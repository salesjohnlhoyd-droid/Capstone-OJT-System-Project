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
   ADJUSTMENT: MULTIPLE PICTURES PER REQUIREMENT + ALL PICTURE FORMATS
   ----------------------------------------------------------
   A requirement's file input (name="<requirement>[]" multiple) may now
   carry several pictures of ANY image format the server can decode
   (JPG, PNG, GIF, WEBP, BMP, ...). The requirements table still keeps ONE
   file per requirement, so:
     - one JPEG picture         -> stored untouched (exactly as before)
     - one picture, other format -> converted to JPEG
     - several pictures          -> stacked top-to-bottom, in the order they
                                    were selected, into ONE JPEG
   A PDF is still accepted when it is the only file selected (legacy).
   Everything downstream (administrator review, previews, polling) keeps
   reading a single image/JPEG (or PDF) blob, so nothing else changes.
========================================================== */
function reqUploadedTmpFiles($fieldName) {
    $out = [];
    if (!isset($_FILES[$fieldName])) return $out;
    $f = $_FILES[$fieldName];
    if (is_array($f['error'])) {
        foreach (array_values($f['error']) as $i => $err) {
            if ($err == UPLOAD_ERR_NO_FILE) continue;
            if ($err == UPLOAD_ERR_INI_SIZE || $err == UPLOAD_ERR_FORM_SIZE) die("File too large. Maximum 5MB allowed per file.");
            if ($err != UPLOAD_ERR_OK) die("There was a problem uploading one of your files. Please try again.");
            $out[] = ['tmp' => array_values($f['tmp_name'])[$i], 'size' => array_values($f['size'])[$i]];
        }
    } else {
        if ($f['error'] == UPLOAD_ERR_NO_FILE) return $out;
        if ($f['error'] == UPLOAD_ERR_INI_SIZE || $f['error'] == UPLOAD_ERR_FORM_SIZE) die("File too large. Maximum 5MB allowed per file.");
        if ($f['error'] != UPLOAD_ERR_OK) die("There was a problem uploading your file. Please try again.");
        $out[] = ['tmp' => $f['tmp_name'], 'size' => $f['size']];
    }
    return $out;
}

function reqLoadImageResource($tmp) {
    $raw = @file_get_contents($tmp);
    $img = $raw !== false ? @imagecreatefromstring($raw) : false;
    if (!$img) return false;
    /* honour the camera orientation stored in JPEG EXIF data */
    if (function_exists('exif_read_data') && function_exists('imagerotate')) {
        $exif = @exif_read_data($tmp);
        $o = $exif['Orientation'] ?? 1;
        $angle = ($o == 3) ? 180 : (($o == 6) ? -90 : (($o == 8) ? 90 : 0));
        if ($angle) { $rot = @imagerotate($img, $angle, 0); if ($rot) { imagedestroy($img); $img = $rot; } }
    }
    return $img;
}

function reqJpegBytes($canvas, $quality) {
    ob_start();
    imagejpeg($canvas, null, $quality);
    return ob_get_clean();
}

/* ADJUSTMENT: every selected picture is now ALSO kept as its own record (table requirement_files),
   like CompanyForm.php keeps one row per file. requirements.file_name still holds ONE blob per
   requirement (all pictures stitched together, or the single PDF) so the administrator's pages and
   the application snapshots keep working; AccomForm.php shows the individual pictures as a stack. */
