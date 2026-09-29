<?php
ini_set('log_errors', 1);
ini_set('error_log', 'C:/xampp/tmp/php_errors.log');
session_start();
include "db.php";

require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require 'PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ============================================
// NEW: BRANDED EMAIL TEMPLATE HELPER
// ------------------------------------------------------------
// Ported from login.php so both the student and company login pages
// send emails that look and read the same: a dark navy/gold "NEUST
// OJT Portal" header, a colored status banner underneath it, a white
// content card, and a light-gray "automated message" footer. This is
// purely a presentation helper — it does not touch SMTP config,
// session handling, OTP generation/validation, or any other logic.
// ============================================
function buildBrandedEmailTemplate($bannerColor, $bannerBg, $bannerIcon, $bannerText, $bodyHtml) {
    return "
    <div style=\"font-family:'Segoe UI',Arial,Helvetica,sans-serif;background:#eef1f8;padding:30px 10px;\">
      <div style=\"max-width:560px;margin:0 auto;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 6px 24px rgba(0,0,0,0.08);border:1px solid #e2e8f0;\">

        <!-- Header -->
        <div style=\"background:linear-gradient(135deg,#0a1454 0%,#132a8c 55%,#1a237e 100%);padding:30px 20px;text-align:center;\">
          <p style=\"margin:0 0 6px;color:#FFD700;font-size:22px;font-weight:800;letter-spacing:0.5px;\">NEUST OJT Portal</p>
          <p style=\"margin:0;color:rgba(255,255,255,0.75);font-size:13px;\">Atate Campus &mdash; On the Job Training System</p>
        </div>

        <!-- Status banner -->
        <div style=\"background:{$bannerBg};padding:16px 20px;text-align:center;border-bottom:1px solid rgba(0,0,0,0.06);\">
          <p style=\"margin:0;color:{$bannerColor};font-size:16px;font-weight:700;\">{$bannerIcon} {$bannerText}</p>
        </div>

        <!-- Body -->
        <div style=\"padding:32px 28px;\">
          {$bodyHtml}
        </div>

        <!-- Footer -->
        <div style=\"background:#f1f5f9;padding:16px 20px;text-align:center;\">
          <p style=\"margin:0;color:#94a3b8;font-size:11px;line-height:1.6;\">
            This is an automated message from the NEUST OJT Validation System.<br>Please do not reply to this email.
          </p>
        </div>

      </div>
    </div>
    ";
}

// ============================================
// NEW: OTP EMAIL FUNCTION — ported from login.php
// ------------------------------------------------------------
// Sends the same style of OTP email used by the student login page,
// so company_login.php can now run its own OTP verification step
// (see STEP 2 further below) using identical SMTP settings/template.
// ── UPDATED: Subject/Body now use the shared branded template above
// (friendlier tone, code shown in a highlighted box) so this page's
// OTP emails match login.php's redesigned OTP emails exactly. SMTP
// setup, error handling, and everything about when/how this function
// is called remains completely unchanged. ──
// ============================================
function sendOTPEmail($toEmail, $otp) {
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
        $mail->addAddress($toEmail);

        $mail->isHTML(true);
        $mail->Subject = 'Your One-Time Password (OTP) - NEUST OJT Portal';

        $otpBody = "
            <p style='margin:0 0 14px;color:#1e293b;font-size:15px;'>Hello,</p>
            <p style='margin:0 0 22px;color:#334155;font-size:14px;line-height:1.7;'>
                Someone requested a one-time password to sign in to the NEUST OJT Portal.
                If this was you, use the code below to continue. For your security,
                please don't share this code with anyone.
            </p>

            <div style='background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:22px;text-align:center;margin:0 0 22px;'>
                <p style='margin:0 0 8px;color:#64748b;font-size:11px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;'>Your OTP Code</p>
                <p style='margin:0;color:#0038a8;font-size:32px;font-weight:800;letter-spacing:6px;'>$otp</p>
            </div>

            <p style='margin:0 0 4px;color:#64748b;font-size:13px;line-height:1.6;font-style:italic;'>
                This code will expire in <strong>5 minutes</strong>. If you didn't request this, you can safely ignore this email &mdash; your account is still secure.
            </p>
        ";

        $mail->Body = buildBrandedEmailTemplate(
            '#1e3a8a',
            '#eef2ff',
            '&#128274;',
            'Your One-Time Password',
            $otpBody
        );

        $mail->send();

        unset($_SESSION['smtp_error']);

        return true;

    } catch (Exception $e) {
        $_SESSION['smtp_error'] = $mail->ErrorInfo;
        return false;
    }
}

