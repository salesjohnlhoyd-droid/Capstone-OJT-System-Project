<?php
session_start();
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require 'PHPMailer/src/Exception.php';

include "db.php";
include "activity_helper.php";

// ===============================
// ACTIVITY LOG FUNCTION
// ===============================
function logActivity($conn, $user_id, $action, $details) {
    $stmt = $conn->prepare("
        INSERT INTO activity_logs (user_id, action_type, details) 
        VALUES (?, ?, ?)
    ");
    $stmt->bind_param("iss", $user_id, $action, $details);
    $stmt->execute();
    $stmt->close();
}


$type = isset($_GET['type']) ? $_GET['type'] : null;
$pageTitle = $type ? "Create " . ucfirst($type) . " Account" : "Select Account Type";

$error = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $first      = trim($_POST['first_name']);
    $middle     = trim($_POST['middle_name']);
    $last       = trim($_POST['last_name']);
    $email      = trim($_POST['email']);
    $role       = strtolower(trim($_POST['role']));
    $department = isset($_POST['department']) ? trim($_POST['department']) : NULL;
    $company_t  = isset($_POST['company_type']) ? trim($_POST['company_type']) : NULL;

    if (empty($first) || empty($last) || empty($email)) {
        $error = "Please fill in all required fields.";
    } else {

        // Generate 6-digit password
        $password = rand(100000, 999999);
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        // ============================================
        // INSERT BASED ON ROLE (NO USERS INSERT)
        // ============================================

        if ($role === "admin") {

            $check = $conn->prepare("SELECT id FROM admins WHERE email = ?");
            $check->bind_param("s", $email);
            $check->execute();
            $check->store_result();

            if ($check->num_rows > 0) {
                $error = "Admin email already exists.";
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO admins 
                    (first_name, middle_name, last_name, email, password) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->bind_param("sssss", $first, $middle, $last, $email, $hashedPassword);

                if ($stmt->execute()) {

                    $success = "Admin account created!";
                } else {
                    $error = "Database Error: " . $conn->error;
                }

                $stmt->close();
            }

            $check->close();
        }

        elseif ($role === "faculty") {

            $check = $conn->prepare("SELECT id FROM faculty WHERE email = ?");
            $check->bind_param("s", $email);
            $check->execute();
            $check->store_result();

            if ($check->num_rows > 0) {
                $error = "Faculty email already exists.";
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO faculty 
                    (first_name, middle_name, last_name, email, password, department) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param("ssssss", $first, $middle, $last, $email, $hashedPassword, $department);

                if ($stmt->execute()) {
                    $success = "Faculty account created!";
                } else {
                    $error = "Database Error: " . $conn->error;
                }

                $stmt->close();
            }

            $check->close();
        }

        elseif ($role === "company") {

            $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $check->bind_param("s", $email);
            $check->execute();
            $check->store_result();

            if ($check->num_rows > 0) {
                $error = "Company email already exists.";
            } else {
                // Ensure company_type is valid
                $company_t = isset($_POST['company_type']) && in_array(strtolower($_POST['company_type']), ['private','public'])
                            ? strtolower($_POST['company_type'])
                            : 'public'; // default to public if not set

                $stmt = $conn->prepare("
                    INSERT INTO users 
                    (first_name, middle_name, last_name, email, password, role, company_type) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param("sssssss", $first, $middle, $last, $email, $hashedPassword, $role, $company_t);

                if ($stmt->execute()) {
                    $success = "Company account created!";
                } else {
                    $error = "Database Error: " . $conn->error;
                }

                $stmt->close();
            }

            $check->close();
        }


        // ============================================
        // SEND EMAIL IF SUCCESS
        // ============================================

        if (empty($error)) {

            $mail = new PHPMailer(true);

            try {
                $mail->isSMTP();
                $mail->Host = 'smtp.gmail.com';
                $mail->SMTPAuth = true;
                $mail->Username = 'salesjohnlhoyd@gmail.com';
                $mail->Password = 'qwufanprpmezotly';
                $mail->SMTPSecure = 'tls';
                $mail->Port = 587;

                $mail->setFrom('salesjohnlhoyd@gmail.com', 'NEUST OJT System');
                $mail->addAddress($email);
                $mail->isHTML(true);
                $mail->Subject = "Your New $role Account Credentials";
                $mail->Body = "
                    <h3>Hello $first,</h3>
                    <p>Your account has been created successfully as <b>" . ucfirst($role) . "</b>.</p>
                    <p>Your temporary login password is:</p>
                    <h2 style='color:#0038a8;'>$password</h2>
                    <p>Please log in and change your password immediately.</p>
                ";

                $mail->send();
                $success .= " 6-digit password sent to $email.";

            } catch (Exception $e) {
                $success .= " Email failed: " . $mail->ErrorInfo;
            }
        }
    }
}
?>



<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo $pageTitle; ?></title>
    <link rel="stylesheet" href="administrator.css">
    <style>
        body { background-color: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .wrapper { display: flex; justify-content: center; align-items: center; min-height: 90vh; flex-direction: column; }
        
        /* PHASE 1: SELECTION CARDS */
        .selection-container { display: flex; gap: 20px; margin-top: 20px; }
        .type-card {
            background: white; border: 2px solid #0038a8; border-radius: 15px;
            width: 200px; padding: 40px 10px; text-align: center;
            text-decoration: none; color: #0038a8; transition: 0.3s;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        .type-card:hover { background: #0038a8; color: #ffcc00; transform: translateY(-10px); }
        .type-card .icon { font-size: 50px; margin-bottom: 15px; display: block; }
        .type-card h2 { margin: 0; font-size: 1.2rem; }

        /* PHASE 2: FORM CARD */
        .form-card {
            background: white; padding: 35px; border-radius: 15px;
            width: 100%; max-width: 450px; box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            border-top: 10px solid #0038a8;
        }
        .form-card h2 { color: #0038a8; text-align: center; margin-bottom: 25px; }
        .group { margin-bottom: 15px; }
        .group label { display: block; font-weight: bold; margin-bottom: 5px; font-size: 0.9rem; color: #333; }
        .group input, .group select {
            width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 8px; box-sizing: border-box;
        }
        .btn-create {
            width: 100%; padding: 15px; background: #0038a8; color: white;
            border: none; border-radius: 8px; font-weight: bold; cursor: pointer;
            font-size: 1rem; margin-top: 10px; transition: 0.3s;
        }
        .btn-create:hover { background: #ffcc00; color: #0038a8; }
        .back-link { margin-top: 20px; color: #777; text-decoration: none; font-size: 0.9rem; }
    </style>
</head>
<body>

<div class="wrapper">

    <?php if (!$type): ?>
        <h1 style="color: #0038a8;">Choose Account Type</h1>
        <div class="selection-container">
            <a href="?type=admin" class="type-card">
                <span class="icon">👤</span>
                <h2>ADMIN</h2>
            </a>
            <a href="?type=faculty" class="type-card">
                <span class="icon">👨‍🏫</span>
                <h2>FACULTY</h2>
            </a>
            <a href="?type=company" class="type-card">
                <span class="icon">🏢</span>
                <h2>COMPANY</h2>
            </a>
        </div>
        <a href="administrator.php" class="back-link">← Back to Dashboard</a>

    <?php else: ?>
        <div class="form-card">
            <h2>Create <?= ucfirst($type) ?></h2>
            
            <?php if ($error) echo "<p style='color:red; text-align:center;'>$error</p>"; ?>
            <?php if ($success) echo "<p style='color:green; text-align:center;'>$success</p>"; ?>

            <form method="POST">
                <input type="hidden" name="role" value="<?= $type ?>">

                <div class="group">
                    <label>First Name</label>
                    <input type="text" name="first_name" required placeholder="Enter first name">
                </div>

                <div class="group">
                    <label>Middle Name (Optional)</label>
                    <input type="text" name="middle_name" placeholder="Enter middle name">
                </div>

                <div class="group">
                    <label>Last Name</label>
                    <input type="text" name="last_name" required placeholder="Enter last name">
                </div>

                <div class="group">
                    <label>Email Address</label>
                    <input type="email" name="email" required placeholder="example@neust.edu.ph">
                </div>

                <?php if ($type == 'faculty'): ?>
                <div class="group">
                    <label>Department</label>
                    <select name="department" required>
                        <option value="">-- Select Department --</option>
                        <option value="BS Information Technology">BS Information Technology</option>
                        <option value="BS Business Administration">BS Business Administration</option>
                        <option value="BS Entrepreneurship">BS Entrepreneurship</option>
                    </select>
                </div>
                <?php endif; ?>

                <?php if ($type == 'company'): ?>
                <div class="group">
                    <label>Company Type</label>
                    <select name="company_type" required>
                        <option value="">-- Select Type --</option>
                        <option value="private">Private</option>
                        <option value="public">Public / Government</option>
                    </select>
                </div>
                <?php endif; ?>

                <button type="submit" class="btn-create">CREATE ACCOUNT</button>
                <div style="text-align:center; margin-top: 15px;">
                    <a href="create_admin.php" style="color:#0038a8; text-decoration:none; font-size:0.8rem;">Change Account Type</a>
                </div>
            </form>
        </div>
    <?php endif; ?>

</div>

</body>
</html>