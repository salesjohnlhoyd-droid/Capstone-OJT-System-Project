<?php
session_start();
include "db.php";

/* ================= SECURITY CHECK ================= */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != "company") {
    die("Access denied.");
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    $report_id = $_POST['report_id'];
    $remark = $_POST['remark'] ?? null;
    $feedback = $_POST['feedback'] ?? null;
    $company_grade = $_POST['company_grade'] ?? null;

    /* ================= GET REPORT DATA ================= */
    $get = $conn->prepare("SELECT report_file, submitted_at FROM reports WHERE id=?");
    $get->bind_param("i", $report_id);
    $get->execute();
    $data = $get->get_result()->fetch_assoc();

    if (!$data) {
        die("Report not found.");
    }

    /* ================= LOCK CHECK (1 WEEK) ================= */
    if (strtotime($data['submitted_at'] . ' +7 days') < time()) {
        die("❌ Editing locked (1 week passed)");
    }

    /* ================= VALIDATE GRADE ================= */
    if ($company_grade !== null && $company_grade !== "") {

        if (!is_numeric($company_grade) || $company_grade < 0 || $company_grade > 100) {
            die("❌ Invalid grade (must be 0-100 only)");
        }

    } else {
        $company_grade = null;
    }

    /* ================= IMPORTANT CHANGE ================= */
    // ❌ REMOVE DELETE LOGIC
    // ✔ KEEP FILE EVEN IF WRONG DOCUMENT

    /* ================= SAVE ONLY ================= */
    $stmt = $conn->prepare("
        UPDATE reports 
        SET 
            remark=?,
            feedback=?,
            company_grade=?,
            graded_by=?,
            status='Reviewed'
        WHERE id=?
    ");

    $stmt->bind_param(
        "ssiii",
        $remark,
        $feedback,
        $company_grade,
        $_SESSION['user_id'],
        $report_id
    );

    if (!$stmt->execute()) {
        die("Database error: " . $stmt->error);
    }
}

/* ================= REDIRECT ================= */
header("Location: company_reports.php");
exit;
?>