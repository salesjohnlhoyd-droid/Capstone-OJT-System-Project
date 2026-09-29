<?php
ini_set('log_errors', 1);
ini_set('error_log', 'C:/xampp/tmp/php_errors.log');
session_start();
// ── NEW (this adjustment): the browser must never re-use an old copy of this page (Back button),
// so the login form it shows always carries a fresh one-time token (see adminLoginNonceOk()).
if (($_SERVER['HTTP_SEC_FETCH_DEST'] ?? 'document') === 'document' && !headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Sat, 01 Jan 2000 00:00:00 GMT');
}
include "db.php";

require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require 'PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ============================================
// NEW (this adjustment): ADMIN LOGOUT  (admin_login.php?logout=1)
// ------------------------------------------------------------
// The sidebar "Logout" button on every admin page now comes here. Before,
// it only opened login.php, which never ended the admin's session — so the
// admin was not really logged out and the browser's Back arrow went straight
// back into the admin pages.
//   • Everything the login put in the session is cleared and the session id
//     is renewed, so every admin page now sends this visitor back here.
//   • "Remember me for today" keeps working: its saved token (kept in this
//     same session, see setRememberMeCookie()) and its cookie are left in
//     place, so signing in again today still skips the OTP step.
//   • Having just logged out on purpose, the admin is NOT signed straight
//     back in by the remember-me auto-login below (admin_logged_out flag);
//     they sign in again with their email + password (OTP still skipped).
//   • Redirects to a clean admin_login.php, so refreshing the login page
//     never repeats the logout.
// ============================================
if (isset($_GET['logout'])) {
    $keepRememberTokens = $_SESSION['admin_remember_tokens'] ?? null;
    $_SESSION = [];
    session_regenerate_id(true);
    if ($keepRememberTokens) $_SESSION['admin_remember_tokens'] = $keepRememberTokens;
    $_SESSION['admin_logged_out'] = true;
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Location: admin_login.php');
    exit();
}

// ============================================
// Lazy migration — is_active column check
// ------------------------------------------------------------
// Same defensive/lazy-migration pattern used in login.php /
// monitoring.php, so this page can safely SELECT `is_active`
// from `admins` and `users` regardless of whether monitoring.php
// has already added the column on this deployment.
// ============================================
function ensureIsActiveColumn(mysqli $conn, string $table): void {
    static $checked = [];
    if (!empty($checked[$table])) return;
    $checked[$table] = true;

    $col_check = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE 'is_active'");
    if ($col_check && $col_check->num_rows === 0) {
        $conn->query("ALTER TABLE `{$table}` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1");
    }
}
ensureIsActiveColumn($conn, 'admins');
ensureIsActiveColumn($conn, 'users');

// ============================================
// NEW: BRANDED EMAIL TEMPLATE HELPER
// ------------------------------------------------------------
// Ported over from login.php so every automated email sent by this
// admin portal (OTP + password reset) shares the exact same look as
// the student/company portal emails: a dark navy/gold "NEUST OJT
// Portal" header, a colored status banner under it (blue for OTP,
// green for a completed reset), a white content card, and a light
// gray automated-message footer.
//
// This is purely a presentation helper: it does not touch SMTP
// config, session handling, OTP generation/validation, or any other
// logic. $bannerBg/$bannerColor pick the banner's theme, $bannerIcon
// is a small emoji/glyph, $bannerText is the banner headline, and
// $bodyHtml is the inner content (greeting, message, code/password
// box, etc.) each calling function builds for itself.
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

// ================= OTP EMAIL FUNCTION =================
// ── UPDATED: Subject/Body redesigned to use the shared branded
// template above (friendlier tone, code shown in a highlighted box),
// matching login.php's OTP email exactly. SMTP setup, debug mode,
// error handling, and everything else about how this function is
// called/used remains completely unchanged. ──
function sendOTPEmail($toEmail, $otp) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();

        $mail->SMTPDebug = 2;
        $mail->Debugoutput = function($str, $level) {
            $_SESSION['smtp_debug'][] = $str;
        };

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

        unset($_SESSION['smtp_error'], $_SESSION['smtp_debug']);
        return true;

    } catch (Exception $e) {
        $_SESSION['smtp_error'] = $mail->ErrorInfo;
        return false;
    }
}

// ================= NEW PASSWORD EMAIL FUNCTION =================
// ── UPDATED: Subject/Body redesigned to match the same branded
// template as the OTP email above (consistent header/footer, a green
// "success" banner, and a friendlier tone), matching login.php's
// password-reset email exactly. SMTP setup, DB update, and everything
// about when/how this function is called remains completely
// unchanged. ──
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
                Your password was just reset for your NEUST OJT Portal admin account. Here's your new
                temporary password &mdash; please use it to log in and set a new one of your own as soon as you can.
            </p>

            <div style='background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:22px;text-align:center;margin:0 0 22px;'>
                <p style='margin:0 0 8px;color:#64748b;font-size:11px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;'>Your Temporary Password</p>
                <p style='margin:0;color:#0038a8;font-size:26px;font-weight:800;letter-spacing:3px;'>$newPassword</p>
            </div>

            <p style='margin:0 0 22px;color:#64748b;font-size:13px;line-height:1.6;font-style:italic;'>
                Didn't request this reset? Please contact the system owner right away so your account can be secured.
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

// ================= GENERATE RANDOM PASSWORD =================
function generateRandomPassword($length = 6) {
    return str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
}

