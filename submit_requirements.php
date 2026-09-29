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