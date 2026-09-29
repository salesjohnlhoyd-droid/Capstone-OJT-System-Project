<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require 'PHPMailer/src/Exception.php';

include "db.php";

// ── Popup state variables ─────────────────────────────────────────────────────
$popup_type     = ''; // 'error' | 'success' | 'warning'
$popup_title    = '';
$popup_msg      = '';
$popup_redirect = ''; // URL to redirect to after OK click (empty = stay)

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $first  = trim($_POST['first_name']);
    $middle = trim($_POST['middle_name']);
    $last   = trim($_POST['last_name']);
    $email  = trim($_POST['email']);
    $course = trim($_POST['course']);

    if (empty($first) || empty($last) || empty($email) || empty($course)) {
        $popup_type  = 'error';
        $popup_title = 'Missing Fields';
        $popup_msg   = 'Please fill in all required fields before submitting.';
    } else {

        // ══════════════════════════════════════════════════════════════════════
        // STEP 1 – Check if registration is currently open
        // ══════════════════════════════════════════════════════════════════════
        $reg_status_result = $conn->query(
            "SELECT setting_value FROM registration_settings WHERE setting_key = 'registration_open' LIMIT 1"
        );

        $registration_is_open = true;
        if ($reg_status_result && $reg_status_result->num_rows > 0) {
            $registration_is_open = ($reg_status_result->fetch_assoc()['setting_value'] === '1');
        }

        if (!$registration_is_open) {
            $popup_type  = 'error';
            $popup_title = 'Registration Temporarily Closed';
            $popup_msg   = 'Student registration is currently unavailable. Please check back later or contact the administrator for assistance.';
        } else {

            // ══════════════════════════════════════════════════════════════════
            // STEP 2 – Look up the student in the students_import table
            // Match by email + first_name + last_name + course
            // ══════════════════════════════════════════════════════════════════
            $student_lookup = $conn->prepare(
                "SELECT * FROM students_import
                 WHERE email = ? AND first_name = ? AND last_name = ? AND course = ?
                 LIMIT 1"
            );
            $student_lookup->bind_param("ssss", $email, $first, $last, $course);
            $student_lookup->execute();
            $student_lookup->store_result();

            if ($student_lookup->num_rows === 0) {

                // ── Check if name + course match WITHOUT email (wrong email case) ──
                $name_check = $conn->prepare(
                    "SELECT id FROM students_import WHERE first_name = ? AND last_name = ? AND course = ? LIMIT 1"
                );
                $name_check->bind_param("sss", $first, $last, $course);
                $name_check->execute();
                $name_check->store_result();

                // ── Check if the email alone exists in the list ──
                $email_check = $conn->prepare(
                    "SELECT id FROM students_import WHERE email = ? LIMIT 1"
                );
                $email_check->bind_param("s", $email);
                $email_check->execute();
                $email_check->store_result();

                if ($email_check->num_rows > 0) {
                    // Email found but other credentials didn't match
                    $popup_type  = 'error';
                    $popup_title = 'Credential Mismatch';
                    $popup_msg   = 'One or more of your credentials (First Name, Last Name, or Course) do not match our records. Please double-check your details and try again.';
                } elseif ($name_check->num_rows > 0) {
                    // Name + course matched but email is wrong
                    $popup_type  = 'error';
                    $popup_title = 'Credential Mismatch';
                    $popup_msg   = 'One or more of your credentials (Email or Course) do not match our records. Please double-check your details and try again.';
                } else {
                    // Nothing matched — student not in the list
                    $popup_type  = 'warning';
                    $popup_title = 'Student Not Found';
                    $popup_msg   = 'Your details could not be found in the student list. Please contact your administrator to have your information added before registering.';
                }

                $email_check->close();
                $name_check->close();

            } else {
                // Student credentials matched — fetch the record
                $student_lookup->bind_result(
                    $sl_id, $sl_first, $sl_middle, $sl_last,
                    $sl_course, $sl_email, $sl_campus, $sl_created_at
                );
                $student_lookup->fetch();

                // ══════════════════════════════════════════════════════════════
                // STEP 2.5 – Validate middle name against the record
                // ══════════════════════════════════════════════════════════════
                $submitted_middle = $middle;
                $record_middle    = trim($sl_middle ?? '');

                $middle_mismatch = false;

                if (!empty($record_middle) && strtolower($submitted_middle) !== strtolower($record_middle)) {
                    // Record has a middle name but submitted value is wrong or blank
                    $middle_mismatch = true;
                } elseif (empty($record_middle) && !empty($submitted_middle)) {
                    // Record has no middle name but student submitted one
                    $middle_mismatch = true;
                }

                if ($middle_mismatch) {
                    $popup_type  = 'error';
                    $popup_title = 'Credential Mismatch';
                    $popup_msg   = 'The Middle Name you entered does not match our records. Please double-check and try again.';
                } else {

                    // ══════════════════════════════════════════════════════════
                    // STEP 3 – Check if the student's campus is allowed
                    // ══════════════════════════════════════════════════════════
                    $allowed_campuses_result = $conn->query(
                        "SELECT setting_value FROM registration_settings WHERE setting_key = 'allowed_campuses' LIMIT 1"
                    );

                    $allowed_campuses = [];
                    if ($allowed_campuses_result && $allowed_campuses_result->num_rows > 0) {
                        $raw_json = $allowed_campuses_result->fetch_assoc()['setting_value'];
                        $decoded  = json_decode($raw_json, true);
                        if (is_array($decoded)) {
                            $allowed_campuses = $decoded;
                        }
                    }

                    // FIXED LOGIC:
                    // If allowed_campuses is NOT empty, the student's campus MUST be in the allowed list
                    // If allowed_campuses IS empty, then NO campuses are allowed (registration blocked for all)
                    $campus_allowed = false;

                    if (!empty($allowed_campuses)) {
                        // Only allow if the student's campus is in the allowed list
                        $campus_allowed = in_array($sl_campus, $allowed_campuses);
                    }
                    // If allowed_campuses is empty, $campus_allowed remains false (no campuses allowed)

                    if (!$campus_allowed) {
                        if (empty($allowed_campuses)) {
                            $popup_msg = 'Student registration is currently restricted. No campuses are authorized to register at this time. Please contact the administrator for more information.';
                        } else {
                            $popup_msg = 'Students from "' . htmlspecialchars($sl_campus) . '" campus are not currently permitted to register. Only students from the following campuses can register: ' . htmlspecialchars(implode(', ', $allowed_campuses)) . '. Please contact the administrator for more information.';
                        }
                        $popup_type  = 'error';
                        $popup_title = 'Campus Not Allowed';
                    } else {

                        // ══════════════════════════════════════════════════════
                        // STEP 4 – Check duplicate email in the users table
                        // ══════════════════════════════════════════════════════
                        $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
                        $check->bind_param("s", $email);
                        $check->execute();
                        $check->store_result();

                        if ($check->num_rows > 0) {
                            $popup_type  = 'error';
                            $popup_title = 'Email Already Registered';
                            $popup_msg   = 'This email address is already associated with an account. If this is not you, please contact the administrator.';
                        } else {
                            // ══════════════════════════════════════════════════
                            // STEP 5 – All checks passed → create the account
                            // ══════════════════════════════════════════════════
                            $password       = rand(100000, 999999);
                            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                            $role           = "student";
                            $deploy_status             = "Waiting";
                            $company_validation_status = null;

                            $stmt = $conn->prepare(
                                "INSERT INTO users
                                 (first_name, middle_name, last_name, role, email, password, course, deploy_status, company_validation_status)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                            );
                            $stmt->bind_param(
                                "sssssssss",
                                $first, $middle, $last, $role,
                                $email, $hashedPassword, $course,
                                $deploy_status, $company_validation_status
                            );

                            if ($stmt->execute()) {

                                if (!file_exists("uploads")) mkdir("uploads", 0777, true);

                                $safeFirst  = preg_replace("/[^a-zA-Z0-9]/", "_", $first);
                                $safeMiddle = preg_replace("/[^a-zA-Z0-9]/", "_", $middle);
                                $safeLast   = preg_replace("/[^a-zA-Z0-9]/", "_", $last);
                                $folderName = !empty($safeMiddle)
                                    ? $safeFirst . "_" . $safeMiddle . "_" . $safeLast
                                    : $safeFirst . "_" . $safeLast;

                                $uploadPath = "uploads/" . $folderName;
                                if (!file_exists($uploadPath)) mkdir($uploadPath, 0777, true);

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
                                        Hello <b>$first</b>,<br><br>
                                        Your login password is:<br>
                                        <h2>$password</h2>
                                        Use this password to login.<br><br>
                                        Course: $course
                                    ";
                                    $mail->send();

                                    $popup_type     = 'success';
                                    $popup_title    = 'Registration Successful!';
                                    $popup_msg      = 'Your account has been created. Check your email for your 6-digit password.';
                                    $popup_redirect = 'login.php';

                                } catch (Exception $e) {
                                    $popup_type     = 'success';
                                    $popup_title    = 'Registration Successful!';
                                    $popup_msg      = 'Your account has been created. However, the confirmation email could not be sent (SMTP error). Please contact the administrator for your password.';
                                    $popup_redirect = 'login.php';
                                }

                            } else {
                                $popup_type  = 'error';
                                $popup_title = 'Registration Failed';
                                $popup_msg   = 'An error occurred while creating your account. Please try again.';
                            }

                            $stmt->close();
                        }

                        $check->close();
                    }
                } // end middle_mismatch check
            }

            $student_lookup->close();
        }

        $conn->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register | NEUST OJT Portal</title>
    <style>
    @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700&display=swap');

    /* ═══════════════════════════════════════════════
       FIX: Full-page background that always renders.
       Gradient base is always visible; photo layers
       on top via pseudo-element so no white flash.
       ═══════════════════════════════════════════════ */
    html, body {
        margin: 0;
        padding: 0;
        min-height: 100%;
    }

    body {
        min-height: 100vh;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        gap: 16px;
        font-family: 'Plus Jakarta Sans', sans-serif;
        /* Guaranteed gradient — always visible */
        background:
            linear-gradient(
                135deg,
                #001f6b 0%,
                #003399 30%,
                #0055cc 55%,
                #1a237e 75%,
                #0d1b5e 100%
            );
        background-attachment: fixed;
        position: relative;
        overflow-x: hidden;
    }

    /* Decorative depth glows */
    body::before {
        content: '';
        position: fixed;
        inset: 0;
        background:
            radial-gradient(ellipse 80% 60% at 20% 10%,  rgba(100,160,255,0.18) 0%, transparent 70%),
            radial-gradient(ellipse 60% 50% at 80% 80%,  rgba(30, 80, 200,0.25) 0%, transparent 70%),
            radial-gradient(ellipse 50% 40% at 50% 50%,  rgba(0, 20, 100, 0.20) 0%, transparent 80%);
        pointer-events: none;
        z-index: 0;
    }

    /* Background photo layered on top of gradient */
    body::after {
        content: '';
        position: fixed;
        inset: 0;
        background: url("photo/OIP.webp") no-repeat center top / cover;
        opacity: 0.55;
        pointer-events: none;
        z-index: 1;
    }

    /* Everything above pseudo-elements */
    body > * {
        position: relative;
        z-index: 2;
    }

    .card {
        background: rgba(255, 255, 255, 0.12);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        padding: 40px;
        width: 380px;
        border-radius: 24px;
        box-shadow:
            0 8px 32px rgba(0, 0, 20, 0.35),
            inset 0 1px 0 rgba(255,255,255,0.25);
        border: 1.5px solid rgba(255, 255, 255, 0.28);
        text-align: center;
    }

    .logo-container img {
        max-width: 100px;
        margin-bottom: 10px;
    }

    h2 {
        color: #ffffff;
        margin-bottom: 25px;
        font-size: 26px;
    }

    .input-group {
        text-align: left;
        margin-bottom: 18px;
    }

    label {
        display: block;
        font-size: 12px;
        font-weight: 700;
        color: rgba(255, 255, 255, 0.75);
        margin-bottom: 6px;
        text-transform: uppercase;
    }

    input[type="text"],
    input[type="email"],
    select {
        width: 100%;
        padding: 14px;
        border-radius: 12px;
        border: 1.5px solid rgba(255, 255, 255, 0.25);
        background: rgba(255, 255, 255, 0.12);
        color: #ffffff;
        box-sizing: border-box;
        font-size: 14px;
        font-family: 'Plus Jakarta Sans', sans-serif;
        appearance: none;
        -webkit-appearance: none;
        outline: none;
        transition: border-color 0.2s, background 0.2s;
    }

    input[type="text"]:focus,
    input[type="email"]:focus,
    select:focus {
        border-color: rgba(255, 255, 255, 0.55);
        background: rgba(255, 255, 255, 0.18);
    }

    input::placeholder {
        color: rgba(255, 255, 255, 0.50);
    }

    select {
        cursor: pointer;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='rgba(255,255,255,0.6)' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 14px center;
        padding-right: 38px;
    }

    select option {
        background: #1e293b;
        color: #ffffff;
    }

    .register-submit {
        width: 100%;
        padding: 16px;
        background: rgba(255, 255, 255, 0.92);
        color: #0038a8;
        border: none;
        border-radius: 12px;
        font-weight: 700;
        font-size: 15px;
        cursor: pointer;
        margin-top: 10px;
        transition: 0.3s;
        font-family: 'Plus Jakarta Sans', sans-serif;
    }

    .register-submit:hover {
        background: #ffffff;
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(0,0,0,0.25);
    }

    .footer-text {
        margin-top: 20px;
        font-size: 14px;
        color: rgba(255, 255, 255, 0.80);
    }

    .footer-text a {
        color: #ffffff;
        text-decoration: none;
        font-weight: 700;
    }

    /* ── POPUP OVERLAY ── */
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
    .rp-title.success { color: #0038a8; }
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
        font-family: 'Plus Jakarta Sans', sans-serif;
    }
    .rp-btn.error   { background: #dc2626; }
    .rp-btn.success { background: #0038a8; }
    .rp-btn.warning { background: #d97706; }
    .rp-btn:hover   { opacity: 0.85; }
    </style>
</head>
<body>

<!-- ── POPUP NOTIFICATION (logic untouched) ── -->
<div id="regPopupOverlay">
    <div id="regPopupBox">
        <span class="rp-icon" id="rpIcon"></span>
        <p class="rp-title" id="rpTitle"></p>
        <p class="rp-msg"   id="rpMsg"></p>
        <button class="rp-btn" id="rpBtn" onclick="rpClose()">OK</button>
    </div>
</div>

<!-- ── HEADER: logo + title (matches login.php) ── -->
<div style="display:flex; align-items:center; gap:18px;">
    <img src="logo.webp" alt="System Logo" style="width:72px; height:72px; border-radius:50%; object-fit:contain; box-shadow:0 4px 16px rgba(0,0,0,0.4);">
    <div style="border-left: 3px solid rgba(255,255,255,0.4); padding-left: 16px;">
        <p style="margin:0 0 2px; font-size:11px; font-weight:600; color:rgba(255,255,255,0.6); text-transform:uppercase; letter-spacing:1.5px;">NEUST Atate Campus</p>
        <p style="margin:0; font-size:18px; font-weight:700; color:#ffffff; line-height:1.3;">Web-Based Smart OJT<br>Monitoring and Supervision<br>Analytics System</p>
    </div>
</div>

<div class="card">
    <h2>Register</h2>

    <form action="register.php" method="POST">

        <div class="input-group">
            <label>First Name</label>
            <input type="text" name="first_name" placeholder="Enter First Name" required
                value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>">
        </div>

        <div class="input-group">
            <label>Middle Name (Optional)</label>
            <input type="text" name="middle_name" placeholder="Enter Middle Name"
                value="<?= htmlspecialchars($_POST['middle_name'] ?? '') ?>">
        </div>

        <div class="input-group">
            <label>Last Name</label>
            <input type="text" name="last_name" placeholder="Enter Last Name" required
                value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>">
        </div>

        <div class="input-group">
            <label>Email</label>
            <input type="email" name="email" placeholder="email@example.com" required
                value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        </div>

        <div class="input-group">
            <label>Course</label>
            <select name="course" required>
                <option value="">-- Select Course --</option>
                <option value="Bachelor of Science in Information Technology"
                    <?= (($_POST['course'] ?? '') === 'Bachelor of Science in Information Technology') ? 'selected' : '' ?>>
                    Bachelor of Science in Information Technology
                </option>
                <option value="Bachelor of Science in Business Administration"
                    <?= (($_POST['course'] ?? '') === 'Bachelor of Science in Business Administration') ? 'selected' : '' ?>>
                    Bachelor of Science in Business Administration
                </option>
                <option value="Bachelor of Science in Business Administration major in Entrepreneurship"
                    <?= (($_POST['course'] ?? '') === 'Bachelor of Science in Business Administration major in Entrepreneurship') ? 'selected' : '' ?>>
                    Bachelor of Science in Business Administration major in Entrepreneurship
                </option>
            </select>
        </div>

        <button type="submit" class="register-submit">Register</button>

        <p class="footer-text">
            Already have an account?
            <a href="login.php">Login</a>
        </p>

    </form>
</div>

<script>
// ── POPUP DATA FROM PHP (logic untouched) ──────────────────────────────────────
const rpType     = <?= json_encode($popup_type) ?>;
const rpTitle    = <?= json_encode($popup_title) ?>;
const rpMsg      = <?= json_encode($popup_msg) ?>;
const rpRedirect = <?= json_encode($popup_redirect) ?>;

function rpClose() {
    document.getElementById('regPopupOverlay').style.display = 'none';
    if (rpRedirect) window.location.href = rpRedirect;
}

document.addEventListener('DOMContentLoaded', function () {
    if (!rpType) return; // no popup needed

    const iconMap = {
        error:   '❌',
        success: '✅',
        warning: '⚠️'
    };

    document.getElementById('rpIcon').textContent  = iconMap[rpType] || '❕';
    document.getElementById('rpTitle').textContent = rpTitle;
    document.getElementById('rpTitle').className   = 'rp-title ' + rpType;
    document.getElementById('rpMsg').textContent   = rpMsg;
    document.getElementById('rpBtn').className     = 'rp-btn ' + rpType;

    document.getElementById('regPopupOverlay').style.display = 'flex';
});
</script>

</body>
</html>