// ================= REMEMBER ME HELPERS =================
// NOTE: Uses its own cookie name ("admin_remember_token") and its own
// session bucket ("admin_remember_tokens") so an admin's "remember me"
// on this page never collides with — or gets silently consumed/cleared
// by — the student/company remember-me cookies set on login.php /
// company_login.php.
function setRememberMeCookie($userId, $role, $firstName) {
    $token   = bin2hex(random_bytes(32));
    $expires = mktime(23, 59, 59, date('n'), date('j'), date('Y'));
    if (!isset($_SESSION['admin_remember_tokens'])) $_SESSION['admin_remember_tokens'] = [];
    $_SESSION['admin_remember_tokens'][$token] = [
        'user_id'    => $userId,
        'role'       => $role,
        'first_name' => $firstName,
        'expires'    => $expires,
    ];
    setcookie('admin_remember_token', $token, [
        'expires'  => $expires,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function checkRememberMeCookie($forUserId = null) {
    if (empty($_COOKIE['admin_remember_token'])) return false;
    $token = $_COOKIE['admin_remember_token'];
    if (!isset($_SESSION['admin_remember_tokens'][$token])) return false;
    $data = $_SESSION['admin_remember_tokens'][$token];
    if (time() > $data['expires']) {
        unset($_SESSION['admin_remember_tokens'][$token]);
        setcookie('admin_remember_token', '', time() - 3600, '/');
        return false;
    }
    if ($forUserId !== null && (int)$data['user_id'] !== (int)$forUserId) {
        return false;
    }
    return $data;
}

function clearRememberMeCookie() {
    if (!empty($_COOKIE['admin_remember_token'])) {
        $token = $_COOKIE['admin_remember_token'];
        if (isset($_SESSION['admin_remember_tokens'][$token])) {
            unset($_SESSION['admin_remember_tokens'][$token]);
        }
        setcookie('admin_remember_token', '', time() - 3600, '/');
    }
}

// ── AUTO-LOGIN via Remember Me cookie (GET page loads only) ──────────────────
// Only ever auto-logs an admin in. This page has no concept of a
// remembered student/company — those cookies are scoped to login.php /
// company_login.php and are never read here.
if (
    empty($_SESSION['admin_id']) &&
    empty($_SESSION['fp_step']) &&
    empty($_SESSION['admin_logged_out']) &&   // NEW (this adjustment): not right after the admin chose to log out
    $_SERVER['REQUEST_METHOD'] === 'GET'
) {
    $remembered = checkRememberMeCookie();
    if ($remembered) {
        $remembered_active = 1;
        $ra_stmt = $conn->prepare("SELECT is_active FROM `admins` WHERE id = ?");
        if ($ra_stmt) {
            $ra_stmt->bind_param("i", $remembered['user_id']);
            $ra_stmt->execute();
            $ra_row = $ra_stmt->get_result()->fetch_assoc();
            $ra_stmt->close();
            if ($ra_row && isset($ra_row['is_active'])) {
                $remembered_active = (int)$ra_row['is_active'];
            }
        }

        if ($remembered_active === 0) {
            // Account was deactivated since the cookie was issued — clear it
            // and let them hit the normal login form (where the deactivated
            // popup will show once they try to sign in with credentials).
            clearRememberMeCookie();
        } else {
            session_regenerate_id(true);
            // NEW: clear any leftover student/company session data (e.g. from
            // a prior login.php / company_login.php session on this browser)
            // so administrator.php never mistakes this for a student session.
            unset($_SESSION['user_id'], $_SESSION['user_id_temp'], $_SESSION['role_temp']);
            $_SESSION['admin_id']   = $remembered['user_id'];
            $_SESSION['first_name'] = $remembered['first_name'];
            $_SESSION['role']       = 'admin';
            header("Location:administrator.php");
            exit();
        }
    }
}

$step = 1;
// ── Generic "wrong portal" redirect popup state ──────────────────────────
// Replaces login.php's single $showCompanyRedirectPopup with a role-aware
// version: this page detects BOTH student and company accounts attempting
// to log in here and offers to send them to their own portal instead.
$showRoleRedirectPopup = false;
$redirectRole   = '';   // 'student' | 'company'
$redirectTarget = '';   // the portal URL to offer
$redirectLabel  = '';   // human-readable label for the popup copy

// ── "Account Deactivated" popup state ──
$showDeactivatedPopup = false;

// ============================================
// Generic login notification popup state
// (Missing Fields / Account Not Found / Incorrect Password)
// ============================================
$popup_type  = '';
$popup_title = '';
$popup_msg   = '';

if (isset($_SESSION['fp_step'])) $step = $_SESSION['fp_step'];

// ============================================
// NEW (this adjustment): ONE-TIME LOGIN FORM TOKEN
// ------------------------------------------------------------
// Problem it fixes: after logging out, pressing the browser's Back arrow can
// reach the history entry of the earlier login form submission. The browser
// then offers to "resend" it (Confirm Form Resubmission / Reload) — and that
// resent email + password used to sign the admin straight back in (with
// "Remember me" active even the OTP was skipped), without typing anything.
// Now every login form carries a random token that is valid for ONE
// submission only, and logging out wipes it. A resent / stale form is refused
// with a "Please Sign In Again" popup, so the admin has to enter their email
// and password again. After that the normal rules apply unchanged:
// "Remember me" active -> straight in (OTP skipped); otherwise -> OTP step.
// ============================================
function adminLoginNonceOk() {
    global $popup_type, $popup_title, $popup_msg, $step;
    $posted = (string)($_POST['login_nonce'] ?? '');
    $ok = !empty($_SESSION['admin_login_nonce']) && hash_equals((string)$_SESSION['admin_login_nonce'], $posted);
    unset($_SESSION['admin_login_nonce']);          // one use only — a fresh one is issued when the page is shown
    if (!$ok) {
        $popup_type  = 'error';
        $popup_title = 'Please Sign In Again';
        $popup_msg   = 'This sign-in form is no longer valid (for example after logging out or using the Back button). Please enter your email and password again.';
        $step = 1;
    }
    return $ok;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // ================= STEP 1 LOGIN =================
    if (isset($_POST['login']) && adminLoginNonceOk()) {   // UPDATED (this adjustment): one-time form token
        unset($_SESSION['fp_step'], $_SESSION['fp_email'],
              $_SESSION['fp_otp'],  $_SESSION['fp_otp_time'],
              $_SESSION['fp_otp_count'], $_SESSION['fp_cooldown'],
              $_SESSION['fp_first_name']);

        $email      = trim($_POST['email']);
        $password   = trim($_POST['password']);
        $rememberMe = isset($_POST['remember_me']);
        $user       = null;
        $role       = null;

        if ($email === '' || $password === '') {
            $popup_type  = 'error';
            $popup_title = 'Missing Fields';
            $popup_msg   = 'Please enter both your email and password.';
        } else {

            // Admin accounts live in `admins` — this portal's own table,
            // so it is checked first.
            $stmt = $conn->prepare("SELECT id, first_name, password, is_active FROM admins WHERE email = ?");
            $stmt->bind_param("s", $email); $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) { $user = $result->fetch_assoc(); $role = "admin"; }
            $stmt->close();

            // ── NEW: if no admin matches, check `users` too — this is what
            // lets the page detect a student or company account being used
            // here by mistake, so it can offer to redirect them instead of
            // just saying "Account Not Found". ──
            if (!$user) {
                $stmt = $conn->prepare("SELECT id, first_name, password, role, is_active FROM users WHERE email = ?");
                $stmt->bind_param("s", $email); $stmt->execute();
                $result = $stmt->get_result();
                if ($result->num_rows > 0) {
                    $user = $result->fetch_assoc();
                    $role = ($user['role'] == "company") ? "company" : "student";
                }
                $stmt->close();
            }

            if ($user) {
                if (password_verify($password, $user['password'])) {

                    if ($role !== 'admin') {
                        // ============================================
                        // NEW: ROLE-BASED WRONG-PORTAL REDIRECT
                        // ------------------------------------------------------------
                        // A student or company account with valid credentials was
                        // used on the admin portal. Instead of an "Incorrect
                        // Password" / "Account Not Found" message, show a popup
                        // offering to send them to the portal that actually belongs
                        // to them: students -> login.php, companies -> company_login.php.
                        // Mirrors the $showCompanyRedirectPopup pattern from
                        // login.php, generalized to cover both roles here.
                        // ============================================
                        $showRoleRedirectPopup = true;
                        $redirectRole = $role;
                        if ($role === 'student') {
                            $redirectTarget = 'login.php';
                            $redirectLabel  = 'Student Portal';
                        } else {
                            $redirectTarget = 'company_login.php';
                            $redirectLabel  = 'Company Portal';
                        }
                        $step = 1;
                    } elseif (isset($user['is_active']) && (int)$user['is_active'] === 0) {
                        // ============================================
                        // DEACTIVATED ADMIN ACCOUNT CHECK
                        // ------------------------------------------------------------
                        // Mirrors the is_active flag toggled elsewhere in the
                        // system. A deactivated admin account is blocked from
                        // signing in and shown a popup instead of proceeding
                        // to the OTP step.
                        // ============================================
                        $showDeactivatedPopup = true;
                        $step = 1;
                    } else {

                        $existingRemember = checkRememberMeCookie($user['id']);
                        if ($existingRemember) {
                            session_regenerate_id(true);
                            $_SESSION['admin_id']   = $user['id'];
                            $_SESSION['first_name'] = $user['first_name'];
                            $_SESSION['role']       = 'admin';
                            setRememberMeCookie($user['id'], 'admin', $user['first_name']);
                            unset($_SESSION['otp_email'], $_SESSION['admin_id_temp'],
                                  $_SESSION['first_name_temp'],
                                  $_SESSION['remember_me_pending'],
                                  $_SESSION['otp_send_count'], $_SESSION['otp_cooldown'],
                                  // NEW: clear any leftover student/company session data
                                  // so administrator.php never mistakes this for a student session.
                                  $_SESSION['user_id'], $_SESSION['user_id_temp'], $_SESSION['role_temp']);
                            header("Location:administrator.php");
                            exit();
                        }

                        $_SESSION['otp_email']          = $email;
                        $_SESSION['admin_id_temp']       = $user['id'];
                        $_SESSION['first_name_temp']     = $user['first_name'];
                        $_SESSION['remember_me_pending'] = $rememberMe;

                        $step = 2;
                    }
                } else {
                    $popup_type  = 'error';
                    $popup_title = 'Incorrect Password';
                    $popup_msg   = 'The password you entered is incorrect. Please try again.';
                }
            } else {
                $popup_type  = 'error';
                $popup_title = 'Account Not Found';
                $popup_msg   = 'No account was found with that email address. Please check and try again.';
            }
        }
    }

    // ================= SEND OTP =================
    if (isset($_POST['send_otp'])) {
        if (!isset($_SESSION['otp_email'])) {
            $error = "Please login first."; $step = 1;
        } else {
            $current_time = time();
            if (!isset($_SESSION['otp_send_count'])) $_SESSION['otp_send_count'] = 0;

            if (isset($_SESSION['otp_cooldown']) && $current_time < $_SESSION['otp_cooldown']) {
                $remaining = $_SESSION['otp_cooldown'] - $current_time;
                $error = "OTP cooldown active. Please wait " . ceil($remaining / 60) . " minute(s).";
                $step = 2;
            } else {
                if (isset($_SESSION['otp_cooldown']) && $current_time >= $_SESSION['otp_cooldown']) {
                    unset($_SESSION['otp_cooldown']); $_SESSION['otp_send_count'] = 0;
                }
                if ($_SESSION['otp_send_count'] >= 3) {
                    $_SESSION['otp_cooldown'] = $current_time + 180;
                    $error = "You have reached the maximum OTP attempts. Please wait 3 minutes.";
                    $step = 2;
                } else {
                    $otp = random_int(100000, 999999);
                    $_SESSION['otp']      = $otp;
                    $_SESSION['otp_time'] = $current_time;
                    if (sendOTPEmail($_SESSION['otp_email'], $otp)) {
                        $_SESSION['otp_send_count'] += 1;
                        $success = "OTP has been sent to your email. Valid for 5 minutes.";
                    } else {
                        $error = "Failed to send OTP. " . ($_SESSION['smtp_error'] ?? 'Check SMTP settings.');
                    }
                    $step = 2;
                }
            }
        }
    }

    // ================= VERIFY OTP =================
    if (isset($_POST['verify_otp'])) {
        if (!isset($_SESSION['otp_email'])) {
            $error = "Please login first."; $step = 1;
        } elseif (!isset($_SESSION['otp'])) {
            $error = "OTP not generated. Click 'Send OTP'."; $step = 2;
        } else {
            $entered_otp  = trim($_POST['otp']);
            $current_time = time();
            if (($current_time - $_SESSION['otp_time']) > 300) {
                $error = "OTP expired. Please resend.";
                unset($_SESSION['otp'], $_SESSION['otp_time']); $step = 2;
            } elseif ($entered_otp == $_SESSION['otp']) {
                $rememberMe = $_SESSION['remember_me_pending'] ?? false;
                session_regenerate_id(true);
                $_SESSION['admin_id']   = $_SESSION['admin_id_temp'];
                $_SESSION['first_name'] = $_SESSION['first_name_temp'];
                $_SESSION['role']       = 'admin';

                if ($rememberMe) {
                    setRememberMeCookie($_SESSION['admin_id'], 'admin', $_SESSION['first_name']);
                }

                unset($_SESSION['otp'], $_SESSION['otp_time'], $_SESSION['otp_email'],
                      $_SESSION['admin_id_temp'], $_SESSION['first_name_temp'],
                      $_SESSION['otp_send_count'], $_SESSION['otp_cooldown'],
                      $_SESSION['remember_me_pending'],
                      // NEW: clear any leftover student/company session data (e.g.
                      // from a prior login.php / company_login.php session in this
                      // browser) so administrator.php never mistakes this admin
                      // session for a student one and redirects to the wrong portal.
                      $_SESSION['user_id'], $_SESSION['user_id_temp'], $_SESSION['role_temp']);

                header("Location:administrator.php");
                exit();
            } else {
                $error = "Invalid OTP. Please try again."; $step = 2;
            }
        }
    }

    // ================= CANCEL OTP — return to login (step 1) =================
    if (isset($_POST['otp_cancel'])) {
        unset($_SESSION['otp_email'], $_SESSION['otp'], $_SESSION['otp_time'],
              $_SESSION['admin_id_temp'], $_SESSION['first_name_temp'],
              $_SESSION['remember_me_pending'], $_SESSION['otp_send_count'], $_SESSION['otp_cooldown']);
        $step = 1;
    }

    // ================= FORGOT PASSWORD — FP1: SUBMIT EMAIL =================
    // Restricted to the `admins` table only — this portal only resets
    // admin passwords, unlike login.php's version which also checks
    // `users`.
    if (isset($_POST['fp_submit_email'])) {
        $fp_email = trim($_POST['fp_email']);
        $fp_user  = null;

        $stmt = $conn->prepare("SELECT id, first_name FROM admins WHERE email = ?");
        $stmt->bind_param("s", $fp_email); $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if ($row) { $fp_user = $row; }

        if (!$fp_user) {
            $fp_error = "No admin account found with that email address.";
            $step = 4; $_SESSION['fp_step'] = 4;
        } else {
            $_SESSION['fp_email']      = $fp_email;
            $_SESSION['fp_first_name'] = $fp_user['first_name'];
            $_SESSION['fp_otp_count']  = 0;
            unset($_SESSION['fp_cooldown']);

            $otp = random_int(100000, 999999);
            $_SESSION['fp_otp']      = $otp;
            $_SESSION['fp_otp_time'] = time();
            if (sendOTPEmail($fp_email, $otp)) {
                $_SESSION['fp_otp_count'] = 1;
                $success = "OTP sent to " . htmlspecialchars($fp_email) . ". Valid for 5 minutes.";
                $step = 5; $_SESSION['fp_step'] = 5;
            } else {
                $fp_error = "Failed to send OTP. Please try again.";
                $step = 4; $_SESSION['fp_step'] = 4;
            }
        }
    }

    // ================= FORGOT PASSWORD — FP2: RESEND OTP =================
    if (isset($_POST['fp_resend_otp'])) {
        if (!isset($_SESSION['fp_email'])) {
            $step = 4; $_SESSION['fp_step'] = 4;
        } else {
            $current_time = time();
            if (!isset($_SESSION['fp_otp_count'])) $_SESSION['fp_otp_count'] = 0;

            if (isset($_SESSION['fp_cooldown']) && $current_time < $_SESSION['fp_cooldown']) {
                $remaining = $_SESSION['fp_cooldown'] - $current_time;
                $fp_error  = "Please wait " . ceil($remaining / 60) . " minute(s) before resending.";
                $step = 5; $_SESSION['fp_step'] = 5;
            } elseif ($_SESSION['fp_otp_count'] >= 3) {
                $_SESSION['fp_cooldown'] = $current_time + 180;
                $fp_error = "Maximum OTP attempts reached. Please wait 3 minutes.";
                $step = 5; $_SESSION['fp_step'] = 5;
            } else {
                $otp = random_int(100000, 999999);
                $_SESSION['fp_otp']      = $otp;
                $_SESSION['fp_otp_time'] = $current_time;
                if (sendOTPEmail($_SESSION['fp_email'], $otp)) {
                    $_SESSION['fp_otp_count'] += 1;
                    $success = "OTP resent to " . htmlspecialchars($_SESSION['fp_email']) . ".";
                } else {
                    $fp_error = "Failed to resend OTP. Please try again.";
                }
                $step = 5; $_SESSION['fp_step'] = 5;
            }
        }
    }

    // ================= FORGOT PASSWORD — FP3: VERIFY OTP & RESET =================
    if (isset($_POST['fp_verify_otp'])) {
        if (!isset($_SESSION['fp_email']) || !isset($_SESSION['fp_otp'])) {
            $fp_error = "Session expired. Please start over.";
            $step = 4; $_SESSION['fp_step'] = 4;
        } else {
            $entered      = trim($_POST['fp_otp_input']);
            $current_time = time();

            if (($current_time - $_SESSION['fp_otp_time']) > 300) {
                $fp_error = "OTP expired. Please resend.";
                unset($_SESSION['fp_otp'], $_SESSION['fp_otp_time']);
                $step = 5; $_SESSION['fp_step'] = 5;
            } elseif ($entered == $_SESSION['fp_otp']) {
                $newPass    = generateRandomPassword(10);
                $hashedPass = password_hash($newPass, PASSWORD_DEFAULT);
                $fp_email   = $_SESSION['fp_email'];
                $fp_name    = $_SESSION['fp_first_name'];

                $stmt = $conn->prepare("UPDATE `admins` SET password = ? WHERE email = ?");
                $stmt->bind_param("ss", $hashedPass, $fp_email);
                $stmt->execute();
                $stmt->close();

                sendNewPasswordEmail($fp_email, $fp_name, $newPass);

                unset($_SESSION['fp_step'], $_SESSION['fp_email'],
                      $_SESSION['fp_otp'],  $_SESSION['fp_otp_time'],
                      $_SESSION['fp_otp_count'], $_SESSION['fp_cooldown'],
                      $_SESSION['fp_first_name']);

                $step = 6;
            } else {
                $fp_error = "Invalid OTP. Please try again.";
                $step = 5; $_SESSION['fp_step'] = 5;
            }
        }
    }

    if (isset($_POST['fp_cancel'])) {
        unset($_SESSION['fp_step'], $_SESSION['fp_email'],
              $_SESSION['fp_otp'],  $_SESSION['fp_otp_time'],
              $_SESSION['fp_otp_count'], $_SESSION['fp_cooldown'],
              $_SESSION['fp_first_name']);
        $step = 1;
    }

    if (isset($_POST['fp_go'])) {
        unset($_SESSION['fp_step'], $_SESSION['fp_email'],
              $_SESSION['fp_otp'],  $_SESSION['fp_otp_time'],
              $_SESSION['fp_otp_count'], $_SESSION['fp_cooldown'],
              $_SESSION['fp_first_name']);
        $_SESSION['fp_step'] = 4;
        header("Location: admin_login.php");
        exit();
    }
}

// NEW (this adjustment): fresh one-time token for the login form shown below (see adminLoginNonceOk())
if (empty($_SESSION['admin_login_nonce'])) $_SESSION['admin_login_nonce'] = bin2hex(random_bytes(16));

$conn->close();

// ── Compute OTP seconds remaining ────────────────────────────────────────────
$otpSecondsLeft   = 0;
$fpOtpSecondsLeft = 0;

if ($step == 2 && isset($_SESSION['otp_time'])) {
    $otpSecondsLeft = max(0, 300 - (time() - $_SESSION['otp_time']));
}
if ($step == 5 && isset($_SESSION['fp_otp_time'])) {
    $fpOtpSecondsLeft = max(0, 300 - (time() - $_SESSION['fp_otp_time']));
}

$showOtpRing   = ($step == 2 && isset($_SESSION['otp_time'])    && $otpSecondsLeft > 0);
$showFpOtpRing = ($step == 5 && isset($_SESSION['fp_otp_time']) && $fpOtpSecondsLeft > 0);

$hasValidRememberCookie = (bool) checkRememberMeCookie();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Login | NEUST OJT Portal</title>
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

html, body { margin: 0; padding: 0; min-height: 100%; }

body {
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    gap: 16px;
    font-family: 'Plus Jakarta Sans', sans-serif;
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
    padding-left: 260px;
    transition: padding-left 0.3s ease;
}
body.sidebar-is-collapsed { padding-left: 80px; }

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

body::after {
    content: '';
    position: fixed;
    inset: 0;
    background: url("photo/OIP.webp") no-repeat center top / cover;
    opacity: 0.55;
    pointer-events: none;
    z-index: 1;
}

body > * { position: relative; z-index: 2; }

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

.logo-container img { max-width: 100px; margin-bottom: 10px; }
h2 { color: #ffffff; margin-bottom: 25px; font-size: 26px; }
.input-group { text-align: left; margin-bottom: 18px; }

label {
    display: block; font-size: 12px; font-weight: 700;
    color: rgba(255, 255, 255, 0.75); margin-bottom: 6px; text-transform: uppercase;
}

input {
    width: 100%; padding: 14px; border-radius: 12px;
    border: 1.5px solid rgba(255, 255, 255, 0.25);
    background: rgba(255, 255, 255, 0.12);
    color: #ffffff;
    box-sizing: border-box; font-size: 14px;
}
input::placeholder { color: rgba(255, 255, 255, 0.50); }
input:focus {
    outline: none;
    border-color: rgba(255,255,255,0.55);
    background: rgba(255,255,255,0.18);
}

.password-wrapper { position: relative; display: flex; align-items: center; }

.toggle-btn {
    position: absolute; right: 15px; background: none; border: none;
    color: #93c5fd; font-weight: 700; font-size: 11px;
    cursor: pointer; text-transform: uppercase;
}

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

.otp-countdown-wrap { display: flex; flex-direction: column; align-items: center; gap: 6px; margin: 4px 0 18px; }
.otp-countdown-ring { position: relative; width: 80px; height: 80px; }
.otp-countdown-ring svg { transform: rotate(-90deg); width: 80px; height: 80px; display: block; }
.otp-ring-bg { fill: none; stroke: rgba(255,255,255,0.12); stroke-width: 6; }
.otp-ring-fill {
    fill: none; stroke: #38bdf8; stroke-width: 6; stroke-linecap: round;
    stroke-dasharray: 207.35; stroke-dashoffset: 0;
    transition: stroke-dashoffset 1s linear, stroke 0.5s ease;
}
.otp-ring-time {
    position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
    font-size: 16px; font-weight: 700; color: #ffffff; letter-spacing: -0.5px;
}
.otp-countdown-label { font-size: 11px; color: rgba(255,255,255,0.55); font-weight: 600; letter-spacing: 0.2px; text-transform: uppercase; }
.otp-expired-msg {
    display: none; font-size: 12px; color: #fca5a5; font-weight: 600; text-align: center;
    background: rgba(239,68,68,0.15); border: 1px solid rgba(239,68,68,0.35);
    border-radius: 8px; padding: 6px 12px; margin-top: 2px;
}

.otp-box-group { display: flex; justify-content: center; gap: 9px; margin: 0 0 4px; }
.otp-box {
    width: 42px; height: 52px; padding: 0; text-align: center; font-size: 20px; font-weight: 700;
    font-family: 'Plus Jakarta Sans', sans-serif; border-radius: 12px;
    border: 1.5px solid rgba(255,255,255,0.45); background: rgba(255,255,255,0.90);
    color: #1e293b; box-sizing: border-box; outline: none; caret-color: #0038a8;
    transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease, transform 0.15s ease;
    -moz-appearance: textfield;
}
.otp-box::-webkit-outer-spin-button, .otp-box::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
.otp-box::placeholder { color: #94a3b8; }
.otp-box:focus {
    border-color: #38bdf8; background: #ffffff;
    box-shadow: 0 0 0 4px rgba(56,189,248,0.28); transform: translateY(-1px);
}
.otp-box.otp-box-filled { border-color: #93c5fd; }

.login-submit {
    width: 100%; padding: 16px; background: rgba(255, 255, 255, 0.92);
    color: #0038a8; border: none; border-radius: 12px;
    font-weight: 700; font-size: 15px; cursor: pointer;
    margin-top: 10px; transition: 0.3s;
}
.login-submit:hover { background: #ffffff; transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,0,0,0.25); }

.footer-text { margin-top: 20px; font-size: 14px; color: rgba(255, 255, 255, 0.80); }
.footer-text a { color: #ffffff; text-decoration: none; font-weight: 700; }

.btn-secondary {
    width: 100%; padding: 13px; background: rgba(255,255,255,0.12);
    color: rgba(255,255,255,0.80); border: 1.5px solid rgba(255,255,255,0.25);
    border-radius: 12px; font-weight: 600; font-size: 14px; cursor: pointer;
    margin-top: 10px; transition: 0.2s;
}
.btn-secondary:hover { background: rgba(255,255,255,0.20); color: #ffffff; }

#fpSuccessOverlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,0.55); z-index: 9999;
    justify-content: center; align-items: center;
    backdrop-filter: blur(4px); animation: fadeIn 0.25s ease;
}
@keyframes fadeIn { from{opacity:0;} to{opacity:1;} }
#fpSuccessBox {
    background: white; border-radius: 20px; padding: 40px 36px;
    width: 340px; max-width: 92%; text-align: center;
    box-shadow: 0 20px 60px rgba(0,0,0,0.25);
    animation: popIn 0.3s cubic-bezier(0.34,1.56,0.64,1);
}
@keyframes popIn { from{transform:scale(0.85);opacity:0;} to{transform:scale(1);opacity:1;} }
#fpSuccessBox .sp-icon  { font-size: 52px; display: block; margin-bottom: 14px; }
#fpSuccessBox .sp-title { font-size: 18px; font-weight: 700; color: #0038a8; margin: 0 0 8px; }
#fpSuccessBox .sp-msg   { font-size: 13px; color: #64748b; margin: 0 0 24px; line-height: 1.6; }
#fpSuccessBox .sp-btn {
    padding: 13px 36px; background: #0038a8; color: white;
    border: none; border-radius: 12px; font-weight: 700;
    font-size: 14px; cursor: pointer; transition: 0.2s;
}
#fpSuccessBox .sp-btn:hover { background: #002d86; }

/* ── ROLE-BASED WRONG-PORTAL REDIRECT POPUP ── */
/* Generalized version of login.php's #companyRedirectOverlay — shown for
   BOTH student and company accounts detected on this admin-only page. */
#roleRedirectOverlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,0.55); z-index: 9999;
    justify-content: center; align-items: center;
    backdrop-filter: blur(4px); animation: fadeIn 0.25s ease;
}
#roleRedirectBox {
    background: white; border-radius: 20px; padding: 40px 36px;
    width: 360px; max-width: 92%; text-align: center;
    box-shadow: 0 20px 60px rgba(0,0,0,0.25);
    animation: popIn 0.3s cubic-bezier(0.34,1.56,0.64,1);
}
#roleRedirectBox .sp-icon  { font-size: 52px; display: block; margin-bottom: 14px; }
#roleRedirectBox .sp-title { font-size: 18px; font-weight: 700; color: #0038a8; margin: 0 0 8px; }
#roleRedirectBox .sp-msg   { font-size: 13px; color: #64748b; margin: 0 0 24px; line-height: 1.6; }
#roleRedirectBox .sp-btn-row { display: flex; gap: 10px; justify-content: center; }
#roleRedirectBox .sp-btn {
    padding: 13px 24px; background: #0038a8; color: white;
    border: none; border-radius: 12px; font-weight: 700;
    font-size: 14px; cursor: pointer; transition: 0.2s; flex: 1;
}
#roleRedirectBox .sp-btn:hover { background: #002d86; }
#roleRedirectBox .sp-btn-cancel {
    padding: 13px 24px; background: #e2e8f0; color: #334155;
    border: none; border-radius: 12px; font-weight: 700;
    font-size: 14px; cursor: pointer; transition: 0.2s; flex: 1;
}
#roleRedirectBox .sp-btn-cancel:hover { background: #cbd5e1; }

#deactivatedOverlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,0.55); z-index: 9999;
    justify-content: center; align-items: center;
    backdrop-filter: blur(4px); animation: fadeIn 0.25s ease;
}
#deactivatedBox {
    background: white; border-radius: 20px; padding: 40px 36px;
    width: 360px; max-width: 92%; text-align: center;
    box-shadow: 0 20px 60px rgba(0,0,0,0.25);
    animation: popIn 0.3s cubic-bezier(0.34,1.56,0.64,1);
}
#deactivatedBox .sp-icon  { font-size: 52px; display: block; margin-bottom: 14px; }
#deactivatedBox .sp-title { font-size: 18px; font-weight: 700; color: #dc2626; margin: 0 0 8px; }
#deactivatedBox .sp-msg   { font-size: 13px; color: #64748b; margin: 0 0 24px; line-height: 1.6; }
#deactivatedBox .sp-btn {
    padding: 13px 36px; background: #dc2626; color: white;
    border: none; border-radius: 12px; font-weight: 700;
    font-size: 14px; cursor: pointer; transition: 0.2s;
}
#deactivatedBox .sp-btn:hover { background: #b91c1c; }

#loginNotifyOverlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,0.55); z-index: 9999;
    justify-content: center; align-items: center;
    backdrop-filter: blur(4px); animation: fadeIn 0.25s ease;
}
#loginNotifyBox {
    background: white; border-radius: 20px; padding: 40px 36px;
    width: 340px; max-width: 92%; text-align: center;
    box-shadow: 0 20px 60px rgba(0,0,0,0.25);
    animation: popIn 0.3s cubic-bezier(0.34,1.56,0.64,1);
}
#loginNotifyBox .ln-icon  { font-size: 52px; display: block; margin-bottom: 14px; }
#loginNotifyBox .ln-title { font-size: 18px; font-weight: 700; color: #dc2626; margin: 0 0 8px; }
#loginNotifyBox .ln-msg   { font-size: 13px; color: #64748b; margin: 0 0 24px; line-height: 1.6; }
#loginNotifyBox .ln-btn {
    padding: 13px 36px; background: #dc2626; color: white;
    border: none; border-radius: 12px; font-weight: 700;
    font-size: 14px; cursor: pointer; transition: 0.2s;
}
#loginNotifyBox .ln-btn:hover { background: #b91c1c; }

.fp-light-input {
    background: rgba(255,255,255,0.92) !important;
    color: #1e293b !important;
    border: 1.5px solid rgba(255,255,255,0.60) !important;
}
.fp-light-input::placeholder { color: #94a3b8 !important; }

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

.sidebar-links { flex: 1; display: flex; flex-direction: column; padding: 10px 0; overflow: hidden; }
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
.sidebar a i { width: 30px; font-size: 18px; margin-right: 15px; text-align: center; flex-shrink: 0; }
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

.sidebar-toggle-btn { background: transparent; border: none; color: white; cursor: pointer; font-size: 20px; outline: none; flex-shrink: 0; }

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
     SIDEBAR — Home / Student Portal / Company Portal / Admin Portal
     Same persistent, collapsible sidebar pattern as login.php and
     company_login.php, with "Admin Portal" (this page) marked active.
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
        <a href="company_login.php">
            <i class="fas fa-building"></i>
            <span class="link-text">Company Portal</span>
        </a>
        <a href="admin_login.php" class="active">
            <i class="fas fa-user-shield"></i>
            <span class="link-text">Admin Portal</span>
        </a>
    </div>
</div>

<!-- ── PASSWORD CHANGED SUCCESS POPUP ── -->
<div id="fpSuccessOverlay">
    <div id="fpSuccessBox">
        <span class="sp-icon"><i class="fas fa-lock"></i></span>
        <p class="sp-title">Password Successfully Changed!</p>
        <p class="sp-msg">Your new password has been sent to your email address. Please log in with your new password.</p>
        <button class="sp-btn" onclick="document.getElementById('fpSuccessOverlay').style.display='none'; window.location='admin_login.php';">
            OK, Go to Login
        </button>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     ROLE-BASED WRONG-PORTAL REDIRECT POPUP
     Shown when a valid student or company account signs in here.
     Offers to send them to their own portal instead of an admin one.
══════════════════════════════════════════════════════════════ -->
<div id="roleRedirectOverlay">
    <div id="roleRedirectBox">
        <span class="sp-icon" id="roleRedirectIcon"></span>
        <p class="sp-title" id="roleRedirectTitle"></p>
        <p class="sp-msg" id="roleRedirectMsg"></p>
        <div class="sp-btn-row">
            <button class="sp-btn" id="roleRedirectGoBtn">OK</button>
            <button class="sp-btn-cancel" onclick="document.getElementById('roleRedirectOverlay').style.display='none';">Cancel</button>
        </div>
    </div>
</div>
<?php if (!empty($showRoleRedirectPopup)): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var role   = <?= json_encode($redirectRole) ?>;
    var target = <?= json_encode($redirectTarget) ?>;
    var label  = <?= json_encode($redirectLabel) ?>;

    var iconEl  = document.getElementById('roleRedirectIcon');
    var titleEl = document.getElementById('roleRedirectTitle');
    var msgEl   = document.getElementById('roleRedirectMsg');
    var goBtn   = document.getElementById('roleRedirectGoBtn');

    if (role === 'student') {
        iconEl.innerHTML = '<i class="fas fa-user-graduate"></i>';
        titleEl.textContent = 'Student Account Detected';
    } else {
        iconEl.innerHTML = '<i class="fas fa-building"></i>';
        titleEl.textContent = 'Company Account Detected';
    }
    msgEl.textContent = 'This is the Admin Portal. Your account belongs to the ' + label + '. Would you like to be redirected there instead?';
    goBtn.onclick = function () { window.location = target; };

    document.getElementById('roleRedirectOverlay').style.display = 'flex';
});
</script>
<?php endif; ?>

