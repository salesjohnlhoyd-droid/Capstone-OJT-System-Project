<?php
session_start();
include "db.php";

/* ================= SECURITY ================= */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != "student") {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

/* ================= GET COMPANY ================= */
$stmt = $conn->prepare("
    SELECT company_id
    FROM ojt_assignments
    WHERE student_id=?
    LIMIT 1
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();

if (!$res) {
    die("You are not assigned to any company.");
}

$company_id = $res['company_id'];

/* ================= WEEK ================= */
$week_start = date("Y-m-d", strtotime("monday this week"));

/* ================= CHECK EXISTING ================= */
$check = $conn->prepare("
    SELECT *
    FROM reports
    WHERE user_id=? AND company_id=? AND week_start=?
    LIMIT 1
");
$check->bind_param("iis", $user_id, $company_id, $week_start);
$check->execute();
$existing = $check->get_result()->fetch_assoc();

/* ================= VALIDATION ================= */
if ($existing) {
    if ($existing['remark'] != "Wrong Document") {
        die("❌ You already submitted this week.");
    }
}

/* ================= FILE CHECK ================= */
if (!isset($_FILES['report'])) {
    die("No file uploaded.");
}

$file = $_FILES['report'];

if ($file['error'] !== 0) {
    die("Upload error.");
}

/* ================= VALIDATE PDF ================= */
$mime = mime_content_type($file['tmp_name']);
if ($mime !== 'application/pdf') {
    die("❌ Only PDF files are allowed.");
}

/* ================= FILE SIZE LIMIT (OPTIONAL BUT IMPORTANT) ================= */
// 5MB limit
if ($file['size'] > 5 * 1024 * 1024) {
    die("❌ File too large. Max 5MB.");
}

/* ================= SAVE FILE ================= */
$folder = "uploads/reports/";

if (!file_exists($folder)) {
    mkdir($folder, 0777, true);
}

/* UNIQUE FILE NAME */
$filename = $folder . "report_" . $user_id . "_" . time() . ".pdf";

if (!move_uploaded_file($file['tmp_name'], $filename)) {
    die("Failed to save file.");
}

/* ================= DATABASE ================= */
if ($existing) {

    /* 🔥 DELETE OLD FILE ONLY HERE (CORRECT PLACE) */
    if (!empty($existing['report_file']) && file_exists($existing['report_file'])) {
        unlink($existing['report_file']);
    }

    /* RESET EVERYTHING CLEAN */
    $stmt = $conn->prepare("
        UPDATE reports
        SET 
            report_file=?,
            submitted_at=NOW(),
            remark=NULL,
            feedback=NULL,
            company_grade=NULL,
            faculty_grade=NULL,
            status='Resubmitted'
        WHERE id=?
    ");
    $stmt->bind_param("si", $filename, $existing['id']);

} else {

    $stmt = $conn->prepare("
        INSERT INTO reports
        (user_id, company_id, report_file, week_start, submitted_at, status)
        VALUES (?, ?, ?, ?, NOW(), 'Submitted')
    ");
    $stmt->bind_param("iiss", $user_id, $company_id, $filename, $week_start);
}

/* ================= EXECUTE ================= */
if (!$stmt->execute()) {
    die("Database error: " . $stmt->error);
}

/* ================= REDIRECT ================= */
header("Location: student_report.php");
exit;
?>