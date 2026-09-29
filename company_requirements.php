<?php
session_start();
include "db.php"; // make sure $conn exists


// Max file size and allowed types
$maxFileSize = 5 * 1024 * 1024; // 5MB
$allowedTypes = ['image/jpeg','image/png','application/pdf'];

/* ================= FUNCTION TO SAVE UPLOADED FILE ================= */
function saveCompanyRequirement($conn, $user_id, $fieldName, $requirementType) {
    global $maxFileSize, $allowedTypes;

    if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] != 0) {
        return;
    }

    $tmp = $_FILES[$fieldName]['tmp_name'];

    if (filesize($tmp) > $maxFileSize) {
        die("File too large. Maximum 5MB allowed.");
    }

    $mime = mime_content_type($tmp);
    if (!in_array($mime, $allowedTypes)) {
        die("Invalid file type. Only JPG, PNG, PDF allowed.");
    }

    $data = file_get_contents($tmp);

    // Check if record exists
    $stmt = $conn->prepare("
        SELECT id FROM company_requirements 
        WHERE user_id=? AND requirement_type=?
    ");
    $stmt->bind_param("is", $user_id, $requirementType);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        // Update existing record
        $stmt->close();
        $stmt = $conn->prepare("
            UPDATE company_requirements
            SET file_name=?, status='Pending', remark=NULL
            WHERE user_id=? AND requirement_type=?
        ");
        $stmt->bind_param("sis", $data, $user_id, $requirementType);
    } else {
        // Insert new record
        $stmt->close();
        $stmt = $conn->prepare("
            INSERT INTO company_requirements
            (user_id, requirement_type, file_name, status)
            VALUES (?, ?, ?, 'Pending')
        ");
        $stmt->bind_param("iss", $user_id, $requirementType, $data);
    }

    $stmt->execute();
    $stmt->close();
}

/* ================= HANDLE FORM SUBMISSION ================= */
if (isset($_POST['submit_all'])) {

    // Update company info
    $company = $_POST['company'] ?? '';
    $company_address = $_POST['company_address'] ?? '';
    $telephone = $_POST['telephone'] ?? '';
    $contact_first = $_POST['contact_first_name'] ?? '';
    $contact_middle = $_POST['contact_middle_initial'] ?? '';
    $contact_last = $_POST['contact_last_name'] ?? '';
    $position = $_POST['position'] ?? '';

    $stmt = $conn->prepare("
        UPDATE company_information
        SET company=?, company_address=?, telephone=?,
            contact_first_name=?, contact_middle_initial=?,
            contact_last_name=?, position=?, company_type=?
        WHERE user_id=?
    ");
    $stmt->bind_param(
        "ssssssssi",
        $company, $company_address, $telephone,
        $contact_first, $contact_middle, $contact_last,
        $position, $company_type, $user_id
    );
    $stmt->execute();
    $stmt->close();

    // Get company_type from database instead of POST
$stmt = $conn->prepare("SELECT company_type FROM company_information WHERE user_id=?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($company_type);
$stmt->fetch();
$stmt->close();

    // Define requirements
    $private_reqs = [
        "company_profile",
        "vision_mission",
        "mayors_permit",
        "sec_registration",
        "dti_registration",
        "cda_registration",
        "bir_clearance",
        "ohs_plan",
        "training_supervisor_cv",
        "authority_moa"
    ];

    $public_reqs = [
        "authority_moa_public",
        "training_supervisor_pds",
        "legislative_charter"
    ];

    $reqs = ($company_type === 'public') ? $public_reqs : $private_reqs;

    // Save uploaded files
    foreach ($reqs as $req) {
        saveCompanyRequirement($conn, $user_id, $req, $req);
    }

    // Redirect back to form with success message
    header("Location: CompanyForm.php?msg=submitted");
    exit;
}

?>