<!-- ── ACCOUNT DEACTIVATED POPUP ── -->
<div id="deactivatedOverlay">
    <div id="deactivatedBox">
        <span class="sp-icon"><i class="fas fa-ban"></i></span>
        <p class="sp-title">Account Deactivated</p>
        <p class="sp-msg">Your admin account has been deactivated and can no longer log in. Please contact the system owner for assistance.</p>
        <button class="sp-btn" onclick="document.getElementById('deactivatedOverlay').style.display='none';">OK</button>
    </div>
</div>
<?php if (!empty($showDeactivatedPopup)): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('deactivatedOverlay');
    if (el) el.style.display = 'flex';
});
</script>
<?php endif; ?>

<!-- ── GENERIC LOGIN NOTIFICATION POPUP ── -->
<div id="loginNotifyOverlay">
    <div id="loginNotifyBox">
        <span class="ln-icon" id="lnIcon"><i class="fas fa-triangle-exclamation"></i></span>
        <p class="ln-title" id="lnTitle"></p>
        <p class="ln-msg" id="lnMsg"></p>
        <button class="ln-btn" id="lnBtn" onclick="document.getElementById('loginNotifyOverlay').style.display='none';">OK</button>
    </div>
</div>
<?php if (!empty($popup_type)): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.getElementById('lnTitle').textContent = <?= json_encode($popup_title) ?>;
    document.getElementById('lnMsg').textContent   = <?= json_encode($popup_msg) ?>;
    document.getElementById('loginNotifyOverlay').style.display = 'flex';
});
</script>
<?php endif; ?>

