<?php
session_start();
include "db.php";

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$maxFileSize = 5 * 1024 * 1024; // 5MB limit
$allowedTypes = [
    'image/jpeg',
    'image/png',
    'application/pdf'
];

/* ==========================================================
   UPDATE COMPANY INFORMATION ONLY IF "Update Company Info" BUTTON IS CLICKED
========================================================== */
if(isset($_POST['update_company'])){
    $stmt = $conn->prepare("SELECT user_id FROM company_information WHERE user_id=?");
    $stmt->bind_param("i",$user_id);
    $stmt->execute();
    $stmt->store_result();

    if($stmt->num_rows > 0){
        $stmt->close();
        $stmt = $conn->prepare("
            UPDATE company_information
            SET company=?, company_address=?, telephone=?,
                contact_first_name=?, contact_middle_initial=?,
                contact_last_name=?, position=?
            WHERE user_id=?
        ");
        $stmt->bind_param("sssssssi",
            $_POST['company'],
            $_POST['company_address'],
            $_POST['telephone'],
            $_POST['contact_first_name'],
            $_POST['contact_middle_initial'],
            $_POST['contact_last_name'],
            $_POST['position'],
            $user_id
        );
    } else {
        $stmt->close();
        $stmt = $conn->prepare("
            INSERT INTO company_information
            (user_id, company, company_address, telephone,
             contact_first_name, contact_middle_initial,
             contact_last_name, position)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("isssssss",
            $user_id,
            $_POST['company'],
            $_POST['company_address'],
            $_POST['telephone'],
            $_POST['contact_first_name'],
            $_POST['contact_middle_initial'],
            $_POST['contact_last_name'],
            $_POST['position']
        );
    }

    $stmt->execute();
    $stmt->close();

    header("Location: AccomForm.php?msg=company_updated");
    exit;
}

/* ==========================================================
   SAVE BLOB FILE FUNCTION
   (logic/queries unchanged — only wrapped with a packet-size
   guard + try/catch so an oversized blob can no longer crash
   the script with an uncaught mysqli_sql_exception and the
   follow-on "Error occurred while closing statement" warning)
========================================================== */
function saveBlob($conn, $user_id, $fieldName, $table, $columnName, $requirementType = null) {
    global $maxFileSize, $allowedTypes;

    if(!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] != 0){
        return;
    }

    $tmp = $_FILES[$fieldName]['tmp_name'];

    if(filesize($tmp) > $maxFileSize){
        die("File too large. Maximum 5MB allowed.");
    }

    $mime = mime_content_type($tmp);
    if(!in_array($mime, $allowedTypes)){
        die("Invalid file type. Only JPG, PNG, PDF allowed.");
    }

    $data = file_get_contents($tmp);

    /* ── Guard against exceeding MySQL's max_allowed_packet ──────────────
       Sending a blob larger than the server's configured max_allowed_packet
       kills the mysqli connection with an uncaught mysqli_sql_exception.
       That crash then breaks every statement after it (hence the
       "Error occurred while closing statement" warning further down the
       requirements loop). We read the server's actual live value once and
       refuse gracefully instead of crashing.
       NOTE: the real long-term fix is raising max_allowed_packet in MySQL's
       my.ini (under [mysqld], e.g. max_allowed_packet=16M) and restarting
       MySQL — this guard just stops the fatal crash if a file still ends
       up too big for whatever the current server setting is. ── */
    static $maxAllowedPacket = null;
    if ($maxAllowedPacket === null) {
        $maxAllowedPacket = 1048576; // 1MB conservative fallback if the query below fails
        if ($res = $conn->query("SELECT @@max_allowed_packet AS map")) {
            $row = $res->fetch_assoc();
            if ($row && isset($row['map'])) {
                $maxAllowedPacket = (int)$row['map'];
            }
            $res->free();
        }
    }
    // Leave headroom for query text / protocol overhead surrounding the blob
    $safeLimit = (int)($maxAllowedPacket * 0.9);
    if (strlen($data) > $safeLimit) {
        die("Upload failed: this file is too large for the server's current database settings (max_allowed_packet). Please ask the administrator to increase max_allowed_packet, or upload a smaller file.");
    }

    try {
        if($table === "student_information"){
            $stmt = $conn->prepare("SELECT user_id FROM student_information WHERE user_id=?");
            $stmt->bind_param("i",$user_id);
            $stmt->execute();
            $stmt->store_result();

            if($stmt->num_rows > 0){
                $stmt->close();
                $stmt = $conn->prepare("
                    UPDATE student_information
                    SET student_photo=?, photo_status='Pending', photo_remark=NULL
                    WHERE user_id=?
                ");
                $null = NULL;
                $stmt->bind_param("bi",$null,$user_id);
                $stmt->send_long_data(0,$data);
            } else {
                $stmt->close();
                $stmt = $conn->prepare("
                    INSERT INTO student_information (user_id, student_photo, photo_status)
                    VALUES (?, ?, 'Pending')
                ");
                $null = NULL;
                $stmt->bind_param("ib",$user_id,$null);
                $stmt->send_long_data(1,$data);
            }
            $stmt->execute();
            $stmt->close();
        }
        else if($table === "requirements"){
            $stmt = $conn->prepare("SELECT id FROM requirements WHERE user_id=? AND requirement_type=?");
            $stmt->bind_param("is",$user_id,$requirementType);
            $stmt->execute();
            $stmt->store_result();

            if($stmt->num_rows > 0){
                $stmt->close();
                $stmt = $conn->prepare("
                    UPDATE requirements
                    SET $columnName=?, status='Pending', remark=NULL
                    WHERE user_id=? AND requirement_type=?
                ");
                $null = NULL;
                $stmt->bind_param("bis",$null,$user_id,$requirementType);
                $stmt->send_long_data(0,$data);
            } else {
                $stmt->close();
                $stmt = $conn->prepare("
                    INSERT INTO requirements (user_id, requirement_type, $columnName, status)
                    VALUES (?, ?, ?, 'Pending')
                ");
                $null = NULL;
                $stmt->bind_param("isb",$user_id,$requirementType,$null);
                $stmt->send_long_data(2,$data);
            }

            $stmt->execute();
            $stmt->close();
        }
    } catch (mysqli_sql_exception $e) {
        // Any execute-time DB error (including a packet-size failure that
        // slipped past the pre-check above) is caught here instead of
        // crashing the whole request and leaving a dead connection behind
        // for the next statement in the requirements loop to choke on.
        if (isset($stmt) && $stmt instanceof mysqli_stmt) {
            @$stmt->close();
        }
        if (stripos($e->getMessage(), 'max_allowed_packet') !== false) {
            die("Upload failed: this file is too large for the server's current database settings (max_allowed_packet). Please ask the administrator to increase max_allowed_packet, or upload a smaller file.");
        }
        die("Upload failed while saving your file. Please try again. (" . htmlspecialchars($e->getMessage()) . ")");
    }
}

/* ==========================================================
   MULTI-FILE REQUIREMENTS — ONE `requirements` ROW PER FILE
   Same technique as CompanyForm.php's
   cfValidateRequirementUploadsMulti() / cfReplaceCompanyRequirementFiles():
   every file selected for a requirement is validated, then saved as its
   OWN row (same user_id + requirement_type, oldest id first) instead of
   being combined into a single image. administrator.php already lists
   every row of a requirement (svFetchFileEntries / stream_student_file).
========================================================== */

/** Any picture format is accepted; non JPG/PNG pictures are converted to PNG so every browser can show them. */
function srNormalizePictureBytes(string $tmpPath, string $mime): ?string
{
    $raw = @file_get_contents($tmpPath);
    if ($raw === false || $raw === '') return null;
    if ($mime === 'image/jpeg' || $mime === 'image/png') return $raw;
    if (!function_exists('imagecreatefromstring')) return null;
    $img = @imagecreatefromstring($raw);
    if (!$img) return null;
    imagealphablending($img, false);
    imagesavealpha($img, true);
    ob_start();
    imagepng($img);
    $png = ob_get_clean();
    imagedestroy($img);
    return ($png === false || $png === '') ? null : $png;
}

/**
 * Makes sure the `requirements` table can hold several rows per
 * (user_id, requirement_type). The table was created with
 * UNIQUE (user_id, requirement_type), which rejects the 2nd file of a
 * requirement, so that unique index is replaced by a plain index (the
 * plain one is added FIRST because the user_id foreign key needs an index).
 * Runs once per request; every step is guarded so a failure here can never
 * stop single-file uploads from working.
 */
function srEnsureMultiFileSchema(mysqli $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $idx = [];
        if ($res = $conn->query("SHOW INDEX FROM requirements")) {
            while ($r = $res->fetch_assoc()) {
                $idx[$r['Key_name']]['unique'] = ((int)$r['Non_unique'] === 0);
                $idx[$r['Key_name']]['cols'][(int)$r['Seq_in_index']] = $r['Column_name'];
            }
            $res->free();
        }
        $legacy = [];
        foreach ($idx as $name => $info) {
            if ($name === 'PRIMARY' || empty($info['unique'])) continue;
            ksort($info['cols']);
            $cols = array_values($info['cols']);
            if ($cols === ['user_id', 'requirement_type'] || $cols === ['requirement_type', 'user_id']) $legacy[] = $name;
        }
        if (!$legacy) return;
        if (!isset($idx['idx_user_requirement_type'])) {
            $conn->query("ALTER TABLE requirements ADD INDEX idx_user_requirement_type (user_id, requirement_type)");
        }
        foreach ($legacy as $name) {
            $conn->query("ALTER TABLE requirements DROP INDEX `" . $conn->real_escape_string($name) . "`");
        }
    } catch (\Throwable $e) {
        error_log('[SUBMIT REQ SCHEMA] could not relax the unique index on requirements: ' . $e->getMessage());
    }
}

/**
 * Validates every file submitted for one requirement field
 * ($_FILES[$fieldName] — name="key[]" multi input; a plain single input is
 * also understood). Returns ['ok'=>bool, 'error'=>string, 'bytes'=>string[]];
 * 'bytes' is empty (ok=true) when nothing was chosen for this field, so the
 * requirement is simply left untouched.
 */
function srValidateRequirementUploads(string $fieldName, string $label, int $maxFileSize, array $allowedTypes, int $safePacket): array
{
    $none = ['ok' => true, 'error' => '', 'bytes' => []];
    if (!isset($_FILES[$fieldName]) || !isset($_FILES[$fieldName]['name'])) return $none;
    $f = $_FILES[$fieldName];
    $namesArr = array_values((array)$f['name']);
    $tmpNames = array_values((array)$f['tmp_name']);
    $errors   = array_values((array)$f['error']);
    $sizes    = array_values((array)$f['size']);

    $submitted = [];
    foreach ($errors as $i => $code) {
        if ((int)$code !== UPLOAD_ERR_NO_FILE) $submitted[] = $i;
    }
    if (!$submitted) return $none;

    $fail = function (string $msg): array { return ['ok' => false, 'error' => $msg, 'bytes' => []]; };

    // Optional safety net: the browser may tell us how many files it selected.
    $expected = $_POST[$fieldName . '_expected_count'] ?? null;
    if ($expected !== null && is_numeric($expected) && (int)$expected > count($submitted)) {
        return $fail('You selected ' . (int)$expected . ' file(s) for "' . $label . '" but only ' . count($submitted)
            . ' reached the server. This usually means a server upload limit (max_file_uploads in php.ini) was exceeded. '
            . 'Please upload fewer files at once for this document, or ask the administrator to raise that limit.');
    }

    // One PDF at most, and never mixed with pictures (same rule as CompanyForm.php).
    $pdfCount = 0; $otherCount = 0;
    foreach ($submitted as $i) {
        if ((int)$errors[$i] !== UPLOAD_ERR_OK) continue;
        if (@mime_content_type($tmpNames[$i]) === 'application/pdf') $pdfCount++; else $otherCount++;
    }
    if ($pdfCount > 1) {
        return $fail('Only one PDF file can be selected for "' . $label . '". Choose a single PDF, or pictures if you need several files for this document.');
    }
    if ($pdfCount === 1 && $otherCount > 0) {
        return $fail('Please select files of the same format only for "' . $label . '" — either all pictures or a single PDF, not a mix of both.');
    }

    $out = [];
    foreach ($submitted as $i) {
        $nm = $namesArr[$i] ?? 'a file';
        if ((int)$errors[$i] !== UPLOAD_ERR_OK) {
            $why = ((int)$errors[$i] === UPLOAD_ERR_INI_SIZE || (int)$errors[$i] === UPLOAD_ERR_FORM_SIZE) ? ' (the file is larger than the server allows)' : '';
            return $fail('There was a problem uploading "' . $label . '" (' . $nm . ')' . $why . '. Please try again.');
        }
        $tmp = $tmpNames[$i];
        if (!is_uploaded_file($tmp)) return $fail('"' . $label . '" (' . $nm . ') was not uploaded correctly. Please try again.');
        if ((int)$sizes[$i] > $maxFileSize) return $fail('"' . $label . '" (' . $nm . ') exceeds the 5MB limit.');

        $mime = @mime_content_type($tmp);
        $isPicture = $mime && strpos($mime, 'image/') === 0;
        if (!$mime || (!$isPicture && !in_array($mime, $allowedTypes, true))) {
            return $fail('"' . $label . '" (' . $nm . ') must be a PDF or a picture file (JPG, PNG, GIF, WEBP, BMP, ...).');
        }
        if ($isPicture) {
            $bytes = srNormalizePictureBytes($tmp, $mime);
            if ($bytes === null) return $fail('"' . $label . '" (' . $nm . ') is in a picture format this server cannot read. Please convert it to JPG or PNG and try again.');
        } else {
            $bytes = @file_get_contents($tmp);
        }
        if ($bytes === false || $bytes === '') return $fail('Failed to read "' . $label . '" (' . $nm . '). Please try again.');
        if (strlen($bytes) > $safePacket) {
            return $fail('"' . $label . '" (' . $nm . ') is too large for the server\'s current database settings (max_allowed_packet). Please ask the administrator to increase it, or upload a smaller file.');
        }
        $out[] = $bytes;
    }
    return ['ok' => true, 'error' => '', 'bytes' => $out];
}

/**
 * Replaces every saved file of one requirement with the new set: deletes the
 * old rows, then inserts each file as its own `requirements` row (status
 * Pending, no remark — a resubmission re-enters the admin's review queue, as
 * before). Runs in a transaction, so a failure part-way leaves the previously
 * saved file(s) untouched instead of a half-saved requirement.
 * Returns '' on success or an error message.
 */
function srReplaceRequirementFiles(mysqli $conn, int $userId, string $type, array $filesBytes): string
{
    if (!$filesBytes) return '';
    try {
        $conn->begin_transaction();

        $del = $conn->prepare("DELETE FROM requirements WHERE user_id=? AND requirement_type=?");
        if (!$del) throw new Exception('prepare(delete) failed: ' . $conn->error);
        $del->bind_param("is", $userId, $type);
        $del->execute();
        $del->close();

        foreach ($filesBytes as $bytes) {
            $ins = $conn->prepare("INSERT INTO requirements (user_id, requirement_type, file_name, status, remark) VALUES (?, ?, ?, 'Pending', NULL)");
            if (!$ins) throw new Exception('prepare(insert) failed: ' . $conn->error);
            // Blob bound directly as "s" (same as CompanyForm.php) so the full file is stored.
            $ins->bind_param("iss", $userId, $type, $bytes);
            $ins->execute();
            $newId = (int)$conn->insert_id;
            $affected = $ins->affected_rows;
            $ins->close();
            if ($affected < 1) throw new Exception('insert reported 0 affected rows');

            $v = $conn->prepare("SELECT LENGTH(file_name) AS len FROM requirements WHERE id=?");
            if ($v) {
                $v->bind_param("i", $newId);
                $v->execute();
                $vr = $v->get_result()->fetch_assoc();
                $v->close();
                if ((int)($vr['len'] ?? 0) !== strlen($bytes)) throw new Exception('stored file length mismatch');
            }
        }
        $conn->commit();
        return '';
    } catch (\Throwable $e) {
        try { $conn->rollback(); } catch (\Throwable $e2) {}
        error_log('[SUBMIT REQ] saving "' . $type . '" failed for user ' . $userId . ': ' . $e->getMessage());
        if (stripos($e->getMessage(), 'max_allowed_packet') !== false) {
            return 'A file for this requirement is too large for the server\'s current database settings (max_allowed_packet). Please ask the administrator to increase it, or upload a smaller file.';
        }
        if (stripos($e->getMessage(), 'Duplicate entry') !== false) {
            return 'Several files for one requirement cannot be saved yet because the database still enforces one file per requirement. Please contact the administrator.';
        }
        return 'Your file(s) could not be saved. Please try again.';
    }
}

/** Plain error page for a rejected requirement submit (nothing is saved when this is shown). */
function srFailSubmit(string $message): void
{
    http_response_code(422);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Upload failed</title></head>'
       . '<body style="font-family:Arial,sans-serif;padding:30px;">'
       . '<h3>Upload failed</h3><p>' . htmlspecialchars($message) . '</p>'
       . '<p><a href="AccomForm.php">&larr; Back to Documentary Requirements</a></p></body></html>';
    exit;
}

/* ==========================================================
   IF "Submit All" BUTTON IS CLICKED
   - Updates company info + saves 2x2 photo + requirements
========================================================== */
if(isset($_POST['submit_all'])){

    // --- Update company info ---
    $stmt = $conn->prepare("SELECT user_id FROM company_information WHERE user_id=?");
    $stmt->bind_param("i",$user_id);
    $stmt->execute();
    $stmt->store_result();

    if($stmt->num_rows > 0){
        $stmt->close();
        $stmt = $conn->prepare("
            UPDATE company_information
            SET company=?, company_address=?, telephone=?,
                contact_first_name=?, contact_middle_initial=?,
                contact_last_name=?, position=?
            WHERE user_id=?
        ");
        $stmt->bind_param("sssssssi",
            $_POST['company'],
            $_POST['company_address'],
            $_POST['telephone'],
            $_POST['contact_first_name'],
            $_POST['contact_middle_initial'],
            $_POST['contact_last_name'],
            $_POST['position'],
            $user_id
        );
    } else {
        $stmt->close();
        $stmt = $conn->prepare("
            INSERT INTO company_information
            (user_id, company, company_address, telephone,
             contact_first_name, contact_middle_initial,
             contact_last_name, position)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("isssssss",
            $user_id,
            $_POST['company'],
            $_POST['company_address'],
            $_POST['telephone'],
            $_POST['contact_first_name'],
            $_POST['contact_middle_initial'],
            $_POST['contact_last_name'],
            $_POST['position']
        );
    }

    $stmt->execute();
    $stmt->close();

    // --- Save 2x2 photo ---
    saveBlob($conn, $user_id, "student_photo", "student_information", "student_photo");

    // --- Save requirements ---
    $requirements = [
        "cert_registration",
        "certificate_pdos",
        "ojt_sheet",
        "application_sit",
        "waiver_form",
        "student_contract",
        "psych_result",
        "medical_result"
    ];

    // Every file chosen for a requirement is saved as its own row in `requirements`
    // (no combining). All requirements are validated BEFORE anything is written, so
    // one bad file never leaves the submit half-saved.
    $reqLabels = [
        "cert_registration" => "Certification of Registration",
        "certificate_pdos"  => "PDOS Certificate",
        "ojt_sheet"         => "OJT Sheet",
        "application_sit"   => "Application SIT",
        "waiver_form"       => "Waiver Form",
        "student_contract"  => "Student Contract",
        "psych_result"      => "Psych Result",
        "medical_result"    => "Medical Result",
    ];

    $safePacket = 1048576;
    if ($res = $conn->query("SELECT @@max_allowed_packet AS map")) {
        $row = $res->fetch_assoc();
        if ($row && isset($row['map'])) $safePacket = (int)($row['map'] * 0.9);
        $res->free();
    }

    $toSave = [];
    foreach($requirements as $req){
        $v = srValidateRequirementUploads($req, $reqLabels[$req] ?? $req, $maxFileSize, $allowedTypes, $safePacket);
        if (!$v['ok']) srFailSubmit($v['error']);
        if (!empty($v['bytes'])) $toSave[$req] = $v['bytes'];
    }

    if ($toSave) {
        srEnsureMultiFileSchema($conn);
        foreach ($toSave as $req => $filesBytes) {
            $err = srReplaceRequirementFiles($conn, (int)$user_id, $req, $filesBytes);
            if ($err !== '') srFailSubmit('"' . ($reqLabels[$req] ?? $req) . '": ' . $err);
        }
    }

    header("Location: AccomForm.php?msg=all_submitted");
    exit;
}
?>