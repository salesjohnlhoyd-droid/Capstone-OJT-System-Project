<?php
session_start();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require 'PHPMailer/src/Exception.php';

include "db.php";

/* ================= GET USER ID ================= */
if(!isset($_SESSION['user_id'])){
    die("User not logged in.");
}

$user_id = $_SESSION['user_id'];

/* ================= COORDINATOR INFO ================= */
$coordinator = $conn->query("
    SELECT id, first_name, last_name, email 
    FROM users 
    WHERE role='coordinator' 
    LIMIT 1
")->fetch_assoc();

$coordinator_name  = $coordinator['first_name']." ".$coordinator['last_name'];
$coordinator_email = $coordinator['email'];

/* ================= SUPERVISOR INFO ================= */
$stmt = $conn->prepare("SELECT first_name, middle_name, last_name FROM users WHERE id=?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($sup_first, $sup_middle, $sup_last);
$stmt->fetch();
$stmt->close();

$supervisor_name = trim("$sup_first $sup_middle $sup_last");

/* ================= SUPERVISOR COMPANY ================= */
$stmt = $conn->prepare("SELECT company FROM company_information WHERE user_id=?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($supervisor_company);
$stmt->fetch();
$stmt->close();

$message_sent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $student_id = intval($_POST['student_id'] ?? 0);
    $category   = htmlspecialchars(trim($_POST['category'] ?? ''), ENT_QUOTES);
    $concern    = htmlspecialchars(trim($_POST['concern'] ?? ''), ENT_QUOTES);

    if($student_id > 0 && !empty($concern) && !empty($category)) {

        /* ================= SAVE MESSAGE ================= */
        $stmt = $conn->prepare("
            INSERT INTO supervisor_messages
            (supervisor_id, coordinator_id, student_id, category, concern, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param("iiiss", $user_id, $coordinator['id'], $student_id, $category, $concern);
        $stmt->execute();
        $stmt->close();

        /* ================= SEND EMAIL ================= */
        $mail = new PHPMailer(true);

        try {

            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'salesjohnlhoyd@gmail.com';
            $mail->Password   = 'qwufanprpmezotly';
            $mail->SMTPSecure = 'tls';
            $mail->Port       = 587;

            $mail->setFrom($coordinator_email, $coordinator_name);
            $mail->addAddress($coordinator['email'], $coordinator['first_name']);

            $mail->Subject = $category . " Concern";

            $mail->Body = "
Supervisor: $supervisor_name
Company: $supervisor_company

Student ID: $student_id
Category: $category

Concern:
$concern
";

            $mail->send();

            $message_sent = true;

        } catch (Exception $e) {
            echo "Mailer Error: {$mail->ErrorInfo}";
        }
    }
}

/* ================= FETCH STUDENTS ================= */
$students = [];
$stmt = $conn->prepare("
    SELECT id, first_name, last_name 
    FROM users 
    WHERE supervisor_id = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$students = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Contact Coordinator</title>
    <link rel="stylesheet" href="CompanyForm.css">
    <style>
        /* Presentable boxes for coordinator, supervisor, and message */
        .info-box {
            background-color: #f9faff;
            border: 1px solid #d0d7e3;
            border-left: 5px solid #0038a8;
            border-radius: 8px;
            padding: 15px 20px;
            margin: 15px 0;
            box-shadow: 0 2px 6px rgba(0,0,0,0.08);
        }

        .info-box h4 {
            margin: 0 0 5px;
            font-size: 14px;
            color: #0038a8;
        }

        .info-box p {
            margin: 2px 0;
            font-size: 14px;
            color: #333;
        }

        form .grid-item {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        textarea {
            min-height: 120px;
            padding: 10px;
            border-radius: 6px;
            border: 1px solid #ddd;
            font-size: 14px;
            resize: vertical;
        }

        button {
            width: 180px;
            background: #0038a8;
            color: white;
            border: none;
            padding: 12px;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s;
        }

        button:hover {
            background: #002080;
        }

        .message-status {
            text-align: center;
            font-weight: 600;
            margin-top: 10px;
            color: green;
        }

        @media (max-width: 768px) {
            button { width: 100%; }
        }

        select {
    padding: 10px;
    border-radius: 6px;
    border: 1px solid #ddd;
    font-size: 14px;
} 
    </style>
</head>
<body>

<div class="admin-header">
    <div class="header-left">
        <img src="logo.webp" alt="Logo"> 
        <div class="logo-text">NEUST<br>OJT VALIDATION SYSTEM</div>
    </div>
    <a href="login.php" class="logout-btn">Logout</a>
</div>

<div class="main-wrapper">
    <div class="card">
        <h2>Contact OJT Coordinator</h2>

        <!-- Coordinator Info Box -->
        <div class="info-box">
            <h4>Coordinator:</h4>
            <p><?= htmlspecialchars($coordinator['first_name'] . " " . $coordinator['last_name']) ?></p>
            <p>Email: <?= htmlspecialchars($coordinator['email']) ?></p>
        </div>

        <!-- Supervisor Info Box -->
        <div class="info-box">
            <h4>Sender:</h4>
            <p><?= htmlspecialchars($supervisor_name) ?></p>
            <p>Company: <?= htmlspecialchars($supervisor_company) ?></p>
        </div>

        <?php if ($message_sent): ?>
            <p class="message-status">Message sent successfully!</p>
        <?php endif; ?>

        <!-- Form -->
        <form method="POST">
            <div class="grid-container">
                <div class="grid-item">
                    <!-- Student -->
<label for="student_id">Student Name:</label>
<select id="student_id" name="student_id" required>
    <option value=""> Select Student Name </option>
    <option value=""> Angelica Ortiz </option>
    <option value="">John Lloyd Sales</option>
    <option value="">Danica Santaygillo</option>
    <option value="">Mark Nhel Nagtalon</option>
    <?php foreach ($students as $student): ?>
        <option value="<?= $student['id'] ?>">
            <?= htmlspecialchars($student['first_name'] . " " . $student['last_name']) ?>
        </option>
    <?php endforeach; ?>
</select>

<!-- Category -->
<label for="category">Concern Type:</label>
<select name="category" id="category" required>
    <option value=""> Select Concern Type </option>
    <option value="Attendance">Attendance</option>
    <option value="Performance">Performance</option>
    <option value="Behavior">Behavior</option>
    <option value="Document Issue">Document Issue</option>
</select>

<!-- Message -->
<label for="concern">Concern / Message:</label>
<textarea id="concern" name="concern" placeholder="Write your concern..." required></textarea>

                    <!-- Button below textarea -->
                    <button type="submit" name="send_message">Send Message</button>
                </div>
            </div>
        </form>
    </div>
</div>

</body>
</html>