<!-- ── HEADER ── -->
<div style="display:flex; align-items:center; gap:18px;">
    <img src="logo.webp" alt="System Logo" style="width:72px; height:72px; border-radius:50%; object-fit:contain; box-shadow:0 4px 16px rgba(0,0,0,0.4);">
    <div style="border-left: 3px solid rgba(255,255,255,0.4); padding-left: 16px;">
        <p style="margin:0 0 2px; font-size:11px; font-weight:600; color:rgba(255,255,255,0.6); text-transform:uppercase; letter-spacing:1.5px;">NEUST Atate Campus</p>
        <p style="margin:0; font-size:18px; font-weight:700; color:#ffffff; line-height:1.3;">Web-Based Smart OJT<br>Monitoring and Supervision<br>Analytics System</p>
    </div>
</div>

<div class="card">


<?php if (!empty($error))    echo "<p style='color:#fca5a5;text-align:center;background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.3);border-radius:10px;padding:10px;'>$error</p>"; ?>
<?php
if (!empty($_SESSION['smtp_debug'])) {
    echo "<div style='background:#111;color:#0f0;padding:10px;font-size:11px;text-align:left;max-height:150px;overflow:auto;border-radius:8px;'>";
    echo "<strong>SMTP Debug:</strong><br>";
    foreach ($_SESSION['smtp_debug'] as $line) {
        echo htmlspecialchars($line) . "<br>";
    }
    echo "</div>";
}
?>
<?php if (!empty($success))  echo "<p style='color:#86efac;text-align:center;background:rgba(34,197,94,0.15);border:1px solid rgba(34,197,94,0.3);border-radius:10px;padding:10px;'>$success</p>"; ?>
<?php if (!empty($fp_error)) echo "<p style='color:#fca5a5;text-align:center;background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.3);border-radius:10px;padding:10px;'>$fp_error</p>"; ?>