function reqEnsureFilesTable($conn) {
    static $done = false;
    if ($done) return;
    $conn->query("CREATE TABLE IF NOT EXISTS requirement_files (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        requirement_type VARCHAR(64) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        file_name LONGBLOB NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_user_type (user_id, requirement_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/* Replaces the individually stored pictures of one requirement (an empty $parts list just clears them). */
function reqSaveParts($conn, $user_id, $requirementType, array $parts, $safeLimit) {
    reqEnsureFilesTable($conn);
    $del = $conn->prepare("DELETE FROM requirement_files WHERE user_id=? AND requirement_type=?");
    $del->bind_param("is", $user_id, $requirementType);
    $del->execute();
    $del->close();
    if (count($parts) < 2) return; // a single picture / PDF lives in requirements.file_name alone

    $ins = $conn->prepare("INSERT INTO requirement_files (user_id, requirement_type, sort_order, file_name) VALUES (?, ?, ?, ?)");
    foreach (array_values($parts) as $i => $bytes) {
        if (strlen($bytes) > $safeLimit) {
            die("Upload failed: one of the pictures is too large for the server's current database settings (max_allowed_packet). Please upload smaller pictures.");
        }
        $ins->bind_param("isis", $user_id, $requirementType, $i, $bytes);
        $ins->execute();
    }
    $ins->close();
}

/* Returns ['data' => the blob for requirements.file_name, 'parts' => [individual JPEG blobs]], or null when nothing was selected. */
function reqBuildBlobFromUploads($fieldName) {
    global $maxFileSize;
    $files = reqUploadedTmpFiles($fieldName);
    if (empty($files)) return null;

    foreach ($files as $f) {
        if ($f['size'] > $maxFileSize) die("File too large. Maximum 5MB allowed per file.");
    }

    /* ADJUSTMENT: PDF limit (same rule as company_register.php) — only ONE PDF per requirement,
       and a PDF cannot be mixed with pictures in the same selection. */
    $pdfCount = 0; $otherCount = 0;
    foreach ($files as $f) {
        if (mime_content_type($f['tmp']) === 'application/pdf') $pdfCount++; else $otherCount++;
    }
    if ($pdfCount > 1) {
        die("Only one PDF file can be selected for this document. Please choose a single PDF file, or switch to picture files if you need to upload multiple files.");
    }
    if ($pdfCount === 1 && $otherCount > 0) {
        die("Please select files of the same format only — either all pictures or a single PDF, not a mix of both, for this document.");
    }

    /* a lone PDF / JPEG is kept as-is */
    if (count($files) === 1) {
        $m = mime_content_type($files[0]['tmp']);
        if ($m === 'application/pdf') return ['data' => file_get_contents($files[0]['tmp']), 'parts' => []];
        if ($m === 'image/jpeg')      return ['data' => file_get_contents($files[0]['tmp']), 'parts' => []];
    }

    $images = [];
    foreach ($files as $f) {
        $m = mime_content_type($f['tmp']);
        if (strpos((string)$m, 'image/') !== 0) {
            die("Invalid file type. Only picture files (JPG, PNG, GIF, WEBP, BMP, ...) are allowed" . (count($files) === 1 ? " (or a single PDF)." : "."));
        }
        $img = reqLoadImageResource($f['tmp']);
        if (!$img) die("One of your pictures is in a format this server cannot read. Please convert it to JPG or PNG and try again.");
        $images[] = $img;
    }

    /* each picture as its own JPEG record (capped to a sensible size) */
    $parts = [];
    if (count($images) > 1) {
        foreach ($images as $im) {
            $w = imagesx($im); $h = imagesy($im);
            if ($w > 2400) {
                $nh = max(1, (int)round($h * (2400 / $w)));
                $c = imagecreatetruecolor(2400, $nh);
                imagefill($c, 0, 0, imagecolorallocate($c, 255, 255, 255));
                imagecopyresampled($c, $im, 0, 0, 0, 0, 2400, $nh, $w, $h);
                $parts[] = reqJpegBytes($c, 88);
                imagedestroy($c);
            } else {
                $c = imagecreatetruecolor($w, $h);
                imagefill($c, 0, 0, imagecolorallocate($c, 255, 255, 255));
                imagecopy($c, $im, 0, 0, 0, 0, $w, $h);
                $parts[] = reqJpegBytes($c, 88);
                imagedestroy($c);
            }
        }
    }

    /* common width = widest picture, capped so the stitched result stays a sensible size */
    $width = 0;
    foreach ($images as $im) $width = max($width, imagesx($im));
    $width = min($width, count($images) > 1 ? 1400 : 2400);
    $gap = 6;
    $height = 0; $heights = [];
    foreach ($images as $i => $im) {
        $h = (int)round(imagesy($im) * ($width / imagesx($im)));
        $heights[$i] = max(1, $h);
        $height += $heights[$i] + ($i > 0 ? $gap : 0);
    }
    $canvas = imagecreatetruecolor($width, $height);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
    $y = 0;
    foreach ($images as $i => $im) {
        imagecopyresampled($canvas, $im, 0, $y, 0, 0, $width, $heights[$i], imagesx($im), imagesy($im));
        imagedestroy($im);
        $y += $heights[$i] + $gap;
    }
    $bytes = reqJpegBytes($canvas, 88);
    imagedestroy($canvas);
    return ['data' => $bytes, 'parts' => $parts];
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

    if ($table === "requirements") {
        /* ADJUSTMENT: requirement uploads can be several pictures of any format — see reqBuildBlobFromUploads() above */
        $built = reqBuildBlobFromUploads($fieldName);
        if ($built === null) {
            return;
        }
        $data     = $built['data'];
        $reqParts = $built['parts'];
    } else {
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
    }

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

            /* ADJUSTMENT: keep every selected picture as its own record too (see reqSaveParts) */
            reqSaveParts($conn, $user_id, $requirementType, $reqParts, $safeLimit);
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

    foreach($requirements as $req){
        saveBlob($conn, $user_id, $req, "requirements", "file_name", $req);
    }

    header("Location: AccomForm.php?msg=all_submitted");
    exit;
}
?>