// ============================================
// NEW: NEW PASSWORD EMAIL FUNCTION — ported from login.php
// ------------------------------------------------------------
// Used by this page's own Forgot Password flow (see FP-steps further
// below) so a company account no longer needs to be sent to
// login.php to reset its password.
// ── UPDATED: Subject/Body now use the same shared branded template
// as sendOTPEmail() above (consistent header/footer, a green
// "success" banner, friendlier tone), matching login.php's
// redesigned password-reset email exactly. The SMTP setup, DB
// update, and everything about when/how this function is called
// remains completely unchanged. ──
// ============================================
function sendNewPasswordEmail($toEmail, $firstName, $newPassword) {
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
        $mail->addAddress($toEmail);
        $mail->isHTML(true);
        $mail->Subject = 'Your Password Has Been Reset - NEUST OJT Portal';

        $passwordBody = "
            <p style='margin:0 0 14px;color:#1e293b;font-size:15px;'>Hello, <strong>" . htmlspecialchars($firstName) . "</strong>!</p>
            <p style='margin:0 0 22px;color:#334155;font-size:14px;line-height:1.7;'>
                Your password was just reset for your NEUST OJT Portal account. Here's your new
                temporary password &mdash; please use it to log in and set a new one of your own as soon as you can.
            </p>

            <div style='background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:22px;text-align:center;margin:0 0 22px;'>
                <p style='margin:0 0 8px;color:#64748b;font-size:11px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;'>Your Temporary Password</p>
                <p style='margin:0;color:#0038a8;font-size:26px;font-weight:800;letter-spacing:3px;'>$newPassword</p>
            </div>

            <p style='margin:0 0 22px;color:#64748b;font-size:13px;line-height:1.6;font-style:italic;'>
                Didn't request this reset? Please contact your school administrator right away so your account can be secured.
            </p>
        ";

        $mail->Body = buildBrandedEmailTemplate(
            '#065f46',
            '#ecfdf5',
            '&#9989;',
            'Password Reset Successful',
            $passwordBody
        );

        $mail->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// ============================================
// NEW: GENERATE RANDOM PASSWORD — ported from login.php
// ============================================
function generateRandomPassword($length = 6) {
    return str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
}

// ============================================
// NEW: REMEMBER ME HELPERS — ported from login.php
// ------------------------------------------------------------
// Identical implementation to the one already used on the student
// login page. Both pages share the same 'remember_token' cookie and
// the same $_SESSION['remember_tokens'] store, so a token created on
// one page is recognized (and role-checked) correctly on the other.
// ============================================
function setRememberMeCookie($userId, $role, $firstName) {
    $token   = bin2hex(random_bytes(32));
    $expires = mktime(23, 59, 59, date('n'), date('j'), date('Y'));
    if (!isset($_SESSION['remember_tokens'])) $_SESSION['remember_tokens'] = [];
    $_SESSION['remember_tokens'][$token] = [
        'user_id'    => $userId,
        'role'       => $role,
        'first_name' => $firstName,
        'expires'    => $expires,
    ];
    setcookie('remember_token', $token, [
        'expires'  => $expires,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function checkRememberMeCookie($forUserId = null) {
    if (empty($_COOKIE['remember_token'])) return false;
    $token = $_COOKIE['remember_token'];
    if (!isset($_SESSION['remember_tokens'][$token])) return false;
    $data = $_SESSION['remember_tokens'][$token];
    if (time() > $data['expires']) {
        unset($_SESSION['remember_tokens'][$token]);
        setcookie('remember_token', '', time() - 3600, '/');
        return false;
    }
    if ($forUserId !== null && (int)$data['user_id'] !== (int)$forUserId) {
        return false;
    }
    return $data;
}

function clearRememberMeCookie() {
    if (!empty($_COOKIE['remember_token'])) {
        $token = $_COOKIE['remember_token'];
        if (isset($_SESSION['remember_tokens'][$token])) {
            unset($_SESSION['remember_tokens'][$token]);
        }
        setcookie('remember_token', '', time() - 3600, '/');
    }
}

// ============================================
// REDIRECT DECISION — kept as a single function because it is called
// from three places (normal OTP-verified login, the Remember-Me login
// shortcut on the POST, and the Remember-Me GET auto-login).
// ------------------------------------------------------------
// ── UPDATED (redirect adjustment) ────────────────────────────────
// moa_request.php has been removed from the system, so every company
// account is now sent to CompanyForm.php after a successful login.
// The old moa_requests lookup (including its ALTER TABLE / SELECT
// against the moa_requests table) has been taken out — it is no longer
// needed and would cause an error if that table no longer exists.
// The function name, parameters and return type are unchanged, so all
// call sites keep working exactly as before.
// ============================================
function determineCompanyRedirect(mysqli $conn, int $user_id): string {
    return 'CompanyForm.php';
}

// ── Popup state variables ─────────────────────────────────────────────────
$popup_type  = ''; // 'error' | 'success' | 'warning' | 'confirm' | 'student_redirect' | 'admin_redirect'
$popup_title = '';
$popup_msg   = '';

// NEW: plain inline notice text used only by the OTP step (step 2),
// same pattern as login.php's $error / $success.
$error   = '';
$success = '';

// ============================================
// NEW: Forgot Password / Recover Email notice text — this page's own
// self-contained versions of login.php's $fp_error / $re_error, used
// by the FP-steps and RE-step further below so this page no longer
// needs to hand those flows off to login.php.
// ============================================
$fp_error = '';
$re_error = '';

// Where to redirect after DB cleanup (set inside the POST block below,
// then acted on once, after $stmt/$conn are safely closed).
$redirect_to = null;

// ============================================
// NEW: STEP TRACKING — 1 = login form, 2 = OTP verification
// ------------------------------------------------------------
// Mirrors login.php's $step pattern, but persisted under its own
// 'cl_step' session key (distinct from login.php's 'fp_step'/'re_step')
// so the two pages' in-progress flows never collide when both are
// used in the same browser session.
// ============================================
$step = 1;
if (isset($_SESSION['cl_step'])) $step = $_SESSION['cl_step'];

// ============================================
// NEW: AUTO-LOGIN via Remember Me cookie (GET page loads only)
// ------------------------------------------------------------
// Mirrors login.php's auto-login block. Only acts when the remembered
// cookie belongs to a 'company' account — a remembered student/admin
// cookie is left completely untouched here so login.php can still
// auto-login that visitor on its own page.
// ============================================
if (
    empty($_SESSION['user_id']) &&
    empty($_SESSION['cl_step']) &&
    $_SERVER['REQUEST_METHOD'] === 'GET'
) {
    $remembered = checkRememberMeCookie();
    if ($remembered && $remembered['role'] === 'company') {
        $auto_stmt = $conn->prepare(
            "SELECT id, first_name, middle_name, last_name, email FROM users WHERE id = ? AND role = 'company' LIMIT 1"
        );
        $auto_stmt->bind_param("i", $remembered['user_id']);
        $auto_stmt->execute();
        $auto_row = $auto_stmt->get_result()->fetch_assoc();
        $auto_stmt->close();

        if ($auto_row) {
            session_regenerate_id(true);
            $_SESSION['user_id']          = $auto_row['id'];
            $_SESSION['user_role']        = 'company';
            $_SESSION['role']             = 'company';
            $_SESSION['user_email']       = $auto_row['email'];
            $_SESSION['user_first_name']  = $auto_row['first_name'];
            $_SESSION['user_middle_name'] = $auto_row['middle_name'];
            $_SESSION['user_last_name']   = $auto_row['last_name'];

            $auto_redirect = determineCompanyRedirect($conn, $auto_row['id']);
            header("Location: " . $auto_redirect);
            exit();
        }
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // ================= STEP 1: LOGIN =================
    if (isset($_POST['login'])) {

        // Starting a fresh login attempt — clear any stale in-progress
        // OTP state from a previous attempt.
        unset($_SESSION['cl_otp_email'], $_SESSION['cl_otp'], $_SESSION['cl_otp_time'],
              $_SESSION['cl_user_id_temp'], $_SESSION['cl_first_name_temp'],
              $_SESSION['cl_middle_name_temp'], $_SESSION['cl_last_name_temp'],
              $_SESSION['cl_email_temp'], $_SESSION['cl_remember_me_pending'],
              $_SESSION['cl_otp_send_count'], $_SESSION['cl_otp_cooldown']);

        $email      = trim($_POST['email'] ?? '');
        $password   = trim($_POST['password'] ?? '');
        $rememberMe = isset($_POST['remember_me']); // NEW

        if (empty($email) || empty($password)) {
            $popup_type  = 'error';
            $popup_title = 'Missing Fields';
            $popup_msg   = 'Please enter both your email and password.';
        } else {

            // NOTE: no longer filters by role in the SQL itself — we need to
            // know the account's role (company vs. student) so we can tell
            // students to use the main login page instead of just reporting
            // "account not found". The role check happens in PHP below.
            $stmt = $conn->prepare(
                "SELECT id, first_name, middle_name, last_name, email, password, role
                 FROM users WHERE email = ? LIMIT 1"
            );
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                // ============================================
                // NEW: ADMIN ACCOUNT DETECTION
                // ------------------------------------------------------------
                // No match was found in `users`, but this page previously
                // concluded "Account Not Found" at this point regardless of
                // whether the email actually belongs to an admin (admin
                // accounts live in a separate `admins` table, which this
                // page never queried). Before showing the generic
                // "not found" popup, check the `admins` table for a
                // matching email + password so an admin can instead be
                // shown a one-click redirect to the dedicated Admin Portal
                // (admin_login.php), mirroring the existing
                // "Student Account Detected" redirect pattern below.
                // If the email isn't an admin account either (or the
                // password doesn't match one), this falls through to the
                // exact same "Account Not Found" messaging as before.
                // ============================================
                $adminStmt = $conn->prepare("SELECT id, password FROM admins WHERE email = ? LIMIT 1");
                $adminStmt->bind_param("s", $email);
                $adminStmt->execute();
                $adminResult = $adminStmt->get_result();
                $adminRow    = ($adminResult->num_rows > 0) ? $adminResult->fetch_assoc() : null;
                $adminStmt->close();

                if ($adminRow && password_verify($password, $adminRow['password'])) {
                    $popup_type  = 'admin_redirect';
                    $popup_title = 'Admin Account Detected';
                    $popup_msg   = 'This login page is for company accounts only. Please use the Admin Portal to access your account.';
                } else {
                    $popup_type  = 'confirm';
                    $popup_title = 'Account Not Found';
                    $popup_msg   = 'No company account was found with that email address. Would you like to register a new account?';
                }
            } else {
                $user = $result->fetch_assoc();

                if (!password_verify($password, $user['password'])) {
                    $popup_type  = 'error';
                    $popup_title = 'Incorrect Password';
                    $popup_msg   = 'The password you entered is incorrect. Please check your email for your 6-digit password and try again.';
                } elseif ($user['role'] === 'student') {
                    // ── Student account detected: this page is company-only ──
                    $popup_type  = 'student_redirect';
                    $popup_title = 'Student Account Detected';
                    $popup_msg   = 'This login page is for company accounts only. Please use the student login page to access your account.';
                } elseif ($user['role'] !== 'company') {
                    // Any other unexpected role degrades safely to the same
                    // "not found" messaging this page already used.
                    $popup_type  = 'confirm';
                    $popup_title = 'Account Not Found';
                    $popup_msg   = 'No company account was found with that email address. Would you like to register a new account?';
                } else {

                    // ============================================
                    // NEW: Remember-Me short-circuit — skip OTP entirely
                    // ------------------------------------------------------------
                    // If this device already carries a valid remember-me token
                    // for this exact company account, log in immediately
                    // (same behavior as login.php's existingRemember check)
                    // instead of starting the OTP step below.
                    // ============================================
                    $existingRemember = checkRememberMeCookie($user['id']);

                    if ($existingRemember && $existingRemember['role'] === 'company') {
                        // ── Login success (remembered device): store session ──
                        session_regenerate_id(true);
                        $_SESSION['user_id']          = $user['id'];
                        $_SESSION['user_role']        = 'company';
                        $_SESSION['role']             = 'company';
                        $_SESSION['user_email']       = $user['email'];
                        $_SESSION['user_first_name']  = $user['first_name'];
                        $_SESSION['user_middle_name'] = $user['middle_name'];
                        $_SESSION['user_last_name']   = $user['last_name'];
                        setRememberMeCookie($user['id'], 'company', $user['first_name']);

                        $redirect_to = determineCompanyRedirect($conn, $user['id']);
                    } else {
                        // ============================================
                        // NEW: OTP STEP — stash the verified credentials in
                        // temp session vars and move to step 2, exactly like
                        // login.php does with otp_email / user_id_temp / etc.
                        // ============================================
                        $_SESSION['cl_otp_email']           = $user['email'];
                        $_SESSION['cl_user_id_temp']        = $user['id'];
                        $_SESSION['cl_first_name_temp']     = $user['first_name'];
                        $_SESSION['cl_middle_name_temp']    = $user['middle_name'];
                        $_SESSION['cl_last_name_temp']      = $user['last_name'];
                        $_SESSION['cl_email_temp']          = $user['email'];
                        $_SESSION['cl_remember_me_pending'] = $rememberMe;

                        $step = 2;
                        $_SESSION['cl_step'] = 2;
                    }
                }
            }

            // $stmt is only ever created inside this else branch, and is only
            // ever closed here — exactly once — regardless of which path above
            // was taken.
            $stmt->close();
        }
    }

    // ============================================
    // NEW: SEND OTP
    // ============================================
    if (isset($_POST['send_otp'])) {
        if (!isset($_SESSION['cl_otp_email'])) {
            $error = "Please login first."; $step = 1; $_SESSION['cl_step'] = 1;
        } else {
            $current_time = time();
            if (!isset($_SESSION['cl_otp_send_count'])) $_SESSION['cl_otp_send_count'] = 0;

            if (isset($_SESSION['cl_otp_cooldown']) && $current_time < $_SESSION['cl_otp_cooldown']) {
                $remaining = $_SESSION['cl_otp_cooldown'] - $current_time;
                $error = "OTP cooldown active. Please wait " . ceil($remaining / 60) . " minute(s).";
                $step = 2;
            } else {
                if (isset($_SESSION['cl_otp_cooldown']) && $current_time >= $_SESSION['cl_otp_cooldown']) {
                    unset($_SESSION['cl_otp_cooldown']); $_SESSION['cl_otp_send_count'] = 0;
                }
                if ($_SESSION['cl_otp_send_count'] >= 3) {
                    $_SESSION['cl_otp_cooldown'] = $current_time + 180;
                    $error = "You have reached the maximum OTP attempts. Please wait 3 minutes.";
                    $step = 2;
                } else {
                    $otp = random_int(100000, 999999);
                    $_SESSION['cl_otp']      = $otp;
                    $_SESSION['cl_otp_time'] = $current_time;
                    if (sendOTPEmail($_SESSION['cl_otp_email'], $otp)) {
                        $_SESSION['cl_otp_send_count'] += 1;
                        $success = "OTP has been sent to your email. Valid for 5 minutes.";
                    } else {
                        $error = "Failed to send OTP. " . ($_SESSION['smtp_error'] ?? 'Check SMTP settings.');
                    }
                    $step = 2;
                }
            }
        }
        $_SESSION['cl_step'] = $step;
    }

    // ============================================
    // NEW: VERIFY OTP
    // ============================================
    if (isset($_POST['verify_otp'])) {
        if (!isset($_SESSION['cl_otp_email'])) {
            $error = "Please login first."; $step = 1;
        } elseif (!isset($_SESSION['cl_otp'])) {
            $error = "OTP not generated. Click 'Send OTP'."; $step = 2;
        } else {
            $entered_otp  = trim($_POST['otp'] ?? '');
            $current_time = time();
            if (($current_time - $_SESSION['cl_otp_time']) > 300) {
                $error = "OTP expired. Please resend.";
                unset($_SESSION['cl_otp'], $_SESSION['cl_otp_time']); $step = 2;
            } elseif ($entered_otp == $_SESSION['cl_otp']) {
                $rememberMe = $_SESSION['cl_remember_me_pending'] ?? false;

                session_regenerate_id(true);
                $_SESSION['user_id']          = $_SESSION['cl_user_id_temp'];
                $_SESSION['user_role']        = 'company';
                $_SESSION['role']             = 'company';
                $_SESSION['user_email']       = $_SESSION['cl_email_temp'];
                $_SESSION['user_first_name']  = $_SESSION['cl_first_name_temp'];
                $_SESSION['user_middle_name'] = $_SESSION['cl_middle_name_temp'];
                $_SESSION['user_last_name']   = $_SESSION['cl_last_name_temp'];

                if ($rememberMe) {
                    setRememberMeCookie($_SESSION['user_id'], 'company', $_SESSION['user_first_name']);
                }

                $companyUserIdForRedirect = $_SESSION['user_id'];

                unset($_SESSION['cl_otp'], $_SESSION['cl_otp_time'], $_SESSION['cl_otp_email'],
                      $_SESSION['cl_user_id_temp'], $_SESSION['cl_first_name_temp'],
                      $_SESSION['cl_middle_name_temp'], $_SESSION['cl_last_name_temp'],
                      $_SESSION['cl_email_temp'], $_SESSION['cl_otp_send_count'],
                      $_SESSION['cl_otp_cooldown'], $_SESSION['cl_remember_me_pending'],
                      $_SESSION['cl_step']);

                // Determine redirect target while $conn is still open.
                $redirect_to = determineCompanyRedirect($conn, $companyUserIdForRedirect);
            } else {
                $error = "Invalid OTP. Please try again."; $step = 2;
            }
        }
        if ($redirect_to === null) $_SESSION['cl_step'] = $step;
    }

    // ============================================
    // NEW: CANCEL OTP — return to login (step 1)
    // ============================================
    if (isset($_POST['otp_cancel'])) {
        unset($_SESSION['cl_otp_email'], $_SESSION['cl_otp'], $_SESSION['cl_otp_time'],
              $_SESSION['cl_user_id_temp'], $_SESSION['cl_first_name_temp'],
              $_SESSION['cl_middle_name_temp'], $_SESSION['cl_last_name_temp'],
              $_SESSION['cl_email_temp'], $_SESSION['cl_remember_me_pending'],
              $_SESSION['cl_otp_send_count'], $_SESSION['cl_otp_cooldown']);
        $step = 1;
        $_SESSION['cl_step'] = 1;
    }

    // ════════════════════════════════════════════════════════════
    // NEW: SELF-CONTAINED FORGOT PASSWORD FLOW (company accounts)
    // ------------------------------------------------------------
    // Mirrors login.php's FP1/FP2/FP3 forgot-password flow, but scoped
    // entirely to this page and restricted to `role = 'company'`
    // accounts, using its own session keys (ccl_fp_*) so it never
    // collides with login.php's own fp_* session state if both pages
    // are used in the same browser session. This means the Customer
    // Service "Forgot Password" option on the company login page no
    // longer needs to hand the visitor off to login.php.
    // ════════════════════════════════════════════════════════════

    // ── FP-GO: open the "enter email" step ──
    if (isset($_POST['fp_go'])) {
        unset($_SESSION['ccl_fp_email'], $_SESSION['ccl_fp_otp'], $_SESSION['ccl_fp_otp_time'],
              $_SESSION['ccl_fp_otp_count'], $_SESSION['ccl_fp_cooldown'], $_SESSION['ccl_fp_first_name']);
        $_SESSION['cl_step'] = 4;
        header("Location: company_login.php");
        exit();
    }

    // ── FP1: SUBMIT EMAIL ──
    if (isset($_POST['fp_submit_email'])) {
        $fp_email = trim($_POST['fp_email'] ?? '');
        $fp_user  = null;

        $stmt = $conn->prepare("SELECT id, first_name FROM users WHERE email = ? AND role = 'company' LIMIT 1");
        $stmt->bind_param("s", $fp_email);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) { $fp_user = $row; }

        if (!$fp_user) {
            $fp_error = "No company account was found with that email address.";
            $step = 4; $_SESSION['cl_step'] = 4;
        } else {
            $_SESSION['ccl_fp_email']      = $fp_email;
            $_SESSION['ccl_fp_first_name'] = $fp_user['first_name'];
            $_SESSION['ccl_fp_otp_count']  = 0;
            unset($_SESSION['ccl_fp_cooldown']);

            $otp = random_int(100000, 999999);
            $_SESSION['ccl_fp_otp']      = $otp;
            $_SESSION['ccl_fp_otp_time'] = time();
            if (sendOTPEmail($fp_email, $otp)) {
                $_SESSION['ccl_fp_otp_count'] = 1;
                $success = "OTP sent to " . htmlspecialchars($fp_email) . ". Valid for 5 minutes.";
                $step = 5; $_SESSION['cl_step'] = 5;
            } else {
                $fp_error = "Failed to send OTP. Please try again.";
                $step = 4; $_SESSION['cl_step'] = 4;
            }
        }
    }

    // ── FP2: RESEND OTP ──
    if (isset($_POST['fp_resend_otp'])) {
        if (!isset($_SESSION['ccl_fp_email'])) {
            $step = 4; $_SESSION['cl_step'] = 4;
        } else {
            $current_time = time();
            if (!isset($_SESSION['ccl_fp_otp_count'])) $_SESSION['ccl_fp_otp_count'] = 0;

            if (isset($_SESSION['ccl_fp_cooldown']) && $current_time < $_SESSION['ccl_fp_cooldown']) {
                $remaining = $_SESSION['ccl_fp_cooldown'] - $current_time;
                $fp_error  = "Please wait " . ceil($remaining / 60) . " minute(s) before resending.";
                $step = 5; $_SESSION['cl_step'] = 5;
            } elseif ($_SESSION['ccl_fp_otp_count'] >= 3) {
                $_SESSION['ccl_fp_cooldown'] = $current_time + 180;
                $fp_error = "Maximum OTP attempts reached. Please wait 3 minutes.";
                $step = 5; $_SESSION['cl_step'] = 5;
            } else {
                $otp = random_int(100000, 999999);
                $_SESSION['ccl_fp_otp']      = $otp;
                $_SESSION['ccl_fp_otp_time'] = $current_time;
                if (sendOTPEmail($_SESSION['ccl_fp_email'], $otp)) {
                    $_SESSION['ccl_fp_otp_count'] += 1;
                    $success = "OTP resent to " . htmlspecialchars($_SESSION['ccl_fp_email']) . ".";
                } else {
                    $fp_error = "Failed to resend OTP. Please try again.";
                }
                $step = 5; $_SESSION['cl_step'] = 5;
            }
        }
    }

    // ── FP3: VERIFY OTP & RESET PASSWORD ──
    if (isset($_POST['fp_verify_otp'])) {
        if (!isset($_SESSION['ccl_fp_email']) || !isset($_SESSION['ccl_fp_otp'])) {
            $fp_error = "Session expired. Please start over.";
            $step = 4; $_SESSION['cl_step'] = 4;
        } else {
            $entered      = trim($_POST['fp_otp_input'] ?? '');
            $current_time = time();

            if (($current_time - $_SESSION['ccl_fp_otp_time']) > 300) {
                $fp_error = "OTP expired. Please resend.";
                unset($_SESSION['ccl_fp_otp'], $_SESSION['ccl_fp_otp_time']);
                $step = 5; $_SESSION['cl_step'] = 5;
            } elseif ($entered == $_SESSION['ccl_fp_otp']) {
                $newPass    = generateRandomPassword(10);
                $hashedPass = password_hash($newPass, PASSWORD_DEFAULT);
                $fp_email   = $_SESSION['ccl_fp_email'];
                $fp_name    = $_SESSION['ccl_fp_first_name'];

                $stmt = $conn->prepare("UPDATE users SET password = ? WHERE email = ? AND role = 'company'");
                $stmt->bind_param("ss", $hashedPass, $fp_email);
                $stmt->execute();
                $stmt->close();

                sendNewPasswordEmail($fp_email, $fp_name, $newPass);

                unset($_SESSION['ccl_fp_email'], $_SESSION['ccl_fp_otp'], $_SESSION['ccl_fp_otp_time'],
                      $_SESSION['ccl_fp_otp_count'], $_SESSION['ccl_fp_cooldown'], $_SESSION['ccl_fp_first_name']);

                $step = 6; $_SESSION['cl_step'] = 6;
            } else {
                $fp_error = "Invalid OTP. Please try again.";
                $step = 5; $_SESSION['cl_step'] = 5;
            }
        }
    }

    // ── FP-CANCEL: back to login (step 1) ──
    if (isset($_POST['fp_cancel'])) {
        unset($_SESSION['ccl_fp_email'], $_SESSION['ccl_fp_otp'], $_SESSION['ccl_fp_otp_time'],
              $_SESSION['ccl_fp_otp_count'], $_SESSION['ccl_fp_cooldown'], $_SESSION['ccl_fp_first_name']);
        $step = 1;
        $_SESSION['cl_step'] = 1;
    }

    // ════════════════════════════════════════════════════════════
    // NEW: SELF-CONTAINED RECOVER EMAIL FLOW (company accounts)
    // ------------------------------------------------------------
    // Mirrors login.php's email-recovery request flow, but skips the
    // "Student vs Company" type-selection step entirely (this page is
    // company-only, so the account type is always 'company'), and
    // validates the submitted credentials against `users` where
    // `role = 'company'` instead of leaving that branch unimplemented.
    // Requests are written to the exact same shared
    // `email_recovery_requests` table login.php already uses, so the
    // administrator's review queue sees both student and company
    // requests in one place, same as before.
    // ════════════════════════════════════════════════════════════

    // ── RE-GO: open the request form directly (no type picker needed) ──
    if (isset($_POST['re_go'])) {
        $_SESSION['cl_step'] = 8;
        header("Location: company_login.php");
        exit();
    }

    // ── RE-CANCEL: back to login (step 1) ──
    if (isset($_POST['re_cancel'])) {
        $_SESSION['cl_step'] = 1;
        header("Location: company_login.php");
        exit();
    }

    // ── RE-SUBMIT: validate + store the recovery request ──
    if (isset($_POST['re_submit_request'])) {
        $conn2 = $conn;

        $conn2->query("CREATE TABLE IF NOT EXISTS email_recovery_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            account_type VARCHAR(20),
            first_name VARCHAR(100),
            middle_name VARCHAR(100),
            last_name VARCHAR(100),
            old_email VARCHAR(200),
            new_email VARCHAR(200),
            reason TEXT,
            extra_info VARCHAR(300),
            selfie_blob MEDIUMBLOB,
            status VARCHAR(20) DEFAULT 'Pending',
            submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $re_first    = trim($_POST['re_first_name']       ?? '');
        $re_middle   = trim($_POST['re_middle_name']      ?? '');
        $re_last     = trim($_POST['re_last_name']        ?? '');
        $re_old      = trim($_POST['re_old_email']        ?? '');
        $re_new      = trim($_POST['re_new_email']        ?? '');
        $re_reason   = trim($_POST['re_reason']           ?? '');
        $re_location = trim($_POST['re_company_location'] ?? '');
        $re_extra    = $re_location;

        if (empty($re_first) || empty($re_last) || empty($re_old) || empty($re_new) || empty($re_reason) || empty($re_location)) {
            $re_error = "Please fill in all required fields.";
            $step = 8; $_SESSION['cl_step'] = 8;
        } elseif (!filter_var($re_old, FILTER_VALIDATE_EMAIL) || !filter_var($re_new, FILTER_VALIDATE_EMAIL)) {
            $re_error = "Please enter valid email addresses.";
            $step = 8; $_SESSION['cl_step'] = 8;
        } else {
            $qm = $conn2->prepare("
                SELECT id FROM users
                WHERE role = 'company'
                AND LOWER(TRIM(first_name))  = LOWER(TRIM(?))
                AND LOWER(TRIM(middle_name)) = LOWER(TRIM(?))
                AND LOWER(TRIM(last_name))   = LOWER(TRIM(?))
                AND LOWER(TRIM(email))       = LOWER(TRIM(?))
                LIMIT 1
            ");
            $qm->bind_param("ssss", $re_first, $re_middle, $re_last, $re_old);
            $qm->execute();
            $qm->store_result();
            $match = ($qm->num_rows > 0);
            $qm->close();

            if (!$match) {
                $re_error = "Credentials do not match any company account in our records. Please check all fields and try again.";
                $step = 8; $_SESSION['cl_step'] = 8;
            } else {
                $selfieBlob = null;
                $selfieData = $_POST['re_selfie_data'] ?? '';
                if (!empty($selfieData)) {
                    $selfieData = preg_replace('/^data:image\/\w+;base64,/', '', $selfieData);
                    $selfieBlob = base64_decode($selfieData);
                }

                $stmt = $conn2->prepare("INSERT INTO email_recovery_requests
                    (account_type, first_name, middle_name, last_name, old_email, new_email, reason, extra_info, selfie_blob, status, submitted_at)
                    VALUES ('company', ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())");
                $null = null;
                $stmt->bind_param("sssssssb",
                    $re_first, $re_middle, $re_last,
                    $re_old, $re_new, $re_reason, $re_extra, $null
                );
                if ($selfieBlob !== null) {
                    $stmt->send_long_data(7, $selfieBlob);
                }

                if ($stmt->execute()) {
                    $step = 9; $_SESSION['cl_step'] = 9;
                } else {
                    $re_error = "Database error: " . $conn2->error;
                    $step = 8; $_SESSION['cl_step'] = 8;
                }
                $stmt->close();
            }
        }
    }

    // $conn is opened once by db.php for this request; close it exactly
    // once here, after all statements above have finished with it.
    if (isset($conn)) {
        $conn->close();
    }

    // Perform the redirect (if any) only after DB resources are closed.
    if ($redirect_to !== null) {
        header("Location: " . $redirect_to);
        exit();
    }
}

// ── Compute OTP seconds remaining (for the countdown ring) ────────────────
$otpSecondsLeft = 0;
if ($step == 2 && isset($_SESSION['cl_otp_time'])) {
    $otpSecondsLeft = max(0, 300 - (time() - $_SESSION['cl_otp_time']));
}
$showOtpRing = ($step == 2 && isset($_SESSION['cl_otp_time']) && $otpSecondsLeft > 0);

// ── NEW: Compute Forgot-Password OTP seconds remaining (its own ring) ──────
$fpOtpSecondsLeft = 0;
if ($step == 5 && isset($_SESSION['ccl_fp_otp_time'])) {
    $fpOtpSecondsLeft = max(0, 300 - (time() - $_SESSION['ccl_fp_otp_time']));
}
$showFpOtpRing = ($step == 5 && isset($_SESSION['ccl_fp_otp_time']) && $fpOtpSecondsLeft > 0);

$hasValidRememberCookie = (bool) checkRememberMeCookie();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Company Login | NEUST OJT Portal</title>
    <!-- ══════════════════════════════════════════════════════════════
         NEW: SIDEBAR STATE SYNC (anti-flash, runs before first paint)
         ------------------------------------------------------------
         Reads the sidebar's collapsed/expanded state that was last saved
         (by the toggle handler further below) from localStorage under the
         shared key "neustSidebarCollapsed" and applies a matching class to
         <html> immediately. This is what keeps the sidebar "in sync" as the
         user travels between login.php and company_login.php: whichever
         state they left it in on one page is what greets them on the next,
         with no visible flash of the opposite state while the page loads.
         The actual #sidebar/#body classes are still initialized the normal
         way further down in <body> — this just prevents the flash before
         that script has a chance to run.
    ══════════════════════════════════════════════════════════════ -->
    <script>
    (function () {
        try {
            if (localStorage.getItem('neustSidebarCollapsed') === '1') {
                document.documentElement.classList.add('sb-pref-collapsed');
            }
        } catch (e) { /* localStorage unavailable — ignore, falls back to default expanded state */ }
    })();
    </script>
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
        /* ── NEW: reserves space for the persistent sidebar (see the
           .sidebar rules further below) so the centered login card sits
           within the remaining viewport instead of underneath it. Shrinks
           to match the sidebar's collapsed width via the
           .sidebar-is-collapsed class, toggled in JS alongside
           #sidebar's own .collapsed class. ── */
        padding-left: 260px;
        transition: padding-left 0.3s ease;
    }
    body.sidebar-is-collapsed { padding-left: 80px; }

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

    h2 { color: #ffffff; margin-bottom: 25px; font-size: 26px; }

    .input-group { text-align: left; margin-bottom: 18px; }

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
    input[type="password"],
    input[type="number"] {
        width: 100%;
        padding: 14px;
        border-radius: 12px;
        border: 1.5px solid rgba(255, 255, 255, 0.25);
        background: rgba(255, 255, 255, 0.12);
        color: #ffffff;
        box-sizing: border-box;
        font-size: 14px;
        font-family: 'Plus Jakarta Sans', sans-serif;
        outline: none;
        transition: border-color 0.2s, background 0.2s;
    }

    input[type="text"]:focus,
    input[type="email"]:focus,
    input[type="password"]:focus,
    input[type="number"]:focus {
        border-color: rgba(255, 255, 255, 0.55);
        background: rgba(255, 255, 255, 0.18);
    }

    input::placeholder { color: rgba(255, 255, 255, 0.50); }

    /* ── NEW: PASSWORD SHOW/HIDE — ported from login.php ── */
    .password-wrapper { position: relative; display: flex; align-items: center; }

    .toggle-btn {
        position: absolute; right: 15px; background: none; border: none;
        color: #93c5fd; font-weight: 700; font-size: 11px;
        cursor: pointer; text-transform: uppercase;
    }

    /* ── NEW: REMEMBER ME — ported from login.php ── */
    .remember-me-row {
        display: flex; align-items: center; gap: 10px;
        margin-bottom: 6px; margin-top: 4px; text-align: left;
    }
    .remember-me-row input[type="checkbox"] {
        width: 18px; height: 18px; min-width: 18px; padding: 0;
        border-radius: 5px; border: 1.5px solid rgba(255,255,255,0.40);
        background: rgba(255,255,255,0.15); cursor: pointer;
        accent-color: #3b82f6; box-sizing: border-box;
    }
    .remember-me-row .rm-label {
        font-size: 13px; font-weight: 600; color: rgba(255,255,255,0.85);
        text-transform: none; cursor: pointer; user-select: none;
        margin: 0; display: inline;
    }
    .remember-me-hint {
        font-size: 11px; color: rgba(255,255,255,0.50);
        text-align: left; margin-bottom: 14px; margin-top: -2px; padding-left: 28px;
    }
    .remember-me-active-badge {
        display: flex; align-items: center; gap: 8px;
        background: rgba(56, 189, 248, 0.15);
        border: 1px solid rgba(56, 189, 248, 0.40);
        border-radius: 10px; padding: 9px 13px;
        margin-bottom: 14px; margin-top: 4px;
        font-size: 12px; font-weight: 600; color: #bae6fd;
        text-align: left;
    }
    .remember-me-active-badge .badge-icon { font-size: 15px; flex-shrink: 0; }

    /* ── NEW: OTP COUNTDOWN RING — ported from login.php ── */
    .otp-countdown-wrap {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 6px;
        margin: 4px 0 18px;
    }
    .otp-countdown-ring {
        position: relative;
        width: 80px;
        height: 80px;
    }
    .otp-countdown-ring svg {
        transform: rotate(-90deg);
        width: 80px;
        height: 80px;
        display: block;
    }
    .otp-ring-bg {
        fill: none;
        stroke: rgba(255,255,255,0.12);
        stroke-width: 6;
    }
    .otp-ring-fill {
        fill: none;
        stroke: #38bdf8;
        stroke-width: 6;
        stroke-linecap: round;
        stroke-dasharray: 207.35;
        stroke-dashoffset: 0;
        transition: stroke-dashoffset 1s linear, stroke 0.5s ease;
    }
    .otp-ring-time {
        position: absolute;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 16px;
        font-weight: 700;
        color: #ffffff;
        letter-spacing: -0.5px;
    }
    .otp-countdown-label {
        font-size: 11px;
        color: rgba(255,255,255,0.55);
        font-weight: 600;
        letter-spacing: 0.2px;
        text-transform: uppercase;
    }
    .otp-expired-msg {
        display: none;
        font-size: 12px;
        color: #fca5a5;
        font-weight: 600;
        text-align: center;
        background: rgba(239,68,68,0.15);
        border: 1px solid rgba(239,68,68,0.35);
        border-radius: 8px;
        padding: 6px 12px;
        margin-top: 2px;
    }

    /* ═══════════════════════════════════════════════
       NEW: OTP BOX-STYLE INPUT (individual digit boxes)
       ------------------------------------------------
       Replaces the old single "Enter 6-digit OTP" text
       input with six separate boxes (one per digit), to
       match the requested OTP verification field design.
       Only the input field itself changes here — the
       surrounding card, labels, buttons, and countdown
       ring above are all untouched.
       ═══════════════════════════════════════════════ */
    .otp-box-group {
        display: flex;
        justify-content: center;
        gap: 9px;
        margin: 0 0 4px;
    }
    .otp-box {
        width: 42px;
        height: 52px;
        padding: 0;
        text-align: center;
        font-size: 20px;
        font-weight: 700;
        font-family: 'Plus Jakarta Sans', sans-serif;
        border-radius: 12px;
        border: 1.5px solid rgba(255,255,255,0.45);
        background: rgba(255,255,255,0.90);
        color: #1e293b;
        box-sizing: border-box;
        outline: none;
        caret-color: #0038a8;
        transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease, transform 0.15s ease;
        -moz-appearance: textfield;
    }
    .otp-box::-webkit-outer-spin-button,
    .otp-box::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
    .otp-box::placeholder { color: #94a3b8; }
    .otp-box:focus {
        border-color: #38bdf8;
        background: #ffffff;
        box-shadow: 0 0 0 4px rgba(56,189,248,0.28);
        transform: translateY(-1px);
    }
    .otp-box.otp-box-filled { border-color: #93c5fd; }

    .login-submit {
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

    .login-submit:hover {
        background: #ffffff;
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(0,0,0,0.25);
    }

    /* ── NEW: secondary button — ported from login.php (used by the
       OTP step's "Back to Login" action) ── */
    .btn-secondary {
        width: 100%; padding: 13px; background: rgba(255,255,255,0.12);
        color: rgba(255,255,255,0.80); border: 1.5px solid rgba(255,255,255,0.25);
        border-radius: 12px; font-weight: 600; font-size: 14px; cursor: pointer;
        margin-top: 10px; transition: 0.2s; font-family: 'Plus Jakarta Sans', sans-serif;
    }
    .btn-secondary:hover { background: rgba(255,255,255,0.20); color: #ffffff; }

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

    .back-link {
        display: block;
        margin-top: 14px;
        font-size: 13px;
        color: rgba(255,255,255,0.7);
        text-decoration: none;
        font-weight: 600;
    }
    .back-link:hover { text-decoration: underline; color: #ffffff; }

    /* ═══════════════════════════════════════════════
       NEW: FORGOT PASSWORD / RECOVER EMAIL — ported from
       login.php so this page's own Customer Service menu
       (added further below) can run these flows itself
       instead of handing the visitor off to login.php.
       ═══════════════════════════════════════════════ */

    /* Forgot password card inputs: light for readability */
    .fp-light-input {
        background: rgba(255,255,255,0.92) !important;
        color: #1e293b !important;
        border: 1.5px solid rgba(255,255,255,0.60) !important;
    }
    .fp-light-input::placeholder { color: #94a3b8 !important; }

    /* ── SUCCESS POPUP (forgot-password reset confirmation) ── */
    #fpSuccessOverlay {
        display: none; position: fixed; inset: 0;
        background: rgba(0,0,0,0.55); z-index: 9999;
        justify-content: center; align-items: center;
        backdrop-filter: blur(4px); animation: fpFadeIn 0.25s ease;
    }
    @keyframes fpFadeIn { from{opacity:0;} to{opacity:1;} }
    #fpSuccessBox {
        background: white; border-radius: 20px; padding: 40px 36px;
        width: 340px; max-width: 92%; text-align: center;
        box-shadow: 0 20px 60px rgba(0,0,0,0.25);
        animation: fpPopIn 0.3s cubic-bezier(0.34,1.56,0.64,1);
    }
    @keyframes fpPopIn { from{transform:scale(0.85);opacity:0;} to{transform:scale(1);opacity:1;} }
    #fpSuccessBox .sp-icon  { font-size: 52px; display: block; margin-bottom: 14px; }
    #fpSuccessBox .sp-title { font-size: 18px; font-weight: 700; color: #0038a8; margin: 0 0 8px; }
    #fpSuccessBox .sp-msg   { font-size: 13px; color: #64748b; margin: 0 0 24px; line-height: 1.6; }
    #fpSuccessBox .sp-btn {
        padding: 13px 36px; background: #0038a8; color: white;
        border: none; border-radius: 12px; font-weight: 700;
        font-size: 14px; cursor: pointer; transition: 0.2s;
    }
    #fpSuccessBox .sp-btn:hover { background: #002d86; }

    /* ── RECOVER EMAIL FORM ── */
    .re-section-title {
        font-size: 13px; font-weight: 700; color: #93c5fd;
        text-transform: uppercase; letter-spacing: 0.5px;
        margin: 18px 0 10px; text-align: left;
    }

    /* Recover email form inputs use light theme for readability */
    #reForm input[type="text"],
    #reForm input[type="email"],
    #reForm textarea {
        background: rgba(255,255,255,0.92);
        color: #1e293b;
        border: 1.5px solid rgba(255,255,255,0.60);
    }
    #reForm input::placeholder,
    #reForm textarea::placeholder { color: #94a3b8; }
    #reForm label { color: rgba(255,255,255,0.75); }

    .re-camera-box {
        width: 100%; aspect-ratio: 4/3; background: #0f172a;
        border-radius: 12px; overflow: hidden; position: relative; margin-bottom: 8px;
    }
    .re-camera-box video { width: 100%; height: 100%; object-fit: cover; display: block; }
    .re-camera-box canvas { display: none; }
    .re-snap-btn {
        width: 100%; padding: 12px; background: #0038a8; color: white;
        border: none; border-radius: 10px; font-weight: 700; font-size: 13px;
        cursor: pointer; margin-bottom: 6px; transition: 0.2s;
    }
    .re-snap-btn:hover { background: #002d86; }
    .re-retake-btn {
        width: 100%; padding: 10px; background: rgba(255,255,255,0.15); color: rgba(255,255,255,0.80);
        border: 1.5px solid rgba(255,255,255,0.25); border-radius: 10px; font-weight: 600; font-size: 12px;
        cursor: pointer; display: none; margin-bottom: 8px;
    }
    .re-preview-img {
        width: 100%; border-radius: 10px; display: none;
        border: 2px solid #3b82f6; margin-bottom: 8px;
    }
    .re-camera-hint { font-size: 11px; color: rgba(255,255,255,0.55); text-align: center; margin-bottom: 12px; }

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
    .rp-title.error            { color: #dc2626; }
    .rp-title.success          { color: #0038a8; }
    .rp-title.warning          { color: #d97706; }
    .rp-title.confirm          { color: #0038a8; }
    .rp-title.student_redirect { color: #0038a8; }
    .rp-title.admin_redirect   { color: #6d28d9; }
    .rp-msg   { font-size: 13px; color: #64748b; margin: 0 0 24px; line-height: 1.6; }

    /* Wrapper so Yes/No buttons can sit side by side without touching
       the existing single-button layout for error/success/warning */
    .rp-btn-row {
        display: flex;
        gap: 12px;
        justify-content: center;
    }

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
    .rp-btn.error            { background: #dc2626; }
    .rp-btn.success          { background: #0038a8; }
    .rp-btn.warning          { background: #d97706; }
    .rp-btn.confirm          { background: #0038a8; }
    .rp-btn.confirm-no       { background: #94a3b8; }
    .rp-btn.student_redirect { background: #0038a8; }
    .rp-btn.admin_redirect   { background: #6d28d9; }
    .rp-btn:hover   { opacity: 0.85; }

    /* ═══════════════════════════════════════════════════════════════
       CUSTOMER SERVICE FAB (bottom-right speed-dial)
       ------------------------------------------------------------
       Styling ported from login.php. Clicking it expands two
       pill-shaped options that submit hidden-field forms (fp_go /
       re_go) to THIS page's own self-contained Forgot Password and
       Recover Email Address flows (see the FP/RE PHP handlers and
       the step 4/5/6/8/9 markup elsewhere in this file) — the
       visitor is no longer sent to the student login page for
       either of these.
       ═══════════════════════════════════════════════════════════════ */
    #csFabMenu {
        position: fixed; bottom: 28px; right: 28px; z-index: 1200;
        display: flex; flex-direction: column; align-items: flex-end; gap: 12px;
    }
    .cs-fab-main-btn {
        width: 58px; height: 58px;
        background: rgba(255,255,255,0.16);
        backdrop-filter: blur(14px);
        -webkit-backdrop-filter: blur(14px);
        border: 1.5px solid rgba(255,255,255,0.35);
        color: #ffffff;
        border-radius: 50%;
        font-size: 24px;
        cursor: pointer;
        box-shadow: 0 10px 26px rgba(0,0,20,0.35);
        display: flex; align-items: center; justify-content: center;
        transition: transform 0.25s ease, box-shadow 0.25s ease, background 0.25s ease;
        position: relative;
    }
    .cs-fab-main-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 14px 32px rgba(0,0,20,0.45);
        background: rgba(255,255,255,0.24);
    }
    .cs-fab-main-btn i { pointer-events: none; }

    .cs-fab-options {
        display: flex; flex-direction: column; align-items: flex-end; gap: 10px;
        opacity: 0; pointer-events: none; transform: translateY(10px) scale(0.96);
        transition: opacity 0.22s ease, transform 0.22s ease;
    }
    #csFabMenu.open .cs-fab-options { opacity: 1; pointer-events: auto; transform: translateY(0) scale(1); }

    .cs-fab-option {
        display: flex; align-items: center; gap: 10px;
        background: rgba(255,255,255,0.94);
        border: none; border-radius: 50px;
        padding: 9px 18px 9px 9px;
        cursor: pointer;
        box-shadow: 0 8px 22px rgba(0,0,0,0.28);
        font-family: 'Plus Jakarta Sans', sans-serif; font-weight: 700; font-size: 0.82rem;
        color: #0038a8;
        transition: transform 0.18s, box-shadow 0.18s;
        white-space: nowrap;
    }
    .cs-fab-option:hover { transform: translateY(-2px) scale(1.02); box-shadow: 0 12px 26px rgba(0,0,0,0.32); }
    .cs-fab-option-icon {
        width: 30px; height: 30px; border-radius: 50%;
        background: #0038a8; color: #ffffff;
        display: flex; align-items: center; justify-content: center;
        font-size: 13px; flex-shrink: 0;
    }
    .cs-fab-option-label { padding-right: 2px; }

    /* ═══════════════════════════════════════════════════════════════
       SIDEBAR (Home / Student Portal / Company Portal)
       ------------------------------------------------------------
       Ported from login.php: fixed 260px panel (collapsible to an
       80px icon rail via a toggle button in its own header), same
       maroon/gold design tokens, same link/active/hover treatment,
       same collapse mechanics. "Company Portal" is the active link
       on this page; "Student Portal" points to login.php; "Home"
       remains a placeholder for a page that doesn't exist yet.
       ═══════════════════════════════════════════════════════════════ */
    :root {
        --neust-maroon: #07145fe5;
        --neust-gold:   #FFD700;
        --neust-active: #1a237e;
    }

    .sidebar {
        width: 260px;
        background: var(--neust-maroon);
        height: 100vh;
        position: fixed;
        top: 0; left: 0;
        display: flex;
        flex-direction: column;
        transition: width 0.3s ease;
        z-index: 1300;
        box-shadow: 4px 0 10px rgba(0,0,0,0.25);
    }
    .sidebar.collapsed { width: 80px; }

    .sidebar-header {
        padding: 16px 20px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        border-bottom: 1px solid rgba(255,255,255,0.1);
        flex-shrink: 0;
        min-height: 72px;
    }
    .sidebar-brand {
        display: flex;
        flex-direction: column;
        gap: 1px;
        overflow: hidden;
        transition: opacity 0.2s, width 0.3s;
        max-width: 180px;
    }
    .sidebar-brand-name {
        color: var(--neust-gold);
        font-size: 13.5px;
        font-weight: 700;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        line-height: 1.3;
        font-family: 'Plus Jakarta Sans', sans-serif;
    }
    .sidebar-brand-sub {
        color: rgba(255,255,255,0.55);
        font-size: 10px;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        white-space: nowrap;
    }
    .sidebar.collapsed .sidebar-brand { opacity: 0; width: 0; overflow: hidden; }

    .sidebar-links {
        flex: 1;
        display: flex;
        flex-direction: column;
        padding: 10px 0;
        overflow: hidden;
    }
    .sidebar a {
        padding: 15px 25px;
        color: #cbd5e0;
        text-decoration: none;
        font-size: 14px;
        display: flex;
        align-items: center;
        transition: background 0.2s, color 0.2s;
        white-space: nowrap;
        position: relative;
    }
    .sidebar a i {
        width: 30px;
        font-size: 18px;
        margin-right: 15px;
        text-align: center;
        flex-shrink: 0;
    }
    .sidebar.collapsed .link-text { display: none; }
    .sidebar.collapsed a i { margin-right: 0; }

    .sidebar a:hover:not(.active):not(.link-disabled) { background: rgba(255,255,255,0.07); color: white; }
    .sidebar a.active {
        background: var(--neust-active);
        color: white;
        border-left: 4px solid var(--neust-gold);
    }
    .sidebar a.link-disabled { cursor: not-allowed; opacity: 0.55; }
    .sidebar a.link-disabled:hover { background: rgba(255,255,255,0.04); color: #cbd5e0; }

    .sidebar-link-tag {
        margin-left: auto; font-size: 9px; font-weight: 700; text-transform: uppercase;
        background: rgba(255,255,255,0.18); padding: 2px 7px; border-radius: 20px;
        letter-spacing: 0.4px;
        flex-shrink: 0;
    }
    .sidebar.collapsed .sidebar-link-tag { display: none; }

    .sidebar-toggle-btn {
        background: transparent;
        border: none;
        color: white;
        cursor: pointer;
        font-size: 20px;
        outline: none;
        flex-shrink: 0;
    }

    /* ═══════════════════════════════════════════════════════════════
       SIDEBAR STATE SYNC — anti-flash styling
       ------------------------------------------------------------
       Mirrors the .collapsed / .sidebar-is-collapsed layout exactly,
       but keyed off the <html class="sb-pref-collapsed"> flag set by the
       inline script in <head> (before first paint), so a visitor arriving
       from login.php with the sidebar collapsed sees it collapsed here
       immediately too, instead of briefly seeing it expanded before the
       DOMContentLoaded script catches up.
       ═══════════════════════════════════════════════════════════════ */
    html.sb-pref-collapsed body { padding-left: 80px; }
    html.sb-pref-collapsed .sidebar { width: 80px; }
    html.sb-pref-collapsed .sidebar-brand { opacity: 0; width: 0; overflow: hidden; }
    html.sb-pref-collapsed .link-text { display: none; }
    html.sb-pref-collapsed .sidebar a i { margin-right: 0; }
    html.sb-pref-collapsed .sidebar-link-tag { display: none; }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

<!-- ══════════════════════════════════════════════════════════════
     SIDEBAR — Home / Student Portal / Company Portal
     Ported from login.php. "Company Portal" is active on this page.
══════════════════════════════════════════════════════════════ -->
<div id="sidebar" class="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-brand">
            <span class="sidebar-brand-name">NEUST Atate OJT</span>
            <span class="sidebar-brand-sub">Portal Menu</span>
        </div>
        <button type="button" id="sidebarToggleBtn" class="sidebar-toggle-btn" title="Toggle menu">
            <i class="fas fa-bars"></i>
        </button>
    </div>
    <div class="sidebar-links">
        <a href="#" class="link-disabled" onclick="event.preventDefault();" title="Coming soon">
            <i class="fas fa-house"></i>
            <span class="link-text">Home</span>
            <span class="sidebar-link-tag">Soon</span>
        </a>
        <a href="login.php">
            <i class="fas fa-user-graduate"></i>
            <span class="link-text">Student Portal</span>
        </a>
        <a href="company_login.php" class="active">
            <i class="fas fa-building"></i>
            <span class="link-text">Company Portal</span>
        </a>
        <a href="admin_login.php" >
            <i class="fas fa-user-shield"></i>
            <span class="link-text">Admin Portal</span>
        </a>
    </div>
</div>

<!-- ── POPUP NOTIFICATION ── -->
<div id="regPopupOverlay">
    <div id="regPopupBox">
        <span class="rp-icon" id="rpIcon"></span>
        <p class="rp-title" id="rpTitle"></p>
        <p class="rp-msg"   id="rpMsg"></p>
        <div class="rp-btn-row">
            <button class="rp-btn" id="rpBtn" onclick="rpClose()">OK</button>
            <button class="rp-btn confirm-no" id="rpBtnNo" style="display:none;" onclick="rpClose()">No</button>
        </div>
    </div>
</div>

<!-- ── HEADER ── -->
<div style="display:flex; align-items:center; gap:18px;">
    <img src="logo.webp" alt="System Logo" style="width:72px; height:72px; border-radius:50%; object-fit:contain; box-shadow:0 4px 16px rgba(0,0,0,0.4);">
    <div style="border-left: 3px solid rgba(255,255,255,0.4); padding-left: 16px;">
        <p style="margin:0 0 2px; font-size:11px; font-weight:600; color:rgba(255,255,255,0.6); text-transform:uppercase; letter-spacing:1.5px;">NEUST Atate Campus</p>
        <p style="margin:0; font-size:18px; font-weight:700; color:#ffffff; line-height:1.3;">Web-Based Smart OJT<br>Monitoring and Supervision<br>Analytics System</p>
    </div>
</div>

<div class="card">

    <?php
    // ── FIX: duplicate OTP "sent" success banner on the Forgot
    // Password OTP-verification screen (step 5) ────────────────────
    // Previously this global banner and step 5's own banner (further
    // below, right under "An OTP was sent to ...") both echoed the
    // exact same $success text, so the same green message appeared
    // twice on screen (see bug screenshot). The fix simply skips the
    // global echo while on step 5, since step 5 already displays it
    // once, in context, right under its own instructional text.
    // No other step, message, or piece of logic is touched: $error
    // still prints globally on every step exactly as before, and
    // $success still prints globally (once) on every step other than
    // step 5.
    ?>
    <?php if (!empty($error))   echo "<p style='color:#fca5a5;text-align:center;background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.3);border-radius:10px;padding:10px;'>$error</p>"; ?>
    <?php if (!empty($success) && $step != 5) echo "<p style='color:#86efac;text-align:center;background:rgba(34,197,94,0.15);border:1px solid rgba(34,197,94,0.3);border-radius:10px;padding:10px;'>$success</p>"; ?>

    <?php if ($step == 1): ?>
    <!-- ════════════════════ STEP 1: LOGIN ════════════════════ -->
    <form action="company_login.php" method="POST">

        <div class="input-group">
            <label>Email</label>
            <input type="email" name="email" placeholder="email@example.com" required
                value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        </div>

        <div class="input-group">
            <label>Password</label>
            <!-- NEW: show/hide toggle — ported from login.php's password field
                 so this page's login password can be revealed the same way. -->
            <div class="password-wrapper">
                <input type="password" name="password" id="password" placeholder="ENter your password" required>
                <button type="button" id="togglePassword" class="toggle-btn">Show</button>
            </div>
        </div>

        <!-- NEW: Remember Me — ported from login.php -->
        <?php if ($hasValidRememberCookie): ?>
        <div class="remember-me-active-badge">
            <span class="badge-icon"><i class="fas fa-lock"></i></span>
            <span>A remembered session exists on this device &mdash; OTP will be skipped if it matches your account.</span>
        </div>
        <?php endif; ?>
        <div class="remember-me-row">
            <input type="checkbox" name="remember_me" id="rememberMe" value="1">
            <label class="rm-label" for="rememberMe">Remember me for today</label>
        </div>
        <p class="remember-me-hint">Skip OTP verification for the rest of the day on this device.</p>

        <button type="submit" name="login" class="login-submit">Login</button>

        <p class="footer-text">
            Don't have an account?
            <a href="company_register.php">Register</a>
        </p>
    </form>

    <?php elseif ($step == 2): ?>
    <!-- ════════════════════ STEP 2: OTP VERIFICATION (NEW) ════════════════════ -->
    <form action="company_login.php" method="POST">
        <p style="text-align:center;color:rgba(255,255,255,0.90);margin-bottom:6px;">
            Hello, <strong><?php echo htmlspecialchars($_SESSION['cl_first_name_temp'] ?? ''); ?></strong><br>
            Enter the OTP sent to your email.
        </p>

        <?php if ($showOtpRing): ?>
        <div class="otp-countdown-wrap">
            <div class="otp-countdown-ring">
                <svg viewBox="0 0 80 80" xmlns="http://www.w3.org/2000/svg">
                    <circle class="otp-ring-bg" cx="40" cy="40" r="33"/>
                    <circle class="otp-ring-fill" id="otpRingFill" cx="40" cy="40" r="33"/>
                </svg>
                <div class="otp-ring-time" id="otpTimeDisplay">5:00</div>
            </div>
            <span class="otp-countdown-label" id="otpCountdownLabel">OTP expires in</span>
            <div class="otp-expired-msg" id="otpExpiredMsg"><i class="fas fa-triangle-exclamation"></i> OTP expired &mdash; please resend</div>
        </div>
        <?php endif; ?>

        <div class="input-group">
            <label>Enter OTP</label>
            <div class="otp-box-group" id="otpBoxesCompany">
                <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-box" autocomplete="one-time-code">
                <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-box">
                <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-box">
                <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-box">
                <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-box">
                <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-box">
            </div>
            <input type="hidden" name="otp" id="otp_hidden_company">
        </div>
        <button type="submit" name="verify_otp" class="login-submit">Verify OTP</button>
        <button type="submit" name="send_otp" class="login-submit" style="margin-top:10px;">Send OTP</button>
        <button type="submit" name="otp_cancel" class="btn-secondary">&#8592; Back to Login</button>
    </form>

    <?php elseif ($step == 4): ?>
    <!-- ════════════════════ STEP FP1: FORGOT — ENTER EMAIL (NEW, self-contained) ════════════════════ -->
    <p style="text-align:center;color:rgba(255,255,255,0.75);font-size:14px;margin-bottom:20px;">
        Enter the email address associated with your company account and we'll send you an OTP to reset your password.
    </p>
    <?php if (!empty($fp_error)) echo "<p style='color:#fca5a5;text-align:center;background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.3);border-radius:10px;padding:10px;'>$fp_error</p>"; ?>
    <form action="company_login.php" method="POST">
    <div class="input-group">
        <label>Email Address</label>
        <input type="email" name="fp_email" placeholder="Enter your registered company email" class="fp-light-input"
            value="<?= htmlspecialchars($_POST['fp_email'] ?? '') ?>">
    </div>
    <button type="submit" name="fp_submit_email" class="login-submit">Send OTP</button>
    <button type="submit" name="fp_cancel" class="btn-secondary">&#8592; Back to Login</button>
    </form>

    <?php elseif ($step == 5): ?>
    <!-- ════════════════════ STEP FP2: FORGOT — VERIFY OTP (NEW, self-contained) ════════════════════ -->
    <p style="text-align:center;color:rgba(255,255,255,0.75);font-size:14px;margin-bottom:6px;">
        An OTP was sent to <strong style="color:#fff;"><?= htmlspecialchars($_SESSION['ccl_fp_email'] ?? '') ?></strong>.<br>
        Enter it below to reset your password.
    </p>
    <?php if (!empty($fp_error)) echo "<p style='color:#fca5a5;text-align:center;background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.3);border-radius:10px;padding:10px;'>$fp_error</p>"; ?>
    <?php if (!empty($success)) echo "<p style='color:#86efac;text-align:center;background:rgba(34,197,94,0.15);border:1px solid rgba(34,197,94,0.3);border-radius:10px;padding:10px;'>$success</p>"; ?>

    <?php if ($showFpOtpRing): ?>
    <div class="otp-countdown-wrap">
        <div class="otp-countdown-ring">
            <svg viewBox="0 0 80 80" xmlns="http://www.w3.org/2000/svg">
                <circle class="otp-ring-bg" cx="40" cy="40" r="33"/>
                <circle class="otp-ring-fill" id="fpOtpRingFill" cx="40" cy="40" r="33"/>
            </svg>
            <div class="otp-ring-time" id="fpOtpTimeDisplay">5:00</div>
        </div>
        <span class="otp-countdown-label" id="fpOtpCountdownLabel">OTP expires in</span>
        <div class="otp-expired-msg" id="fpOtpExpiredMsg"><i class="fas fa-triangle-exclamation"></i> OTP expired &mdash; please resend</div>
    </div>
    <?php endif; ?>

    <form action="company_login.php" method="POST">
    <div class="input-group">
        <label>Enter OTP</label>
        <div class="otp-box-group" id="otpBoxesCompanyFp">
            <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-box" autocomplete="one-time-code">
            <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-box">
            <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-box">
            <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-box">
            <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-box">
            <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-box">
        </div>
        <input type="hidden" name="fp_otp_input" id="fp_otp_hidden_company">
    </div>
    <button type="submit" name="fp_verify_otp" id="fpVerifyBtnCompany" class="login-submit">Verify &amp; Reset Password</button>
    <button type="submit" name="fp_resend_otp" class="btn-secondary">Resend OTP</button>
    <button type="submit" name="fp_cancel" class="btn-secondary">&#8592; Cancel</button>
    </form>

    <?php elseif ($step == 6): ?>
    <!-- ════════════════════ STEP FP3: SUCCESS (NEW, self-contained) ════════════════════ -->
    <p style="text-align:center;color:rgba(255,255,255,0.75);font-size:14px;">Redirecting&hellip;</p>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        document.getElementById('fpSuccessOverlay').style.display = 'flex';
    });
    </script>

    <?php elseif ($step == 8): ?>
    <!-- ════════════════════ STEP RE1: RECOVER EMAIL — FILL REQUEST FORM (NEW, self-contained, company-only) ════════════════════ -->
    <p style="text-align:center;color:#93c5fd;font-size:13px;font-weight:700;margin-bottom:16px;">
        <i class="fas fa-building"></i> Company Email Recovery Request
    </p>
    <?php if (!empty($re_error)) echo "<p style='color:#fca5a5;text-align:center;font-size:13px;margin-bottom:10px;background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.3);border-radius:10px;padding:10px;'>$re_error</p>"; ?>
    <form action="company_login.php" method="POST" id="reForm" style="max-height:68vh;overflow-y:auto;padding-right:4px;" onsubmit="return handleReFormSubmit(event)">
        <input type="hidden" name="re_selfie_data" id="reSelfieData">
        <input type="hidden" name="re_action" id="reAction" value="submit">

        <p class="re-section-title"><i class="fas fa-user"></i> Personal Details</p>
        <div class="input-group">
            <label>First Name *</label>
            <input type="text" name="re_first_name" placeholder="First Name" required
                value="<?= htmlspecialchars($_POST['re_first_name'] ?? '') ?>">
        </div>
        <div class="input-group">
            <label>Middle Name</label>
            <input type="text" name="re_middle_name" placeholder="Middle Name (leave blank if none)"
                value="<?= htmlspecialchars($_POST['re_middle_name'] ?? '') ?>">
        </div>
        <div class="input-group">
            <label>Last Name *</label>
            <input type="text" name="re_last_name" placeholder="Last Name" required
                value="<?= htmlspecialchars($_POST['re_last_name'] ?? '') ?>">
        </div>

        <div class="input-group">
            <label>Company Location / Address *</label>
            <input type="text" name="re_company_location" placeholder="Registered company address" required
                value="<?= htmlspecialchars($_POST['re_company_location'] ?? '') ?>">
        </div>

        <p class="re-section-title"><i class="fas fa-envelope"></i> Email Information</p>
        <div class="input-group">
            <label>Old Email Address *</label>
            <input type="email" name="re_old_email" placeholder="Your current registered email" required
                value="<?= htmlspecialchars($_POST['re_old_email'] ?? '') ?>">
        </div>
        <div class="input-group">
            <label>New Email Address *</label>
            <input type="email" name="re_new_email" placeholder="New email you want to use" required
                value="<?= htmlspecialchars($_POST['re_new_email'] ?? '') ?>">
        </div>
        <div class="input-group">
            <label>Reason *</label>
            <textarea name="re_reason" placeholder="Why is your email becoming unavailable?" required
                style="width:100%;padding:14px;border-radius:12px;border:1.5px solid rgba(255,255,255,0.60);background:rgba(255,255,255,0.92);color:#1e293b;font-size:14px;box-sizing:border-box;resize:vertical;min-height:80px;"><?= htmlspecialchars($_POST['re_reason'] ?? '') ?></textarea>
        </div>

        <p class="re-section-title"><i class="fas fa-camera"></i> Live Selfie Verification</p>
        <p class="re-camera-hint">Take a live photo of yourself for identity verification. Required.</p>
        <div class="re-camera-box" id="reCameraBox">
            <video id="reVideo" autoplay playsinline></video>
            <canvas id="reCanvas"></canvas>
        </div>
        <img id="reSelfiePreview" class="re-preview-img" alt="Selfie preview">
        <button type="button" class="re-snap-btn" id="reSnapBtn" onclick="takeSelfie()"><i class="fas fa-camera"></i> Take Photo</button>
        <button type="button" class="re-retake-btn" id="reRetakeBtn" onclick="retakeSelfie()"><i class="fas fa-rotate"></i> Retake</button>

        <button type="submit" name="re_submit_request" class="login-submit" id="reSubmitBtn" disabled
            style="margin-top:14px;background:#6366f1;">
            Submit Recovery Request
        </button>
        <button type="button" class="btn-secondary" onclick="cancelReForm()">&#8592; Cancel</button>
    </form>

    <?php elseif ($step == 9): ?>
    <!-- ════════════════════ STEP RE2: REQUEST SUBMITTED (NEW, self-contained) ════════════════════ -->
    <div style="text-align:center;padding:20px 0;">
        <span style="font-size:52px;display:block;margin-bottom:14px;"><i class="fas fa-paper-plane"></i></span>
        <p style="font-size:18px;font-weight:700;color:#93c5fd;margin:0 0 8px;">Request Submitted!</p>
        <p style="color:rgba(255,255,255,0.75);font-size:13px;line-height:1.6;margin:0 0 24px;">
            Your email recovery request has been sent to the administrator for review.<br>
            You will be notified once your request is approved.
        </p>
        <a href="company_login.php" style="display:inline-block;background:#0038a8;color:white;padding:13px 36px;border-radius:12px;font-weight:700;font-size:14px;text-decoration:none;">Back to Login</a>
    </div>
    <?php endif; ?>
</div>

<!-- ── PASSWORD CHANGED SUCCESS POPUP (NEW, self-contained forgot-password flow) ── -->
<div id="fpSuccessOverlay">
    <div id="fpSuccessBox">
        <span class="sp-icon"><i class="fas fa-lock"></i></span>
        <p class="sp-title">Password Successfully Changed!</p>
        <p class="sp-msg">Your new password has been sent to your email address. Please log in with your new password.</p>
        <button class="sp-btn" onclick="document.getElementById('fpSuccessOverlay').style.display='none'; window.location='company_login.php';">
            OK, Go to Login
        </button>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     CUSTOMER SERVICE FAB — this page now runs its own Forgot
     Password and Recover Email Address flows (see steps 4/5/6 and
     8/9 above), so both options post to company_login.php itself
     instead of handing the visitor off to the student login page.
     The main button has a native `title` tooltip.
══════════════════════════════════════════════════════════════ -->
<?php if ($step == 1): ?>
<div id="csFabMenu">
    <div class="cs-fab-options" id="csFabOptions">
        <form action="company_login.php" method="POST" style="margin:0;">
            <input type="hidden" name="fp_go" value="1">
            <button type="submit" class="cs-fab-option">
                <span class="cs-fab-option-icon"><i class="fas fa-key"></i></span>
                <span class="cs-fab-option-label">Forgot Password</span>
            </button>
        </form>
        <form action="company_login.php" method="POST" style="margin:0;">
            <input type="hidden" name="re_go" value="1">
            <button type="submit" class="cs-fab-option">
                <span class="cs-fab-option-icon"><i class="fas fa-envelope"></i></span>
                <span class="cs-fab-option-label">Recover Email Address</span>
            </button>
        </form>
    </div>
    <button type="button" class="cs-fab-main-btn" id="csFabMainBtn" onclick="toggleCsFabMenu()"
        title="Customer Service — Forgot Password / Recover Email Address">
        <i class="fas fa-headset" id="csFabIcon"></i>
    </button>
</div>
<?php endif; ?>

<script>
const rpType  = <?= json_encode($popup_type) ?>;
const rpTitle = <?= json_encode($popup_title) ?>;
const rpMsg   = <?= json_encode($popup_msg) ?>;

function rpClose() {
    document.getElementById('regPopupOverlay').style.display = 'none';
}

function rpGoRegister() {
    window.location.href = 'company_register.php';
}

function rpGoStudentLogin() {
    window.location.href = 'login.php';
}

// ============================================
// NEW: admin-portal redirect for the 'admin_redirect' popup type
// ------------------------------------------------------------
// Mirrors rpGoStudentLogin() above, but points to the dedicated
// Admin Portal instead of the student login page.
// ============================================
function rpGoAdminLogin() {
    window.location.href = 'admin_login.php';
}

document.addEventListener('DOMContentLoaded', function () {
    if (!rpType) return;

    // ============================================
    // FIX: MISSING POPUP ICONS
    // ------------------------------------------------------------
    // iconMap previously held empty strings for every type, so
    // #rpIcon rendered blank (see bug screenshot) even though the
    // matching popups on login.php / admin_login.php show an icon
    // above the title. Each type now maps to the same Font Awesome
    // icon used for its equivalent situation elsewhere in the
    // system (e.g. the graduation-cap used for "Student Account
    // Detected" on admin_login.php, the shield used for "Admin
    // Account Detected" on login.php), rendered via innerHTML since
    // these are icon markup, not plain text.
    // ============================================
    const iconMap = {
        error:             '<i class="fas fa-circle-exclamation"></i>',
        success:           '<i class="fas fa-circle-check"></i>',
        warning:           '<i class="fas fa-triangle-exclamation"></i>',
        confirm:           '<i class="fas fa-circle-question"></i>',
        student_redirect:  '<i class="fas fa-graduation-cap"></i>',
        admin_redirect:    '<i class="fas fa-user-shield"></i>'
    };

    const rpBtn   = document.getElementById('rpBtn');
    const rpBtnNo = document.getElementById('rpBtnNo');

    document.getElementById('rpIcon').innerHTML  = iconMap[rpType] || '';
    document.getElementById('rpTitle').textContent = rpTitle;
    document.getElementById('rpTitle').className   = 'rp-title ' + rpType;
    document.getElementById('rpMsg').textContent   = rpMsg;

    if (rpType === 'confirm') {
        // Two-button Yes / No mode
        rpBtn.textContent = 'Yes';
        rpBtn.className   = 'rp-btn confirm';
        rpBtn.onclick     = rpGoRegister;

        rpBtnNo.style.display = 'inline-block';
        rpBtnNo.onclick        = rpClose;
    } else if (rpType === 'student_redirect') {
        // OK sends the student to the main login page; Cancel just closes the popup
        rpBtn.textContent = 'OK';
        rpBtn.className   = 'rp-btn student_redirect';
        rpBtn.onclick      = rpGoStudentLogin;

        rpBtnNo.textContent    = 'Cancel';
        rpBtnNo.className      = 'rp-btn confirm-no';
        rpBtnNo.style.display  = 'inline-block';
        rpBtnNo.onclick        = rpClose;
    } else if (rpType === 'admin_redirect') {
        // NEW: OK sends the admin to the dedicated Admin Portal;
        // Cancel just closes the popup and keeps them on this page.
        rpBtn.textContent = 'OK';
        rpBtn.className   = 'rp-btn admin_redirect';
        rpBtn.onclick      = rpGoAdminLogin;

        rpBtnNo.textContent    = 'Cancel';
        rpBtnNo.className      = 'rp-btn confirm-no';
        rpBtnNo.style.display  = 'inline-block';
        rpBtnNo.onclick        = rpClose;
    } else {
        // Default single OK button mode (unchanged behavior)
        rpBtn.textContent = 'OK';
        rpBtn.className   = 'rp-btn ' + rpType;
        rpBtn.onclick      = rpClose;

        rpBtnNo.style.display = 'none';
    }

    document.getElementById('regPopupOverlay').style.display = 'flex';
});
</script>

<!-- ══════════════════════════════════════════════════════════════
     NEW: TOGGLE PASSWORD — ported from login.php so the login
     password field on this page can be shown/hidden the same way.
══════════════════════════════════════════════════════════════ -->
<script>
const togglePassword = document.querySelector('#togglePassword');
const password       = document.querySelector('#password');
if (togglePassword) {
    togglePassword.addEventListener('click', function () {
        const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
        password.setAttribute('type', type);
        this.textContent = type === 'password' ? 'Show' : 'Hide';
    });
}
</script>

<!-- ══════════════════════════════════════════════════════════════
     NEW: OTP COUNTDOWN TIMER — ported from login.php (single ring,
     since this page only has the login OTP, not a forgot-password one)
══════════════════════════════════════════════════════════════ -->
<script>
(function () {
    var CIRC = 207.35;

    function startCountdown(totalSeconds, ringId, displayId, labelId, expiredId) {
        var ring    = document.getElementById(ringId);
        var display = document.getElementById(displayId);
        var label   = document.getElementById(labelId);
        var expired = document.getElementById(expiredId);

        if (!ring || !display) return;

        if (totalSeconds <= 0) {
            display.textContent = '0:00';
            ring.style.strokeDashoffset = CIRC;
            ring.style.stroke = '#f87171';
            if (label)   label.style.display   = 'none';
            if (expired) expired.style.display = 'block';
            return;
        }

        var remaining = totalSeconds;

        function tick() {
            var m = Math.floor(remaining / 60);
            var s = remaining % 60;
            display.textContent = m + ':' + (s < 10 ? '0' : '') + s;

            var progress = remaining / 300;
            ring.style.strokeDashoffset = CIRC * (1 - progress);

            if (remaining > 120) {
                ring.style.stroke = '#38bdf8';
            } else if (remaining > 60) {
                ring.style.stroke = '#fb923c';
            } else {
                ring.style.stroke = '#f87171';
            }

            if (remaining <= 0) {
                display.textContent = '0:00';
                ring.style.strokeDashoffset = CIRC;
                ring.style.stroke = '#f87171';
                if (label)   label.style.display   = 'none';
                if (expired) expired.style.display = 'block';
                return;
            }

            remaining--;
            setTimeout(tick, 1000);
        }

        tick();
    }

    var loginSecs = <?= (int)$otpSecondsLeft ?>;
    if (loginSecs > 0 && document.getElementById('otpRingFill')) {
        startCountdown(loginSecs, 'otpRingFill', 'otpTimeDisplay', 'otpCountdownLabel', 'otpExpiredMsg');
    }

    // NEW: forgot-password OTP ring (self-contained flow, step 5)
    var fpSecs = <?= (int)$fpOtpSecondsLeft ?>;
    if (fpSecs > 0 && document.getElementById('fpOtpRingFill')) {
        startCountdown(fpSecs, 'fpOtpRingFill', 'fpOtpTimeDisplay', 'fpOtpCountdownLabel', 'fpOtpExpiredMsg');
    }
})();
</script>

<!-- ══════════════════════════════════════════════════════════════
     NEW: OTP BOX INPUT BEHAVIOR
     ------------------------------------------------------------
     Wires up the six-box OTP field added above for step 2: typing a
     digit auto-advances to the next box, Backspace on an empty box
     moves back, pasting a full code distributes it across all boxes,
     and every keystroke keeps the hidden input (name="otp") in sync
     so the existing PHP handler ($_POST['otp']) keeps working
     completely unchanged.
══════════════════════════════════════════════════════════════ -->
<script>
function setupOtpBoxes(groupId, hiddenId) {
    var group = document.getElementById(groupId);
    if (!group) return;
    var hidden = document.getElementById(hiddenId);
    var boxes  = Array.prototype.slice.call(group.querySelectorAll('.otp-box'));

    function syncHidden() {
        if (hidden) hidden.value = boxes.map(function (b) { return b.value; }).join('');
    }

    boxes.forEach(function (box, idx) {
        box.addEventListener('input', function () {
            box.value = box.value.replace(/[^0-9]/g, '').slice(-1);
            if (box.value) {
                box.classList.add('otp-box-filled');
                if (idx < boxes.length - 1) boxes[idx + 1].focus();
            } else {
                box.classList.remove('otp-box-filled');
            }
            syncHidden();
        });

        box.addEventListener('keydown', function (e) {
            if (e.key === 'Backspace' && !box.value && idx > 0) {
                boxes[idx - 1].focus();
            }
        });

        box.addEventListener('paste', function (e) {
            e.preventDefault();
            var text = (e.clipboardData || window.clipboardData).getData('text').replace(/[^0-9]/g, '');
            if (!text) return;
            for (var i = 0; i < boxes.length; i++) {
                boxes[i].value = text[i] || '';
                if (boxes[i].value) boxes[i].classList.add('otp-box-filled');
                else boxes[i].classList.remove('otp-box-filled');
            }
            syncHidden();
            var nextEmptyIdx = boxes.findIndex(function (b) { return !b.value; });
            (nextEmptyIdx === -1 ? boxes[boxes.length - 1] : boxes[nextEmptyIdx]).focus();
        });
    });

    syncHidden();
}

document.addEventListener('DOMContentLoaded', function () {
    setupOtpBoxes('otpBoxesCompany', 'otp_hidden_company');

    // NEW: self-contained forgot-password OTP box (step 5)
    setupOtpBoxes('otpBoxesCompanyFp', 'fp_otp_hidden_company');

    // Preserves the original required-field behavior of a plain OTP
    // text input: block the "Verify & Reset Password" submit if the
    // six boxes haven't all been filled in yet.
    var fpVerifyBtn = document.getElementById('fpVerifyBtnCompany');
    var fpHidden     = document.getElementById('fp_otp_hidden_company');
    if (fpVerifyBtn && fpHidden) {
        fpVerifyBtn.addEventListener('click', function (e) {
            if (!fpHidden.value || fpHidden.value.length < 6) {
                e.preventDefault();
                var fpGroup = document.getElementById('otpBoxesCompanyFp');
                var firstEmpty = fpGroup ? fpGroup.querySelector('.otp-box:not(.otp-box-filled)') : null;
                if (firstEmpty) firstEmpty.focus();
                alert('Please enter the complete 6-digit OTP.');
            }
        });
    }
});
</script>

<!-- ══════════════════════════════════════════════════════════════
     CUSTOMER SERVICE FAB TOGGLE — ported from login.php.
     Swaps the headset icon for an X (fa-xmark) while the menu is
     open, and restores it when closed (via the main button, or the
     click-outside-to-close handler below).
══════════════════════════════════════════════════════════════ -->
<script>
function setCsFabIconOpen(isOpen) {
    var icon = document.getElementById('csFabIcon');
    if (!icon) return;
    if (isOpen) {
        icon.classList.remove('fa-headset');
        icon.classList.add('fa-xmark');
    } else {
        icon.classList.remove('fa-xmark');
        icon.classList.add('fa-headset');
    }
}

function toggleCsFabMenu() {
    var fab = document.getElementById('csFabMenu');
    if (!fab) return;
    var isOpen = fab.classList.toggle('open');
    setCsFabIconOpen(isOpen);
}

document.addEventListener('click', function (e) {
    var fab = document.getElementById('csFabMenu');
    if (fab && fab.classList.contains('open') && !fab.contains(e.target)) {
        fab.classList.remove('open');
        setCsFabIconOpen(false);
    }
});
</script>

<!-- ══════════════════════════════════════════════════════════════
     PERSISTENT SIDEBAR COLLAPSE/EXPAND TOGGLE — ported from
     login.php. Binds #sidebarToggleBtn to toggle the sidebar's own
     `.collapsed` class (80px icon rail) together with the body's
     `.sidebar-is-collapsed` class, which shrinks the page's reserved
     left padding to match (see the `body.sidebar-is-collapsed` CSS
     rule above).
══════════════════════════════════════════════════════════════ -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    var sidebarToggleBtn = document.getElementById('sidebarToggleBtn');
    var sidebarEl        = document.getElementById('sidebar');
    if (sidebarToggleBtn && sidebarEl) {

        // ── SIDEBAR STATE SYNC — restore saved state ──────────────
        // Applies the real .collapsed/.sidebar-is-collapsed classes (not
        // just the anti-flash CSS from <head>) so the toggle button's
        // classList.toggle() below starts from the correct state and
        // stays correct on every click after this.
        try {
            if (localStorage.getItem('neustSidebarCollapsed') === '1') {
                sidebarEl.classList.add('collapsed');
                document.body.classList.add('sidebar-is-collapsed');
            }
        } catch (e) { /* localStorage unavailable — falls back to default expanded state */ }

        // ── FIX: SIDEBAR TOGGLE STUCK AFTER PAGE SWITCH ──────────────
        // The anti-flash inline script in <head> adds `sb-pref-collapsed`
        // to <html> before this script runs, and that class's CSS rules
        // (e.g. `html.sb-pref-collapsed .sidebar { width: 80px; }`) are
        // MORE specific than `.sidebar.collapsed { width: 80px; }`, so it
        // was never actually removed anywhere. That meant: collapse the
        // sidebar, navigate to a new page, and the toggle button's click
        // handler below would correctly remove `.collapsed` from the
        // sidebar in JS — but the page stayed visually collapsed anyway
        // because `sb-pref-collapsed` on <html> kept forcing the 80px
        // width regardless. Removing it here, now that the real
        // `.collapsed` / `.sidebar-is-collapsed` classes above have taken
        // over as the single source of truth, lets the toggle button
        // correctly expand (and re-collapse) the sidebar on every page,
        // every time — without touching the anti-flash behavior itself,
        // which has already done its job by this point (DOM is ready).
        document.documentElement.classList.remove('sb-pref-collapsed');

        sidebarToggleBtn.addEventListener('click', function () {
            sidebarEl.classList.toggle('collapsed');
            document.body.classList.toggle('sidebar-is-collapsed');
            // ── SIDEBAR STATE SYNC — persist so login.php (and
            // this page, next visit) opens with the same state. ──
            try {
                localStorage.setItem('neustSidebarCollapsed', sidebarEl.classList.contains('collapsed') ? '1' : '0');
            } catch (e) { /* localStorage unavailable — state just won't persist */ }
        });
    }
});
</script>

<!-- ══════════════════════════════════════════════════════════════
     NEW: RECOVER EMAIL — LIVE SELFIE SCRIPTS
     ------------------------------------------------------------
     Ported from login.php so this page's own self-contained email
     recovery request form (step 8 above) can capture and submit a
     live selfie exactly the same way the student login page does.
══════════════════════════════════════════════════════════════ -->
<script>
var reStream = null;
var reSelfieCapture = false;

function startReCamera() {
    var video = document.getElementById('reVideo');
    if (!video) return;
    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false })
        .then(function(stream) {
            reStream = stream;
            video.srcObject = stream;
        })
        .catch(function() {
            var hint = document.querySelector('.re-camera-hint');
            if (hint) hint.textContent = 'Camera access denied. Please allow camera access and reload.';
            var snap = document.getElementById('reSnapBtn');
            if (snap) snap.disabled = true;
        });
}

function takeSelfie() {
    var video     = document.getElementById('reVideo');
    var canvas    = document.getElementById('reCanvas');
    var preview   = document.getElementById('reSelfiePreview');
    var dataInput = document.getElementById('reSelfieData');
    var submitBtn = document.getElementById('reSubmitBtn');

    canvas.width  = video.videoWidth  || 640;
    canvas.height = video.videoHeight || 480;
    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);

    var dataUrl = canvas.toDataURL('image/jpeg', 0.85);
    preview.src = dataUrl;
    preview.style.display = 'block';
    dataInput.value = dataUrl;

    document.getElementById('reSnapBtn').style.display   = 'none';
    document.getElementById('reRetakeBtn').style.display = 'block';
    document.getElementById('reCameraBox').style.display = 'none';

    reSelfieCapture = true;
    submitBtn.disabled = false;

    if (reStream) { reStream.getTracks().forEach(function(t){ t.stop(); }); reStream = null; }
}

function retakeSelfie() {
    var preview   = document.getElementById('reSelfiePreview');
    var submitBtn = document.getElementById('reSubmitBtn');

    preview.style.display = 'none';
    preview.src = '';
    document.getElementById('reSelfieData').value = '';
    document.getElementById('reSnapBtn').style.display   = 'block';
    document.getElementById('reRetakeBtn').style.display = 'none';
    document.getElementById('reCameraBox').style.display = 'block';

    reSelfieCapture = false;
    submitBtn.disabled = true;
    startReCamera();
}

function validateSelfie() {
    if (!reSelfieCapture || !document.getElementById('reSelfieData').value) {
        alert('Please take a selfie photo before submitting.');
        return false;
    }
    return true;
}

function handleReFormSubmit(e) {
    var action = document.getElementById('reAction') ? document.getElementById('reAction').value : 'submit';
    if (action === 'cancel') return true;
    return validateSelfie();
}

function cancelReForm() {
    if (reStream) { reStream.getTracks().forEach(function(t){ t.stop(); }); reStream = null; }

    // Cancel via a real POST to re_cancel so the server resets cl_step
    // back to 1 (mirrors this page's own POST-based cancel pattern,
    // rather than login.php's GET ?re_cancel=1 link).
    var f = document.createElement('form');
    f.method = 'POST';
    f.action = 'company_login.php';
    var i = document.createElement('input');
    i.type = 'hidden';
    i.name = 're_cancel';
    i.value = '1';
    f.appendChild(i);
    document.body.appendChild(f);
    f.submit();
}

document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('reVideo')) startReCamera();
});
</script>

</body>
</html>