<?php if ($step == 1): ?>
<!-- ════════════════════ STEP 1: LOGIN ════════════════════ -->
<form action="admin_login.php" method="POST">
<input type="hidden" name="login_nonce" value="<?= htmlspecialchars($_SESSION['admin_login_nonce'] ?? '') ?>"><!-- NEW (this adjustment): one-time form token -->
<div class="input-group">
    <label>Email</label>
    <input type="email" name="email" placeholder="Enter your admin email" required>
</div>
<div class="input-group">
    <label>Password</label>
    <div class="password-wrapper">
        <input type="password" name="password" id="password" placeholder="Enter your password" required>
        <button type="button" id="togglePassword" class="toggle-btn">Show</button>
    </div>
</div>

<?php if ($hasValidRememberCookie): ?>
<div class="remember-me-active-badge">
    <span class="badge-icon"><i class="fas fa-lock"></i></span>
    <span>A remembered admin session exists on this device &mdash; OTP will be skipped if it matches your account.</span>
</div>
<?php endif; ?>
<div class="remember-me-row">
    <input type="checkbox" name="remember_me" id="rememberMe" value="1">
    <label class="rm-label" for="rememberMe">Remember me for today</label>
</div>
<p class="remember-me-hint">Skip OTP verification for the rest of the day on this device.</p>

<button type="submit" name="login" class="login-submit">Login</button>
<p class="footer-text">Not an admin? Use the Student or Company portal from the sidebar.</p>
</form>

<?php elseif ($step == 2): ?>
<!-- ════════════════════ STEP 2: LOGIN OTP ════════════════════ -->
<form action="admin_login.php" method="POST">
<p style="text-align:center;color:rgba(255,255,255,0.90);margin-bottom:6px;">
    Hello, <strong><?php echo htmlspecialchars($_SESSION['first_name_temp']); ?></strong><br>
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
    <div class="otp-box-group" id="otpBoxesLogin">
        <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-box" autocomplete="one-time-code">
        <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-box">
        <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-box">
        <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-box">
        <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-box">
        <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-box">
    </div>
    <input type="hidden" name="otp" id="otp_hidden_login">
</div>
<button type="submit" name="verify_otp" class="login-submit">Verify OTP</button>
<button type="submit" name="send_otp" class="login-submit" style="margin-top:10px;">Send OTP</button>
<button type="submit" name="otp_cancel" class="btn-secondary">&#8592; Back to Login</button>
</form>

<?php elseif ($step == 4): ?>
<!-- ════════════════════ STEP FP1: FORGOT — ENTER EMAIL ════════════════════ -->
<p style="text-align:center;color:rgba(255,255,255,0.75);font-size:14px;margin-bottom:20px;">
    Enter the admin email address associated with your account and we'll send you an OTP to reset your password.
</p>
<form action="admin_login.php" method="POST">
<div class="input-group">
    <label>Email Address</label>
    <input type="email" name="fp_email" placeholder="Enter your registered admin email" class="fp-light-input"
        value="<?= htmlspecialchars($_POST['fp_email'] ?? '') ?>">
</div>
<button type="submit" name="fp_submit_email" class="login-submit">Send OTP</button>
<button type="submit" name="fp_cancel" class="btn-secondary">&#8592; Back to Login</button>
</form>

<?php elseif ($step == 5): ?>
<!-- ════════════════════ STEP FP2: FORGOT — VERIFY OTP ════════════════════ -->
<p style="text-align:center;color:rgba(255,255,255,0.75);font-size:14px;margin-bottom:6px;">
    An OTP was sent to <strong style="color:#fff;"><?= htmlspecialchars($_SESSION['fp_email'] ?? '') ?></strong>.<br>
    Enter it below to reset your password.
</p>

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

<form action="admin_login.php" method="POST" id="fpOtpForm">
<div class="input-group">
    <label>Enter OTP</label>
    <div class="otp-box-group" id="otpBoxesFp">
        <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-box" autocomplete="one-time-code">
        <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-box">
        <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-box">
        <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-box">
        <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-box">
        <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-box">
    </div>
    <input type="hidden" name="fp_otp_input" id="fp_otp_hidden">
</div>
<button type="submit" name="fp_verify_otp" id="fpVerifyBtn" class="login-submit">Verify &amp; Reset Password</button>
<button type="submit" name="fp_resend_otp" class="btn-secondary">Resend OTP</button>
<button type="submit" name="fp_cancel" class="btn-secondary">&#8592; Cancel</button>
</form>

<?php elseif ($step == 6): ?>
<!-- ════════════════════ STEP FP3: SUCCESS ════════════════════ -->
<p style="text-align:center;color:rgba(255,255,255,0.75);font-size:14px;">Redirecting&hellip;</p>
<script>
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('fpSuccessOverlay').style.display = 'flex';
});
</script>

<?php endif; ?>

</div><!-- .card -->

<!-- ══════════════════════════════════════════════════════════════
     CUSTOMER SERVICE FAB — forgot password entry point only.
     No email-recovery option here since that flow is student/company
     specific (deployment/company/course lookups) and doesn't apply
     to admin accounts.
══════════════════════════════════════════════════════════════ -->
<?php if ($step == 1): ?>
<div id="csFabMenu">
    <div class="cs-fab-options" id="csFabOptions">
        <form action="admin_login.php" method="POST" style="margin:0;">
            <input type="hidden" name="fp_go" value="1">
            <button type="submit" class="cs-fab-option">
                <span class="cs-fab-option-icon"><i class="fas fa-key"></i></span>
                <span class="cs-fab-option-label">Forgot Password</span>
            </button>
        </form>
    </div>
    <button type="button" class="cs-fab-main-btn" id="csFabMainBtn" onclick="toggleCsFabMenu()"
        title="Customer Service — Forgot Password">
        <i class="fas fa-headset" id="csFabIcon"></i>
    </button>
</div>
<?php endif; ?>

<!-- ── TOGGLE PASSWORD ── -->
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

<!-- ── OTP COUNTDOWN TIMERS ── -->
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

    var fpSecs = <?= (int)$fpOtpSecondsLeft ?>;
    if (fpSecs > 0 && document.getElementById('fpOtpRingFill')) {
        startCountdown(fpSecs, 'fpOtpRingFill', 'fpOtpTimeDisplay', 'fpOtpCountdownLabel', 'fpOtpExpiredMsg');
    }
})();
</script>

<!-- ── OTP BOX INPUT BEHAVIOR ── -->
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
    setupOtpBoxes('otpBoxesLogin', 'otp_hidden_login');
    setupOtpBoxes('otpBoxesFp', 'fp_otp_hidden');

    var fpVerifyBtn = document.getElementById('fpVerifyBtn');
    var fpHidden     = document.getElementById('fp_otp_hidden');
    if (fpVerifyBtn && fpHidden) {
        fpVerifyBtn.addEventListener('click', function (e) {
            if (!fpHidden.value || fpHidden.value.length < 6) {
                e.preventDefault();
                var fpGroup = document.getElementById('otpBoxesFp');
                var firstEmpty = fpGroup ? fpGroup.querySelector('.otp-box:not(.otp-box-filled)') : null;
                if (firstEmpty) firstEmpty.focus();
                alert('Please enter the complete 6-digit OTP.');
            }
        });
    }
});
</script>

<!-- ── CUSTOMER SERVICE FAB TOGGLE ── -->
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

<!-- ── PERSISTENT SIDEBAR COLLAPSE/EXPAND TOGGLE ── -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    var sidebarToggleBtn = document.getElementById('sidebarToggleBtn');
    var sidebarEl        = document.getElementById('sidebar');
    if (sidebarToggleBtn && sidebarEl) {

        try {
            if (localStorage.getItem('neustSidebarCollapsed') === '1') {
                sidebarEl.classList.add('collapsed');
                document.body.classList.add('sidebar-is-collapsed');
            }
        } catch (e) { /* localStorage unavailable — falls back to default expanded state */ }

        document.documentElement.classList.remove('sb-pref-collapsed');

        sidebarToggleBtn.addEventListener('click', function () {
            sidebarEl.classList.toggle('collapsed');
            document.body.classList.toggle('sidebar-is-collapsed');
            try {
                localStorage.setItem('neustSidebarCollapsed', sidebarEl.classList.contains('collapsed') ? '1' : '0');
            } catch (e) { /* localStorage unavailable — state just won't persist */ }
        });
    }
});
</script>
<!-- NEW (this adjustment): if the browser shows this login page from its back / forward memory, reload it
     from the server so the form carries a fresh one-time token (an old copy would be refused). -->
<script>
window.addEventListener('pageshow', function (e) { if (e.persisted) window.location.reload(); });
</script>
</body>
</html>