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
// NEW: Lazy migration — is_active column check
// ------------------------------------------------------------
// Mirrors the same defensive/lazy-migration pattern already used in
// monitoring.php (ensureStatusColumn / ensureAdminExtraColumns) so
// login.php can safely SELECT `is_active` from `users` and `admins`
// regardless of whether monitoring.php has already been visited on
// this deployment (which is what actually adds the column). If the
// column already exists, this is a harmless no-op SHOW COLUMNS check.
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
ensureIsActiveColumn($conn, 'users');
ensureIsActiveColumn($conn, 'admins');

// ============================================
// NEW: BRANDED EMAIL TEMPLATE HELPER
// ------------------------------------------------------------
// Shared HTML wrapper used by BOTH sendOTPEmail() and
// sendNewPasswordEmail() so every automated email sent by this file
// shares one consistent look: a dark navy/gold "NEUST OJT Portal"
// header (matching the portal's navy / gold brand
// colors already used elsewhere on this page), a colored status
// banner under it (blue for OTP, green for a completed reset), a
// white content card with a friendlier, more conversational tone,
// and a light-gray automated-message footer — the same structural
// pattern shown in the "Application Not Approved" reference design.
//
// This is purely a presentation helper: it does not touch SMTP
// config, session handling, OTP generation/validation, or any other
// logic. $bannerBg/$bannerColor pick the banner's theme, $bannerIcon
// is a small emoji/glyph, $bannerText is the banner headline, and
// $bodyHtml is the inner content (greeting, message, code/password
// box, etc.) each calling function builds for itself.
// ============================================
function buildBrandedEmailTemplate($bannerColor, $bannerBg, $bannerIcon, $bannerText, $bodyHtml) {
    // UPDATED (this adjustment): the status banner is only added when a banner
    // text is given. The OTP and new-password emails no longer pass one (their
    // "Your One-Time Password" / "Password Reset Successful" banners and emoji
    // icons were removed), so those emails go straight from header to message.
    $bannerHtml = '';
    if (trim((string)$bannerText) !== '') {
        $bannerHtml = "<div style=\"background:{$bannerBg};padding:16px 20px;text-align:center;border-bottom:1px solid rgba(0,0,0,0.06);\">"
                    . "<p style=\"margin:0;color:{$bannerColor};font-size:16px;font-weight:700;\">" . trim($bannerIcon . ' ' . $bannerText) . "</p>"
                    . "</div>";
    }
    return "
    <div style=\"font-family:'Segoe UI',Arial,Helvetica,sans-serif;background:#eef1f8;padding:30px 10px;\">
      <div style=\"max-width:560px;margin:0 auto;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 6px 24px rgba(0,0,0,0.08);border:1px solid #e2e8f0;\">

        <!-- Header -->
        <div style=\"background:linear-gradient(135deg,#0a1454 0%,#132a8c 55%,#1a237e 100%);padding:30px 20px;text-align:center;\">
          <p style=\"margin:0 0 6px;color:#FFD700;font-size:22px;font-weight:800;letter-spacing:0.5px;\">NEUST OJT Portal</p>
          <p style=\"margin:0;color:rgba(255,255,255,0.75);font-size:13px;\">Atate Campus &mdash; On the Job Training System</p>
        </div>

        {$bannerHtml}

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
// template above (friendlier tone, code shown in a highlighted box).
// SMTP setup, debug mode, error handling, and everything else about
// how this function is called/used remains completely unchanged. ──
function sendOTPEmail($toEmail, $otp) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();

        // TEMP DEBUG MODE (safe to remove later)
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
            '',   // UPDATED: no emoji icon
            '',   // UPDATED: "Your One-Time Password" banner removed
            $otpBody
        );

        $mail->send();

        // clear debug if success
        unset($_SESSION['smtp_error'], $_SESSION['smtp_debug']);

        return true;

    } catch (Exception $e) {
        // STORE REAL ERROR
        $_SESSION['smtp_error'] = $mail->ErrorInfo;
        return false;
    }
}

// ================= NEW PASSWORD EMAIL FUNCTION =================
// ── UPDATED: Subject/Body redesigned to match the same branded
// template as the OTP email above (consistent header/footer, a green
// "success" banner, and a friendlier tone), so both automated emails
// now look and read like they come from the same system. The SMTP
// setup, DB update, and everything about when/how this function is
// called remains completely unchanged. ──
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
                password &mdash; please use it to log in to your account.
            </p>

            <div style='background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:22px;text-align:center;margin:0 0 22px;'>
                <p style='margin:0 0 8px;color:#64748b;font-size:11px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;'>Your New Password</p>
                <p style='margin:0;color:#0038a8;font-size:26px;font-weight:800;letter-spacing:3px;'>$newPassword</p>
            </div>

            <p style='margin:0 0 22px;color:#64748b;font-size:13px;line-height:1.6;font-style:italic;'>
                Didn't request this reset? Please contact your school administrator right away so your account can be secured.
            </p>
        ";

        $mail->Body = buildBrandedEmailTemplate(
            '#065f46',
            '#ecfdf5',
            '',   // UPDATED: no emoji icon
            '',   // UPDATED: "Password Reset Successful" banner removed
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

// ── AUTO-LOGIN via Remember Me cookie (GET page loads only) ──────────────────
// ── UPDATED: now also re-verifies the remembered account's `is_active`
// status against the DB before auto-logging them in. If the account was
// deactivated (e.g. by an admin from monitoring.php) since the cookie was
// set, the stale remember-me cookie is cleared and the visitor falls
// through to a normal login screen instead of being silently signed in. ──
if (
    empty($_SESSION['user_id']) &&
    empty($_SESSION['fp_step']) &&
    empty($_SESSION['re_step']) &&
    $_SERVER['REQUEST_METHOD'] === 'GET'
) {
    $remembered = checkRememberMeCookie();
    if ($remembered) {
        if ($remembered['role'] === 'company') {
            // Company accounts are no longer allowed to use this portal.
            // Clear the stale remember-me cookie so they fall through to a normal login screen.
            clearRememberMeCookie();
        } else {
            $remembered_table = ($remembered['role'] === 'admin') ? 'admins' : 'users';
            $remembered_active = 1;
            $ra_stmt = $conn->prepare("SELECT is_active FROM `$remembered_table` WHERE id = ?");
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
                $_SESSION['user_id']    = $remembered['user_id'];
                $_SESSION['first_name'] = $remembered['first_name'];
                $_SESSION['role']       = $remembered['role'];
                if ($_SESSION['role'] === 'admin') { header("Location:administrator.php"); exit(); }
                else                                { header("Location:AccomForm.php"); exit(); }
            }
        }
    }
}

// ============================================
// NEW (Recover Email ↔ Student List match): helpers
// ------------------------------------------------------------
// The Recover Email form now asks for exactly the same student data the
// admin enters in admin_student_list.php's manual "Add Student" form
// (First / Middle / Last Name, Course, Major, Section, Email, Campus
// Branch). These helpers read that data from the SAME place the Student
// List reads it from — `users` + `student_information`, using the same
// column fallbacks as student_list_source_sql() in admin_student_list.php
// (course/program, section/year_section/year_and_section,
// campus_branch/campus/branch) — so what the student types is compared
// against exactly what the admin saved.
// ============================================
function re_table_columns($conn, $table) {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    $cols = [];
    try {
        $res = $conn->query("SHOW COLUMNS FROM `" . str_replace('`', '', $table) . "`");
        if ($res) {
            while ($c = $res->fetch_assoc()) $cols[$c['Field']] = true;
        }
    } catch (\Throwable $e) {
        $cols = [];
    }
    $cache[$table] = $cols;
    return $cols;
}

function re_ci($expr) {
    return "(CONVERT($expr USING utf8mb4) COLLATE utf8mb4_general_ci)";
}

function re_student_source_sql($conn) {
    static $sql = null;
    if ($sql !== null) return $sql;

    $siCols  = re_table_columns($conn, 'student_information');
    $hasSinf = isset($siCols['user_id']);
    $pick = function (array $candidates) use ($siCols) {
        foreach ($candidates as $c) {
            if (isset($siCols[$c])) return "MAX(`" . $c . "`)";
        }
        return "NULL";
    };

    $sinfJoin = '';
    $sinfCourse = $sinfMajor = $sinfSection = $sinfCampus = 'NULL';
    if ($hasSinf) {
        $sinfJoin = "LEFT JOIN (
                SELECT user_id,
                       " . $pick(['course', 'program']) . " AS course,
                       " . $pick(['major']) . " AS major,
                       " . $pick(['section', 'year_section', 'year_and_section']) . " AS section,
                       " . $pick(['campus_branch', 'campus', 'branch']) . " AS campus_branch
                FROM student_information
                GROUP BY user_id
            ) sinf ON sinf.user_id = u2.id";
        $sinfCourse  = 'sinf.course';
        $sinfMajor   = 'sinf.major';
        $sinfSection = 'sinf.section';
        $sinfCampus  = 'sinf.campus_branch';
    }

    $uCols      = re_table_columns($conn, 'users');
    $userCourse = isset($uCols['course']) ? 'u2.course' : 'NULL';

    $sql = "
        SELECT u2.id AS user_id,
               " . re_ci('u2.first_name') . " AS first_name,
               " . re_ci('u2.middle_name') . " AS middle_name,
               " . re_ci('u2.last_name') . " AS last_name,
               " . re_ci("COALESCE(NULLIF(CONVERT($userCourse USING utf8mb4), ''), CONVERT($sinfCourse USING utf8mb4))") . " AS course,
               " . re_ci($sinfMajor) . " AS major,
               " . re_ci($sinfSection) . " AS section,
               " . re_ci('u2.email') . " AS email,
               " . re_ci($sinfCampus) . " AS campus_branch
        FROM users u2
        $sinfJoin
        WHERE u2.role = 'student'
    ";
    return $sql;
}

// Distinct values of one Student List column — same idea as
// getCourseOptions()/getMajorOptions()/getSectionOptions()/getCampusOptions()
// in admin_student_list.php — used to fill the Recover Email dropdowns.
function re_distinct_options($conn, $column) {
    $allowed = ['course', 'major', 'section', 'campus_branch'];
    if (!in_array($column, $allowed, true)) return [];
    $list = [];
    try {
        $res = $conn->query("SELECT DISTINCT $column FROM (" . re_student_source_sql($conn) . ") re_s
                             WHERE $column IS NOT NULL AND $column != '' ORDER BY $column");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $v = trim((string)$row[$column]);
                if ($v !== '' && !in_array($v, $list, true)) $list[] = $v;
            }
        }
    } catch (\Throwable $e) {
        $list = [];
    }
    return $list;
}

// Case/whitespace-insensitive comparison key (NULL and '' are the same).
function re_norm($value) {
    $value = preg_replace('/\s+/u', ' ', trim((string)$value));
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

$step = 1;
$showCompanyRedirectPopup = false;
// ── NEW: flag that triggers the "Account Deactivated" popup on step 1 ──
$showDeactivatedPopup = false;
// ── NEW: flag that triggers the "Admin Account Detected" popup on step 1 ──
$showAdminRedirectPopup = false;

// ============================================
// NEW: generic login notification popup state
// ------------------------------------------------------------
// Mirrors the popup pattern from company_login.php (there driven by
// $popup_type / $popup_title / $popup_msg) so that step-1 login problems
// that used to render as a plain inline red paragraph — missing fields,
// account not found, incorrect password — now show the same kind of
// popup notification used on the company login page instead.
// ============================================
$popup_type  = '';
$popup_title = '';
$popup_msg   = '';

// ── NEW (one request per student): popup handed over from the Recover
// Email handler (set before its redirect back to login.php), shown with
// the page's existing login notification popup. ──
if (!empty($_SESSION['re_popup']) && is_array($_SESSION['re_popup'])) {
    $popup_type  = $_SESSION['re_popup']['type']  ?? 'existing_request';
    $popup_title = $_SESSION['re_popup']['title'] ?? '';
    $popup_msg   = $_SESSION['re_popup']['msg']   ?? '';
    unset($_SESSION['re_popup']);
}

if (isset($_SESSION['fp_step'])) $step = $_SESSION['fp_step'];
if (isset($_SESSION['re_step'])) $step = $_SESSION['re_step'];

if (isset($_GET['re_cancel'])) {
    unset($_SESSION['re_step'], $_SESSION['re_type']);
    header("Location: login.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // ================= STEP 1 LOGIN =================
    if (isset($_POST['login'])) {
        unset($_SESSION['fp_step'], $_SESSION['fp_email'],
              $_SESSION['fp_otp'],  $_SESSION['fp_otp_time'],
              $_SESSION['fp_otp_count'], $_SESSION['fp_cooldown'],
              $_SESSION['fp_first_name'], $_SESSION['fp_table']);

        $email      = trim($_POST['email']);
        // ============================================
        // FIX: trim the password too (was previously used raw).
        // ------------------------------------------------------------
        // $email above was already trimmed, but $password was not.
        // Copy-pasting a password out of an HTML email (e.g.
        // the "Account Created" / OTP emails sent from monitoring.php,
        // where the password is wrapped in a <b> tag) can very easily
        // pick up an invisible leading/trailing space or line break
        // depending on the email client or how the text was selected.
        // password_verify() then fails even though the password looks
        // completely correct to the user — this was the root cause of
        // a freshly-created admin account getting "Invalid Password"
        // on login even with the right password. Trimming here mirrors
        // the same trim() already applied to $email.
        // ============================================
        $password   = trim($_POST['password']);
        $rememberMe = isset($_POST['remember_me']);
        $user       = null;
        $role       = null;

        // ============================================
        // NEW: MISSING FIELDS CHECK
        // ------------------------------------------------------------
        // Mirrors company_login.php's pattern of validating both fields
        // are present before doing any DB lookup, and reporting it via
        // the same kind of notification popup used on that page instead
        // of relying solely on the HTML5 `required` attribute.
        // ============================================
        if ($email === '' || $password === '') {
            $popup_type  = 'error';
            $popup_title = 'Missing Fields';
            $popup_msg   = 'Please enter both your email and password.';
        } else {

            // ── UPDATED: also selects is_active so a deactivated account can be
            // detected and blocked right after password verification. ──
            $stmt = $conn->prepare("SELECT id, first_name, password, role, is_active FROM users WHERE email = ?");
            $stmt->bind_param("s", $email); $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                $user = $result->fetch_assoc();
                $role = ($user['role'] == "company") ? "company" : "student";
            }
            $stmt->close();

            if (!$user) {
                // ── UPDATED: also selects is_active for admin accounts. ──
                $stmt = $conn->prepare("SELECT id, first_name, password, is_active FROM admins WHERE email = ?");
                $stmt->bind_param("s", $email); $stmt->execute();
                $result = $stmt->get_result();
                if ($result->num_rows > 0) { $user = $result->fetch_assoc(); $role = "admin"; }
                $stmt->close();
            }

            if ($user) {
                if (password_verify($password, $user['password'])) {

                    // ============================================
                    // NEW: DEACTIVATED ACCOUNT CHECK
                    // ------------------------------------------------------------
                    // Mirrors the is_active flag toggled from monitoring.php's
                    // Activate/Deactivate Status buttons. A deactivated account
                    // (student, company, or admin) is blocked from signing in
                    // here and shown a popup notification instead of proceeding
                    // to the OTP step. Checked BEFORE the company-redirect logic
                    // below so a deactivated company account also gets this
                    // message rather than the "use company login" popup.
                    // ============================================
                    if (isset($user['is_active']) && (int)$user['is_active'] === 0) {
                        $showDeactivatedPopup = true;
                        $step = 1;
                    }
                    // ── Company accounts must use the dedicated company login page ──
                    elseif ($role === 'company') {
                        $showCompanyRedirectPopup = true;
                        $step = 1;
                    }
                    // ============================================
                    // NEW: ADMIN ACCOUNT CHECK
                    // ------------------------------------------------------------
                    // Admin accounts must use the dedicated Admin Portal
                    // (admin_login.php), not this student-facing login page.
                    // Shown as its own popup with a redirect action, mirroring
                    // the company-account redirect popup above.
                    // ============================================
                    elseif ($role === 'admin') {
                        $showAdminRedirectPopup = true;
                        $step = 1;
                    } else {

                        $existingRemember = checkRememberMeCookie($user['id']);
                        if ($existingRemember) {
                            session_regenerate_id(true);
                            $_SESSION['user_id']    = $user['id'];
                            $_SESSION['first_name'] = $user['first_name'];
                            $_SESSION['role']       = $role;
                            setRememberMeCookie($user['id'], $role, $user['first_name']);
                            unset($_SESSION['otp_email'], $_SESSION['user_id_temp'],
                                  $_SESSION['first_name_temp'], $_SESSION['role_temp'],
                                  $_SESSION['remember_me_pending'],
                                  $_SESSION['otp_send_count'], $_SESSION['otp_cooldown']);
                            if ($role === 'admin') { header("Location:administrator.php"); exit(); }
                            else                     { header("Location:AccomForm.php"); exit(); }
                        }

                        $_SESSION['otp_email']          = $email;
                        $_SESSION['user_id_temp']        = $user['id'];
                        $_SESSION['first_name_temp']     = $user['first_name'];
                        $_SESSION['role_temp']           = $role;
                        $_SESSION['remember_me_pending'] = $rememberMe;

                        $step = 2;
                    }
                } else {
                    // ============================================
                    // NEW: report via the same notification popup pattern
                    // used in company_login.php, instead of plain inline text.
                    // ============================================
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

    // ================= SEND OTP (UNTOUCHED) =================
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
                $_SESSION['user_id']    = $_SESSION['user_id_temp'];
                $_SESSION['first_name'] = $_SESSION['first_name_temp'];
                $_SESSION['role']       = $_SESSION['role_temp'];

                if ($rememberMe) {
                    setRememberMeCookie($_SESSION['user_id'], $_SESSION['role'], $_SESSION['first_name']);
                }

                unset($_SESSION['otp'], $_SESSION['otp_time'], $_SESSION['otp_email'],
                      $_SESSION['user_id_temp'], $_SESSION['first_name_temp'],
                      $_SESSION['role_temp'], $_SESSION['otp_send_count'],
                      $_SESSION['otp_cooldown'], $_SESSION['remember_me_pending']);

                if ($_SESSION['role'] == "admin") header("Location:administrator.php");
                else                               header("Location:AccomForm.php");
                exit();
            } else {
                $error = "Invalid OTP. Please try again."; $step = 2;
            }
        }
    }

    // ============================================
    // NEW: CANCEL OTP — return to login (step 1)
    // ------------------------------------------------------------
    // Mirrors the fp_cancel pattern already used to back out of the
    // forgot-password flow. Clears all step-2 OTP/session state so a
    // fresh login attempt starts clean, without touching send_otp /
    // verify_otp logic above (both left completely untouched).
    // ============================================
    if (isset($_POST['otp_cancel'])) {
        unset($_SESSION['otp_email'], $_SESSION['otp'], $_SESSION['otp_time'],
              $_SESSION['user_id_temp'], $_SESSION['first_name_temp'], $_SESSION['role_temp'],
              $_SESSION['remember_me_pending'], $_SESSION['otp_send_count'], $_SESSION['otp_cooldown']);
        $step = 1;
    }

    // ================= FORGOT PASSWORD — FP1: SUBMIT EMAIL =================
    if (isset($_POST['fp_submit_email'])) {
        $fp_email = trim($_POST['fp_email']);
        $fp_user  = null;
        $fp_table = null;

        $stmt = $conn->prepare("SELECT id, first_name FROM users WHERE email = ?");
        $stmt->bind_param("s", $fp_email); $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if ($row) { $fp_user = $row; $fp_table = 'users'; }

        if (!$fp_user) {
            $stmt = $conn->prepare("SELECT id, first_name FROM admins WHERE email = ?");
            $stmt->bind_param("s", $fp_email); $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if ($row) { $fp_user = $row; $fp_table = 'admins'; }
        }

        if (!$fp_user) {
            $fp_error = "No account found with that email address.";
            $step = 4; $_SESSION['fp_step'] = 4;
        } else {
            $_SESSION['fp_email']      = $fp_email;
            $_SESSION['fp_first_name'] = $fp_user['first_name'];
            $_SESSION['fp_table']      = $fp_table;
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
                $fp_table   = $_SESSION['fp_table'];
                $fp_email   = $_SESSION['fp_email'];
                $fp_name    = $_SESSION['fp_first_name'];

                $stmt = $conn->prepare("UPDATE `$fp_table` SET password = ? WHERE email = ?");
                $stmt->bind_param("ss", $hashedPass, $fp_email);
                $stmt->execute();
                $stmt->close();

                sendNewPasswordEmail($fp_email, $fp_name, $newPass);

                unset($_SESSION['fp_step'], $_SESSION['fp_email'],
                      $_SESSION['fp_otp'],  $_SESSION['fp_otp_time'],
                      $_SESSION['fp_otp_count'], $_SESSION['fp_cooldown'],
                      $_SESSION['fp_first_name'], $_SESSION['fp_table']);

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
              $_SESSION['fp_first_name'], $_SESSION['fp_table']);
        $step = 1;
    }

    if (isset($_POST['fp_go'])) {
        unset($_SESSION['fp_step'], $_SESSION['fp_email'],
              $_SESSION['fp_otp'],  $_SESSION['fp_otp_time'],
              $_SESSION['fp_otp_count'], $_SESSION['fp_cooldown'],
              $_SESSION['fp_first_name'], $_SESSION['fp_table']);
        $_SESSION['fp_step'] = 4;
        header("Location: login.php");
        exit();
    }

    // ── UPDATED: since company_login.php now runs its own self-contained
    // Recover Email flow, this page's Recover Email option is student-only.
    // Skips straight to the request form (step 8) — no more "Student vs
    // Company" type picker — mirroring how company_login.php's own
    // recovery flow goes straight to its form too. ──
    if (isset($_POST['re_go'])) {
        unset($_SESSION['re_step'], $_SESSION['re_type']);
        $_SESSION['re_step'] = 8;
        header("Location: login.php");
        exit();
    }

    if (isset($_POST['re_cancel'])) {
        unset($_SESSION['re_step'], $_SESSION['re_type']);
        header("Location: login.php");
        exit();
    }

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

        // ── UPDATED (Recover Email ↔ Student List match): the form fields
        // are now the exact same data the admin enters in
        // admin_student_list.php's manual "Add Student" form:
        // First / Middle / Last Name, Course, Major ("none" = no major),
        // Section, Email (the old/registered email) and Campus Branch.
        // The old "Registered Company" field (and its "Waiting" deploy
        // status special-case) was removed because the Student List does
        // not hold a company, which is why valid students could never match. ──
        $re_first   = trim($_POST['re_first_name']    ?? '');
        $re_middle  = trim($_POST['re_middle_name']   ?? '');
        $re_last    = trim($_POST['re_last_name']     ?? '');
        $re_course  = trim($_POST['re_course']        ?? '');
        $re_major   = trim($_POST['re_major']         ?? '');
        $re_section = trim($_POST['re_section']       ?? '');
        $re_campus  = trim($_POST['re_campus_branch'] ?? '');
        $re_old     = trim($_POST['re_old_email']     ?? '');
        $re_new     = trim($_POST['re_new_email']     ?? '');
        $re_reason  = trim($_POST['re_reason']        ?? '');
        $re_extra   = 'Course: ' . $re_course
                    . ' | Major: ' . (strtolower($re_major) === 'none' ? 'None' : $re_major)
                    . ' | Section: ' . $re_section
                    . ' | Campus: ' . $re_campus;
        $re_extra   = function_exists('mb_substr') ? mb_substr($re_extra, 0, 300, 'UTF-8') : substr($re_extra, 0, 300);

        if (empty($re_first) || empty($re_last) || empty($re_course) || $re_major === '' || empty($re_section)
            || empty($re_campus) || empty($re_old) || empty($re_new) || empty($re_reason)) {
            $re_error = "Please fill in all required fields.";
            $step = 8; $_SESSION['re_step'] = 8;
        } elseif (!filter_var($re_old, FILTER_VALIDATE_EMAIL) || !filter_var($re_new, FILTER_VALIDATE_EMAIL)) {
            $re_error = "Please enter valid email addresses.";
            $step = 8; $_SESSION['re_step'] = 8;
        } else {
            // Look the student up by the registered email in the same
            // users + student_information view the Student List uses, then
            // compare every field case/space-insensitively. A missing
            // middle name / major (NULL in the DB) matches a blank middle
            // name / "None" major — the old SQL (LOWER(TRIM(NULL)) = ...)
            // could never match those, which is part of what was broken.
            $match     = false;
            $re_record = null;
            try {
                $qm = $conn2->prepare("SELECT s.* FROM (" . re_student_source_sql($conn2) . ") s
                                       WHERE LOWER(TRIM(s.email)) = LOWER(TRIM(?)) LIMIT 1");
                if ($qm) {
                    $qm->bind_param("s", $re_old);
                    $qm->execute();
                    $re_record = $qm->get_result()->fetch_assoc();
                    $qm->close();
                }
            } catch (\Throwable $e) {
                $re_record = null;
            }

            if ($re_record) {
                $recMajor  = re_norm($re_record['major'] ?? '');
                if ($recMajor === '' || $recMajor === 'none') $recMajor = 'none';
                $formMajor = re_norm($re_major);
                if ($formMajor === '' || $formMajor === 'none') $formMajor = 'none';

                $match = re_norm($re_record['first_name']    ?? '') === re_norm($re_first)
                      && re_norm($re_record['middle_name']   ?? '') === re_norm($re_middle)
                      && re_norm($re_record['last_name']     ?? '') === re_norm($re_last)
                      && re_norm($re_record['course']        ?? '') === re_norm($re_course)
                      && re_norm($re_record['section']       ?? '') === re_norm($re_section)
                      && re_norm($re_record['campus_branch'] ?? '') === re_norm($re_campus)
                      && $recMajor === $formMajor;
            }

            // ── NEW (one request per student): if this student already has a
            // recovery request on file (anything not rejected/declined/cancelled),
            // a second one is not saved. The student is sent back to the login
            // page and a popup explains the existing request and, if they did
            // not submit it, to contact the administrator. Checked only AFTER
            // the details matched, so the popup never reveals whether an
            // account exists to someone who doesn't know its details. ──
            $re_existing = null;
            if ($match) {
                try {
                    $ex = $conn2->prepare("SELECT id, status, submitted_at FROM email_recovery_requests
                        WHERE account_type = 'student'
                          AND (LOWER(TRIM(old_email)) = LOWER(TRIM(?)) OR LOWER(TRIM(new_email)) = LOWER(TRIM(?)))
                          AND (status IS NULL OR LOWER(TRIM(status)) NOT IN ('rejected','declined','denied','cancelled','canceled'))
                        ORDER BY submitted_at DESC, id DESC
                        LIMIT 1");
                    if ($ex) {
                        $ex->bind_param("ss", $re_old, $re_old);
                        $ex->execute();
                        $re_existing = $ex->get_result()->fetch_assoc();
                        $ex->close();
                    }
                } catch (\Throwable $e) {
                    $re_existing = null;
                }
            }

            if (!$match) {
                $re_error = "Credentials do not match any account in our records. Please check all fields and try again.";
                $step = 8; $_SESSION['re_step'] = 8;
            } elseif ($re_existing) {
                $exStatus = trim((string)($re_existing['status'] ?? '')) !== '' ? ucfirst(strtolower(trim($re_existing['status']))) : 'Pending';
                $exWhen   = !empty($re_existing['submitted_at']) ? date('F j, Y \\a\\t g:i A', strtotime($re_existing['submitted_at'])) : '';
                $_SESSION['re_popup'] = [
                    'type'  => 'existing_request',
                    'title' => 'Existing Request Found',
                    'msg'   => "An email recovery request for this student account was already submitted"
                             . ($exWhen !== '' ? " on " . $exWhen : '')
                             . " (status: " . $exStatus . "). Only one request is allowed per student.\n\n"
                             . "If you did not submit this request, please contact your administrator for assistance.",
                ];
                unset($_SESSION['re_step'], $_SESSION['re_type']);
                header("Location: login.php");
                exit();
            } else {
                $selfieBlob = null;
                $selfieData = $_POST['re_selfie_data'] ?? '';
                if (!empty($selfieData)) {
                    $selfieData = preg_replace('/^data:image\/\w+;base64,/', '', $selfieData);
                    $selfieBlob = base64_decode($selfieData);
                }

                $stmt = $conn2->prepare("INSERT INTO email_recovery_requests
                    (account_type, first_name, middle_name, last_name, old_email, new_email, reason, extra_info, selfie_blob, status, submitted_at)
                    VALUES ('student', ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())");
                $null = null;
                $stmt->bind_param("sssssssb",
                    $re_first, $re_middle, $re_last,
                    $re_old, $re_new, $re_reason, $re_extra, $null
                );
                if ($selfieBlob !== null) {
                    $stmt->send_long_data(7, $selfieBlob);
                }

                if ($stmt->execute()) {
                    $step = 9;
                    unset($_SESSION['re_step'], $_SESSION['re_type']);
                } else {
                    $re_error = "Database error: " . $conn2->error;
                    $step = 8; $_SESSION['re_step'] = 8;
                }
                $stmt->close();
            }
        }
    }
}

// ── NEW (Recover Email ↔ Student List match): dropdown options for the
// Recover Email form, loaded from the Student List data BEFORE the
// connection is closed (the old step-8 markup queried $conn after
// $conn->close(), which fails). ──
$re_course_options  = [];
$re_major_options   = [];
$re_section_options = [];
$re_campus_options  = [];
if ($step == 8) {
    $re_course_options  = re_distinct_options($conn, 'course');
    $re_major_options   = re_distinct_options($conn, 'major');
    $re_section_options = re_distinct_options($conn, 'section');
    $re_campus_options  = re_distinct_options($conn, 'campus_branch');
}

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
<title>Login | NEUST OJT Portal</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700&display=swap');

/* ═══════════════════════════════════════════════
   FIX: Full-page background that always renders.
   Uses a rich blue-to-indigo gradient as the base
   so the glassmorphism card looks correct even when
   the photo/OIP.webp image is unavailable.
   The image is layered ON TOP via a pseudo-element
   so that if it does load it enhances the gradient.
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
    /* UPDATED (login redesign): light student-list page background (same #EEF1F6
       content-area color as admin_student_list.php) instead of the blue gradient. */
    background: #EEF1F6;
    position: relative;
    overflow-x: hidden;
}

/* UPDATED (login redesign): the decorative gradient shapes and the background photo
   were removed together with the blue gradient. */
body::before,
body::after { display: none; }

/* Everything above the pseudo-elements */
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

/* ── REMEMBER ME ── */
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

/* ── Active "remembered device" badge ── */
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

/* ── OTP COUNTDOWN RING ── */
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
    width: 100%; padding: 16px; background: rgba(255, 255, 255, 0.92);
    color: #0038a8; border: none; border-radius: 12px;
    font-weight: 700; font-size: 15px; cursor: pointer;
    margin-top: 10px; transition: 0.3s;
}
.login-submit:hover { background: #ffffff; transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,0,0,0.25); }

.footer-text { margin-top: 20px; font-size: 14px; color: rgba(255, 255, 255, 0.80); }
.footer-text a { color: #ffffff; text-decoration: none; font-weight: 700; }

.forgot-link {
    display: block; margin-top: 12px; font-size: 13px;
    color: rgba(255,255,255,0.80); text-decoration: none; font-weight: 600;
    background: none; border: none; cursor: pointer;
    width: 100%; text-align: center;
}
.forgot-link:hover { text-decoration: underline; color: #ffffff; }

.btn-secondary {
    width: 100%; padding: 13px; background: rgba(255,255,255,0.12);
    color: rgba(255,255,255,0.80); border: 1.5px solid rgba(255,255,255,0.25);
    border-radius: 12px; font-weight: 600; font-size: 14px; cursor: pointer;
    margin-top: 10px; transition: 0.2s;
}
.btn-secondary:hover { background: rgba(255,255,255,0.20); color: #ffffff; }

/* ── SUCCESS POPUP ── */
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

/* ── COMPANY ACCOUNT REDIRECT POPUP ── */
#companyRedirectOverlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,0.55); z-index: 9999;
    justify-content: center; align-items: center;
    backdrop-filter: blur(4px); animation: fadeIn 0.25s ease;
}
#companyRedirectBox {
    background: white; border-radius: 20px; padding: 40px 36px;
    width: 360px; max-width: 92%; text-align: center;
    box-shadow: 0 20px 60px rgba(0,0,0,0.25);
    animation: popIn 0.3s cubic-bezier(0.34,1.56,0.64,1);
}
#companyRedirectBox .sp-icon  { font-size: 52px; display: block; margin-bottom: 14px; }
#companyRedirectBox .sp-title { font-size: 18px; font-weight: 700; color: #0038a8; margin: 0 0 8px; }
#companyRedirectBox .sp-msg   { font-size: 13px; color: #64748b; margin: 0 0 24px; line-height: 1.6; }
#companyRedirectBox .sp-btn-row { display: flex; gap: 10px; justify-content: center; }
#companyRedirectBox .sp-btn {
    padding: 13px 24px; background: #0038a8; color: white;
    border: none; border-radius: 12px; font-weight: 700;
    font-size: 14px; cursor: pointer; transition: 0.2s; flex: 1;
}
#companyRedirectBox .sp-btn:hover { background: #002d86; }
#companyRedirectBox .sp-btn-cancel {
    padding: 13px 24px; background: #e2e8f0; color: #334155;
    border: none; border-radius: 12px; font-weight: 700;
    font-size: 14px; cursor: pointer; transition: 0.2s; flex: 1;
}
#companyRedirectBox .sp-btn-cancel:hover { background: #cbd5e1; }

/* ═══════════════════════════════════════════════
   NEW: ADMIN ACCOUNT REDIRECT POPUP
   ------------------------------------------------
   Shown when the matched account (checked against the
   `admins` table) attempts to log in via this
   student-facing login page. Same visual language as
   #companyRedirectBox above (two-button OK/Cancel),
   just with its own id/color so it doesn't interfere
   with any existing markup, styling, or scripts.
   ═══════════════════════════════════════════════ */
#adminRedirectOverlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,0.55); z-index: 9999;
    justify-content: center; align-items: center;
    backdrop-filter: blur(4px); animation: fadeIn 0.25s ease;
}
#adminRedirectBox {
    background: white; border-radius: 20px; padding: 40px 36px;
    width: 360px; max-width: 92%; text-align: center;
    box-shadow: 0 20px 60px rgba(0,0,0,0.25);
    animation: popIn 0.3s cubic-bezier(0.34,1.56,0.64,1);
}
#adminRedirectBox .sp-icon  { font-size: 52px; display: block; margin-bottom: 14px; }
#adminRedirectBox .sp-title { font-size: 18px; font-weight: 700; color: #6d28d9; margin: 0 0 8px; }
#adminRedirectBox .sp-msg   { font-size: 13px; color: #64748b; margin: 0 0 24px; line-height: 1.6; }
#adminRedirectBox .sp-btn-row { display: flex; gap: 10px; justify-content: center; }
#adminRedirectBox .sp-btn {
    padding: 13px 24px; background: #6d28d9; color: white;
    border: none; border-radius: 12px; font-weight: 700;
    font-size: 14px; cursor: pointer; transition: 0.2s; flex: 1;
}
#adminRedirectBox .sp-btn:hover { background: #5b21b6; }
#adminRedirectBox .sp-btn-cancel {
    padding: 13px 24px; background: #e2e8f0; color: #334155;
    border: none; border-radius: 12px; font-weight: 700;
    font-size: 14px; cursor: pointer; transition: 0.2s; flex: 1;
}
#adminRedirectBox .sp-btn-cancel:hover { background: #cbd5e1; }

/* ═══════════════════════════════════════════════
   NEW: ACCOUNT DEACTIVATED POPUP
   ------------------------------------------------
   Shown when a student, company, or admin account
   passes the email/password check on step 1 but its
   is_active flag (toggled from monitoring.php) is 0.
   Same visual language as #companyRedirectBox above,
   just with a red/destructive accent instead of blue.
   ═══════════════════════════════════════════════ */
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

/* ═══════════════════════════════════════════════
   NEW: GENERIC LOGIN NOTIFICATION POPUP
   ------------------------------------------------
   Ports the notification-popup pattern used in
   company_login.php (#regPopupOverlay/#regPopupBox,
   driven by $popup_type/$popup_title/$popup_msg) over
   to login.php's step-1 validation: Missing Fields,
   Account Not Found, and Incorrect Password now show
   this popup instead of the old plain inline red text.
   Same visual language as the other popups above.
   ═══════════════════════════════════════════════ */
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

/* ══════════════════════════════════════════════════════════════
   NEW: GLOBAL LOADING PAGE — same design as admin_student_list.php
   ------------------------------------------------------------
   Light full-page backdrop, navy ring spinner, uppercase "LOADING..."
   label with animated dots. Visible by default so it covers the first
   paint while the page loads, shown again whenever the page is left
   (login, OTP, forgot password, recover email...).
   Its "success-state" (green check + title + message) replaces the
   old "Request Submitted!" card after an email recovery request.
   ══════════════════════════════════════════════════════════════ */
#globalLoadingOverlay {
    position: fixed;
    inset: 0;
    z-index: 20000;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(238, 241, 246, 0.92);
    opacity: 1;
    visibility: visible;
    transition: opacity 0.35s ease, visibility 0.35s ease;
}
#globalLoadingOverlay.hidden {
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
}
.global-loading-box {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 16px;
    animation: globalLoadingPop 0.35s ease;
}
.global-loading-spinner {
    width: 54px;
    height: 54px;
    border-radius: 50%;
    border: 5px solid var(--grid-border, #C3CADA);
    border-top-color: var(--grid-navy, #1B2A4A);
    animation: globalLoadingSpin 0.85s linear infinite;
}
.global-loading-text {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    font-size: 13px;
    font-weight: 700;
    color: var(--grid-navy, #1B2A4A);
    text-transform: uppercase;
    letter-spacing: 0.6px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.global-loading-dots span {
    animation: globalLoadingDots 1.2s infinite;
    opacity: 0;
}
.global-loading-dots span:nth-child(2) { animation-delay: 0.2s; }
.global-loading-dots span:nth-child(3) { animation-delay: 0.4s; }
@keyframes globalLoadingSpin { to { transform: rotate(360deg); } }
@keyframes globalLoadingPop { from { transform: scale(0.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }
@keyframes globalLoadingDots { 0%, 20% { opacity: 0; } 50% { opacity: 1; } 100% { opacity: 0; } }

.global-loading-success { display: none; flex-direction: column; align-items: center; gap: 10px; text-align: center; max-width: 420px; padding: 0 20px; }
#globalLoadingOverlay.success-state .global-loading-spinner,
#globalLoadingOverlay.success-state .global-loading-text { display: none; }
#globalLoadingOverlay.success-state .global-loading-success { display: flex; }
.gls-check {
    width: 64px; height: 64px; border-radius: 50%;
    background: var(--grid-green, #2C5A2C); color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 30px; box-shadow: 0 0 0 8px var(--grid-green-bg, #EAF3EA);
    animation: glsCheckPop 0.45s cubic-bezier(.34,1.56,.64,1);
}
.gls-title {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    font-size: 15px; font-weight: 700; color: var(--grid-navy, #1B2A4A);
    text-transform: uppercase; letter-spacing: 0.6px; margin-top: 6px;
}
.gls-message { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size: 13px; line-height: 1.5; color: var(--grid-muted, #5B6478); }
.gls-sub { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size: 11px; color: var(--grid-muted, #5B6478); opacity: .8; display: flex; align-items: center; gap: 6px; }
@keyframes glsCheckPop { from { transform: scale(0.3); opacity: 0; } to { transform: scale(1); opacity: 1; } }

/* NEW (one request per student): let the login popup show a message on
   separate lines (only the "Existing Request Found" message uses line breaks). */
#loginNotifyBox .ln-msg { white-space: pre-line; }

/* ── RECOVER EMAIL ── */
.re-section-title {
    font-size: 13px; font-weight: 700; color: #93c5fd;
    text-transform: uppercase; letter-spacing: 0.5px;
    margin: 18px 0 10px; text-align: left;
}
.re-type-card {
    flex: 1; border: 2px solid rgba(255,255,255,0.20); border-radius: 14px;
    padding: 18px 10px; text-align: center; cursor: pointer;
    transition: all 0.2s; background: rgba(255,255,255,0.08);
}
.re-type-card:hover { border-color: #60a5fa; background: rgba(96,165,250,0.15); }
.re-type-card.selected { border-color: #3b82f6; background: rgba(59,130,246,0.20); }
.re-type-card .re-icon { font-size: 28px; display: block; margin-bottom: 6px; }
.re-type-card .re-label { font-size: 13px; font-weight: 700; color: #ffffff; }

/* UPDATED: the old glass-theme "#reForm" input/label overrides were
   removed here — the Recover Email form now uses the admin "Add Student"
   modal design (see the ADMIN MODAL DESIGN block below), and those
   ID-level rules would have overridden it (white labels on white). */

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

/* Forgot password card inputs: light for readability */
.fp-light-input {
    background: rgba(255,255,255,0.92) !important;
    color: #1e293b !important;
    border: 1.5px solid rgba(255,255,255,0.60) !important;
}
.fp-light-input::placeholder { color: #94a3b8 !important; }

/* ═══════════════════════════════════════════════════════════════
   NEW: ADMIN "ADD STUDENT" MODAL LAYOUT — Forgot Password (steps
   4 & 5) and Recover Email (step 8)
   ------------------------------------------------------------
   Keeps the structure of the manual Add Student modal in
   admin_student_list.php (header with icon + × close, compact grid
   of .form-group fields with a required *, small help text, sticky
   bottom action bar with submit + cancel).
   UPDATED (match the background): the colors/surfaces now follow
   this page's own blue-gradient glassmorphism theme — the same
   translucent blurred card, white/transparent inputs, uppercase
   light labels and white "Login"-style primary button used by the
   login card — instead of the admin page's flat white look.
   Applied only while .card carries .am-card (steps 4, 5, 8).
   ═══════════════════════════════════════════════════════════════ */
.card.am-card {
    --am-text: #ffffff;
    --am-text-soft: rgba(255, 255, 255, 0.75);
    --am-text-muted: rgba(255, 255, 255, 0.55);
    --am-line: rgba(255, 255, 255, 0.22);
    --am-field-bg: rgba(255, 255, 255, 0.12);
    --am-field-border: rgba(255, 255, 255, 0.25);
    --am-accent: #93c5fd;
    --am-required: #fca5a5;
    /* same glass surface as the base .card */
    background: rgba(255, 255, 255, 0.12);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border: 1.5px solid rgba(255, 255, 255, 0.28);
    border-radius: 24px;
    box-shadow:
        0 8px 32px rgba(0, 0, 20, 0.35),
        inset 0 1px 0 rgba(255, 255, 255, 0.25);
    width: 550px;
    max-width: 94%;
    max-height: calc(100vh - 140px);
    max-height: calc(100dvh - 140px);
    overflow-y: auto;
    padding: 22px 28px 0 28px;
    box-sizing: border-box;
    text-align: left;
    color: var(--am-text);
    display: flex;
    flex-direction: column;
    animation: amModalPop 0.3s ease;
    scrollbar-width: thin;
    scrollbar-color: rgba(255, 255, 255, 0.35) transparent;
}
.card.am-card::-webkit-scrollbar { width: 8px; }
.card.am-card::-webkit-scrollbar-track { background: transparent; }
.card.am-card::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.30); border-radius: 8px; }
.card.am-card.am-card-wide { width: 860px; }
@keyframes amModalPop { from { transform: scale(0.96); opacity: 0; } to { transform: scale(1); opacity: 1; } }

/* Header always sits on top, even above the status messages that are echoed first */
.am-card .am-modal-header {
    order: -1;
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 14px; padding-bottom: 10px;
    border-bottom: 1px solid var(--am-line);
}
.am-card .am-modal-header h3 {
    margin: 0; color: var(--am-text); font-size: 15px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.5px;
}
.am-card .am-modal-header h3 i { margin-right: 6px; color: var(--am-accent); }
.am-card .am-close-btn {
    background: none; border: none; font-size: 26px; line-height: 1;
    cursor: pointer; color: var(--am-text-soft); transition: color 0.2s, transform 0.2s;
    padding: 0; width: auto; margin: 0;
}
.am-card .am-close-btn:hover { color: #ffffff; transform: scale(1.1); }
.am-card .am-modal-intro { font-size: 13px; color: var(--am-text-soft); margin: 0 0 14px; line-height: 1.55; text-align: left; }
.am-card .am-modal-intro strong { color: #ffffff; }
.am-card form { margin-bottom: 0; }

/* Grid — 1 column for the Forgot Password box, 3 for Recover Email */
.am-card .am-grid { display: grid; grid-template-columns: 1fr; column-gap: 14px; row-gap: 0; align-items: start; }
.am-card.am-card-wide .am-grid { grid-template-columns: repeat(3, 1fr); }
.am-card.am-card-wide .am-grid .span-2 { grid-column: span 2; }
.am-card .am-grid .span-3 { grid-column: 1 / -1; }

.am-card .form-group { margin-bottom: 12px; text-align: left; }
/* labels: same style as the login card's labels */
.am-card .form-group label {
    display: block; font-weight: 700; color: var(--am-text-soft); margin-bottom: 6px;
    font-size: 12px; text-transform: uppercase; letter-spacing: 0.2px;
}
.am-card .form-group label .required { color: var(--am-required); }
/* inputs: same translucent style as the login card's inputs */
.am-card .form-group input:not(.otp-box),
.am-card .form-group select,
.am-card .form-group textarea {
    width: 100%; padding: 11px 13px; border: 1.5px solid var(--am-field-border); border-radius: 12px;
    font-size: 14px; font-family: inherit; background: var(--am-field-bg); color: #ffffff;
    transition: all 0.2s; box-sizing: border-box;
}
/* UPDATED (dropdown indicator): the opened dropdown list now looks like the
   admin_student_list.php dropdowns — light list, dark text and the browser's
   normal highlight for the hovered/selected option — instead of the dark navy
   list. The closed field keeps the glass look with one clear white chevron. */
.am-card .form-group select {
    color-scheme: light;
    cursor: pointer;
    -webkit-appearance: none; -moz-appearance: none; appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1.5l5 5 5-5' fill='none' stroke='%23ffffff' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 14px center;
    background-size: 12px 8px;
    padding-right: 38px;
}
.am-card .form-group select::-ms-expand { display: none; }
.am-card .form-group select option { background: #ffffff; color: #1e293b; }
.am-card .form-group select option[value=""] { color: #64748b; }
.am-card .form-group textarea { resize: vertical; min-height: 80px; }
.am-card .form-group input:not(.otp-box)::placeholder,
.am-card .form-group textarea::placeholder { color: rgba(255, 255, 255, 0.50); }
.am-card .form-group input:not(.otp-box):focus,
.am-card .form-group select:focus,
.am-card .form-group textarea:focus {
    outline: none; border-color: rgba(255, 255, 255, 0.55); background: rgba(255, 255, 255, 0.18);
    box-shadow: 0 0 0 3px rgba(147, 197, 253, 0.18);
}
/* NEW (dropdown indicator): keep the white chevron visible while focused */
.am-card .form-group select:focus {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1.5l5 5 5-5' fill='none' stroke='%23ffffff' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 14px center;
    background-size: 12px 8px;
}
.am-card .help-text { font-size: 11px; color: var(--am-text-muted); margin-top: 5px; line-height: 1.4; }
.am-card .help-text i { color: var(--am-accent); }

/* Action bar — submit button is first in the DOM (so Enter still
   triggers it) but row-reverse shows it on the right, like the admin modal.
   UPDATED (overlap fix): no longer sticky / tinted / pulled to the card
   edges, so it sits BELOW the fields in normal flow and can never cover
   them; it simply shares the card's glass surface. */
.am-card .modal-actions {
    display: flex; flex-direction: row-reverse; justify-content: flex-start; flex-wrap: wrap; gap: 10px;
    position: static; background: none;
    margin: 6px 0 0 0; padding: 14px 0 20px 0;
    border-top: 1px solid var(--am-line);
}
/* primary: same look as .login-submit */
.am-card .btn-submit {
    background: rgba(255, 255, 255, 0.92); color: #0038a8; border: none; padding: 12px 26px; border-radius: 12px;
    font-weight: 700; cursor: pointer; transition: 0.3s; font-size: 13px; font-family: inherit;
    text-transform: uppercase; letter-spacing: 0.3px;
}
.am-card .btn-submit:hover { background: #ffffff; transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0, 0, 0, 0.25); }
.am-card .btn-submit:disabled { opacity: 0.5; cursor: not-allowed; transform: none; box-shadow: none; }
/* secondary: same look as .btn-secondary */
.am-card .btn-cancel-modal {
    background: rgba(255, 255, 255, 0.12); color: rgba(255, 255, 255, 0.85); border: 1.5px solid rgba(255, 255, 255, 0.25);
    padding: 12px 22px; border-radius: 12px; font-weight: 600; cursor: pointer; transition: 0.2s;
    font-size: 13px; font-family: inherit; text-transform: uppercase; letter-spacing: 0.3px;
}
.am-card .btn-cancel-modal:hover { background: rgba(255, 255, 255, 0.20); color: #ffffff; }

/* Status messages keep the page's own glass red/green look; only aligned to the form */
.am-card .card-msg { text-align: left !important; margin: 0 0 12px !important; font-size: 13px; }
.am-card .card-msg-error   { color: #fca5a5; background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.3); border-radius: 10px; }
.am-card .card-msg-success { color: #86efac; background: rgba(34, 197, 94, 0.15); border: 1px solid rgba(34, 197, 94, 0.3); border-radius: 10px; }

/* OTP label centered over the (unchanged) six OTP boxes */
.am-card #fpOtpForm .form-group label { text-align: center; }

/* Recover Email — live selfie block sized for the wide layout
   (colors stay the page's existing .re-* glass styles) */
.am-card .re-section-title { margin: 8px 0 8px; padding-bottom: 6px; border-bottom: 1px solid var(--am-line); }
.am-card .re-camera-hint { text-align: left; margin-bottom: 8px; }
.am-card .re-selfie-wrap { max-width: 360px; }
.am-card .re-snap-btn:disabled { opacity: 0.5; cursor: not-allowed; }

/* ── NEW: TWO-PHASE RECOVER EMAIL (Details → Live Selfie) ── */
#reForm[data-phase="1"] .re-phase-2,
#reForm[data-phase="1"] .re-only-2,
#reForm[data-phase="2"] .re-phase-1,
#reForm[data-phase="2"] .re-only-1 { display: none !important; }
.am-card .re-phase-badge {
    font-size: 11px; font-weight: 700; letter-spacing: 0.4px; text-transform: uppercase;
    color: var(--am-accent); background: rgba(147, 197, 253, 0.14);
    border: 1px solid rgba(147, 197, 253, 0.35); border-radius: 50px; padding: 5px 12px; white-space: nowrap;
}
.am-card .re-phase2-grid { display: grid; grid-template-columns: 1fr 340px; gap: 22px; align-items: start; }
.am-card .re-phase2-grid .re-section-title { margin-top: 0; }
.am-card .re-phase2-grid .re-selfie-wrap { max-width: 340px; width: 100%; }
.am-card .re-summary {
    background: rgba(255, 255, 255, 0.08); border: 1px solid var(--am-line); border-radius: 12px;
    padding: 10px 14px; margin-top: 4px;
}
.am-card .re-summary-title {
    margin: 0 0 6px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; color: var(--am-accent);
}
.am-card .re-summary-row {
    display: flex; justify-content: space-between; gap: 12px; padding: 6px 0;
    border-top: 1px solid rgba(255, 255, 255, 0.10); font-size: 12.5px;
}
.am-card .re-summary-row:first-of-type { border-top: none; }
.am-card .re-summary-row span { color: var(--am-text-muted); white-space: nowrap; }
.am-card .re-summary-row strong { color: #ffffff; font-weight: 600; text-align: right; word-break: break-word; }
.am-card .re-summary-note { font-size: 11px; color: var(--am-text-muted); margin: 8px 0 0; }
.am-card .re-summary-note i { color: var(--am-accent); }

/* ── NEW: compact sizing for the wide Recover Email form so every field
   fits on screen without a scrollbar ── */
.card.am-card.am-card-wide { padding-top: 18px; }
.am-card.am-card-wide .am-modal-header { margin-bottom: 10px; padding-bottom: 8px; }
.am-card.am-card-wide .am-modal-intro { margin-bottom: 10px; font-size: 12.5px; }
.am-card.am-card-wide .form-group { margin-bottom: 9px; }
.am-card.am-card-wide .form-group label { margin-bottom: 4px; font-size: 11.5px; }
.am-card.am-card-wide .form-group input:not(.otp-box),
.am-card.am-card-wide .form-group select { padding: 8px 12px; font-size: 13.5px; height: 38px; }
.am-card.am-card-wide .form-group select { padding-right: 38px; } /* NEW: room for the chevron */
.am-card.am-card-wide .form-group textarea { padding: 8px 12px; font-size: 13.5px; min-height: 38px; height: 38px; }
.am-card.am-card-wide .help-text { margin-top: 3px; font-size: 10.5px; }
.am-card.am-card-wide .modal-actions { padding: 12px 0 16px 0; }
.am-card.am-card-wide .btn-submit,
.am-card.am-card-wide .btn-cancel-modal { padding-top: 10px; padding-bottom: 10px; }

/* Responsive — same 3 → 2 → 1 column steps as the admin modal
   (breakpoints kept as they were) */
@media (max-width: 1100px) {
    .am-card.am-card-wide .am-grid { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 760px) {
    .card.am-card { padding: 16px 16px 0 16px; }
    .am-card .modal-actions { padding: 12px 0 16px 0; }
    .am-card.am-card-wide .am-grid { grid-template-columns: 1fr; }
    .am-card.am-card-wide .am-grid .span-2,
    .am-card .am-grid .span-3 { grid-column: auto; }
    .am-card .re-phase2-grid { grid-template-columns: 1fr; }
}

/* ═══════════════════════════════════════════════════════════════════
   NEW: COMPANY_REGISTER.PHP NAV HEADER + FORM DESIGN
   ------------------------------------------------------------------
   Applied ONLY to the Forgot Password (steps 4 & 5) and Recover Email
   (step 8) screens. Every rule is scoped under body.reg-design, a class
   PHP adds to <body> just for those steps, so the login screen, OTP
   login, popups, loading page and everything else keep their
   original look. Presentation only — no ids, names, forms, scripts or
   PHP flow are changed.
   ═══════════════════════════════════════════════════════════════════ */
body.reg-design {
    --bg: #fcfaf7;
    --text: #2d1b1b;
    --grid-bg: #EEF1F6;
    --grid-navy: #1B2A4A;
    --grid-border: #C3CADA;
    --grid-red: #A02A2A;
    --grid-muted: #5A6272;
    background: #EEF1F6;   /* UPDATED: same light background as the login screen */
    background-attachment: scroll;
    color: var(--text);
    justify-content: flex-start;
    align-items: stretch;
    gap: 0;
}
body.reg-design::before,
body.reg-design::after { display: none; }

/* ── UPDATED (login redesign): no nav header and no separate logo block on these screens — the logo
      and title live in the split card's navy left panel. ── */

/* ── PAGE CONTAINER ── */
body.reg-design .reg-page-container {
    flex: 1;
    padding: 30px 20px;
    display: flex;
    justify-content: center;   /* horizontally centered on the page */
    align-items: center;       /* UPDATED: vertically centered in the space under the navbar */
}
body.reg-design .reg-page-container > .card { margin: auto; }

/* ── FORM CARD — flat, bordered, square corners (card widths stay as before) ── */
body.reg-design .card.am-card {
    --am-text: #1B2A4A;
    --am-text-soft: #4a5568;
    --am-text-muted: #5A6272;
    --am-line: #C3CADA;
    --am-field-bg: #ffffff;
    --am-field-border: #C3CADA;
    --am-accent: #1B2A4A;
    --am-required: #A02A2A;
    background: #ffffff;
    backdrop-filter: none;
    -webkit-backdrop-filter: none;
    border: 1px solid var(--grid-border);
    border-radius: 0;
    box-shadow: none;
    color: var(--text);
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    max-height: calc(100vh - 150px);
    max-height: calc(100dvh - 150px);
    scrollbar-color: #C3CADA transparent;
}
body.reg-design .card.am-card::-webkit-scrollbar-thumb { background: #C3CADA; }
body.reg-design .am-card .am-modal-header h3 { color: var(--grid-navy); }
body.reg-design .am-card .am-close-btn:hover { color: var(--grid-navy); }
body.reg-design .am-card .am-modal-intro strong { color: var(--grid-navy); }

/* ── LABELS & FIELDS ── */
body.reg-design .card.am-card .form-group label {
    text-transform: none; letter-spacing: 0; font-weight: 600; color: #1e293b; font-size: 12.5px;
}
body.reg-design .card.am-card .form-group input:not(.otp-box),
body.reg-design .card.am-card .form-group select,
body.reg-design .card.am-card .form-group textarea {
    background-color: #ffffff; color: var(--text);
    border: 1px solid var(--grid-border); border-radius: 0;
}
body.reg-design .card.am-card .form-group input:not(.otp-box)::placeholder,
body.reg-design .card.am-card .form-group textarea::placeholder { color: #a0aec0; }
body.reg-design .card.am-card .form-group input:not(.otp-box):focus,
body.reg-design .card.am-card .form-group select:focus,
body.reg-design .card.am-card .form-group textarea:focus {
    border-color: var(--grid-navy); background-color: #ffffff; box-shadow: none;
}
body.reg-design .card.am-card .form-group select,
body.reg-design .card.am-card .form-group select:focus {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1.5l5 5 5-5' fill='none' stroke='%231B2A4A' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
}

/* ── OTP (step 5) ── */
body.reg-design .otp-ring-bg { stroke: #e2e8f0; }
body.reg-design .otp-ring-time { color: var(--grid-navy); }
body.reg-design .otp-countdown-label { color: var(--grid-muted); }
body.reg-design .otp-expired-msg { border-radius: 0; color: var(--grid-red); background: #fdf2f2; border-color: #f0c4c4; }
body.reg-design .otp-box { border: 1px solid var(--grid-border); border-radius: 0; background: #ffffff; caret-color: var(--grid-navy); }
body.reg-design .otp-box:focus { border-color: var(--grid-navy); box-shadow: 0 0 0 3px rgba(27,42,74,0.15); transform: none; }
body.reg-design .otp-box.otp-box-filled { border-color: var(--grid-navy); }

/* ── BUTTONS ── */
body.reg-design .card.am-card .btn-submit {
    background: var(--grid-navy); color: #ffffff; border: none; border-radius: 0; font-weight: 600;
    text-transform: uppercase; letter-spacing: 0.3px; transition: opacity 0.2s;
}
body.reg-design .card.am-card .btn-submit:hover { background: var(--grid-navy); transform: none; box-shadow: none; opacity: 0.9; }
body.reg-design .card.am-card .btn-submit:disabled { opacity: 0.5; }
body.reg-design .card.am-card .btn-cancel-modal {
    background: #ffffff; color: var(--grid-navy); border: 1px solid var(--grid-border); border-radius: 0; font-weight: 600;
}
body.reg-design .card.am-card .btn-cancel-modal:hover { background: var(--grid-bg); color: var(--grid-navy); }

/* ── STATUS MESSAGES (!important beats the inline dark-theme colors these
      messages carry, on these screens only) ── */
body.reg-design .card-msg { border-radius: 0 !important; }
body.reg-design .card-msg-error   { color: var(--grid-red) !important; background: #fdf2f2 !important; border: 1px solid #f0c4c4 !important; }
body.reg-design .card-msg-success { color: #2C5A2C !important; background: #EAF3EA !important; border: 1px solid #b7d7b7 !important; }

/* ── RECOVER EMAIL — details / selfie phases ── */
body.reg-design .re-section-title { color: var(--grid-navy); }
body.reg-design .re-camera-hint { color: var(--grid-muted); }
body.reg-design .re-camera-box { border-radius: 0; }
body.reg-design .re-snap-btn { background: var(--grid-navy); border-radius: 0; }
body.reg-design .re-snap-btn:hover { background: var(--grid-navy); opacity: 0.9; }
body.reg-design .re-retake-btn { background: #ffffff; color: var(--grid-navy); border: 1px solid var(--grid-border); border-radius: 0; }
body.reg-design .re-preview-img { border: 2px solid var(--grid-navy); border-radius: 0; }
body.reg-design .am-card .re-phase-badge { background: var(--grid-bg); border: 1px solid var(--grid-border); border-radius: 0; color: var(--grid-navy); }
body.reg-design .am-card .re-summary { background: var(--grid-bg); border: 1px solid var(--grid-border); border-radius: 0; }
body.reg-design .am-card .re-summary-row { border-top-color: var(--grid-border); }
body.reg-design .am-card .re-summary-row strong { color: #1e293b; }

/* ═══════════════════════════════════════════════════════════════════
   NEW: LOGIN REDESIGN — "Style D" split card (steps 1 & 2)
   ------------------------------------------------------------------
   Navy panel (logo + campus + system title) on the left, white form on
   the right, in the admin_student_list.php palette (navy #1B2A4A, gold
   #FFD700, border #C3CADA, light #EEF1F6, square corners). Presentation
   only — form names, ids used by scripts, PHP flow and popups are untouched.
   ═══════════════════════════════════════════════════════════════════ */
.card.login-split {
    --grid-navy: #1B2A4A; --grid-border: #C3CADA; --grid-bg: #EEF1F6;
    --grid-red: #A02A2A;  --grid-muted: #5A6272;
    display: flex; align-items: stretch;
    width: 620px; max-width: calc(100% - 40px); min-height: 340px;
    padding: 0; box-sizing: border-box;
    background: #ffffff;
    backdrop-filter: none; -webkit-backdrop-filter: none;
    border: 1px solid var(--grid-border); border-radius: 0; box-shadow: none;
    text-align: left; color: #1e293b;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}
.login-split .ls-left {
    position: relative; width: 210px; flex-shrink: 0; box-sizing: border-box;
    background: var(--grid-navy);
    border-top: 3px solid #FFD700; margin-top: -1px;
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    padding: 24px 18px; text-align: center;
}
.login-split .ls-left::after {
    content: ''; position: absolute; right: -9px; top: 50%; margin-top: -9px;
    border-left: 9px solid var(--grid-navy);
    border-top: 9px solid transparent; border-bottom: 9px solid transparent;
}
.login-split .ls-logo {
    width: 84px; height: 84px; border-radius: 50%; background: #ffffff;
    display: flex; align-items: center; justify-content: center;
    overflow: hidden; margin-bottom: 16px; box-sizing: border-box; padding: 6px;
}
.login-split .ls-logo img { width: 100%; height: 100%; object-fit: contain; display: block; }
.login-split .ls-campus { margin: 0 0 6px; font-size: 10.5px; font-weight: 700; color: #FFD700; text-transform: uppercase; letter-spacing: 1.5px; }
.login-split .ls-title  { margin: 0; font-size: 12px; line-height: 1.5; color: rgba(255,255,255,0.80); }

.login-split .ls-right {
    flex: 1; min-width: 0; box-sizing: border-box;
    padding: 32px 32px 24px 36px;
    display: flex; flex-direction: column; justify-content: center;
}
.login-split .input-group { margin-bottom: 12px; }
.login-split .ls-sr { position: absolute; width: 1px; height: 1px; margin: -1px; padding: 0; overflow: hidden; clip: rect(0,0,0,0); border: 0; white-space: nowrap; }
.login-split label { color: var(--grid-navy); }

/* icon-cell fields */
.login-split .ls-field { display: flex; align-items: stretch; border: 1px solid var(--grid-border); background: #ffffff; transition: border-color 0.2s; }
.login-split .ls-field:focus-within { border-color: var(--grid-navy); }
.login-split .ls-ic { width: 38px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; background: var(--grid-bg); border-right: 1px solid var(--grid-border); color: var(--grid-navy); font-size: 14px; }
.login-split .ls-field .password-wrapper { flex: 1; min-width: 0; }
.login-split .ls-field input {
    flex: 1; min-width: 0; width: 100%; height: 40px; padding: 0 12px; box-sizing: border-box;
    border: none; border-radius: 0; background: #ffffff; color: #1e293b; font-size: 13.5px;
    font-family: inherit;
}
.login-split .ls-field input::placeholder { color: #a0aec0; }
.login-split .ls-field input:focus { border: none; background: #ffffff; outline: none; }
.login-split .ls-field #password { padding-right: 58px; }
.login-split .toggle-btn { color: var(--grid-navy); right: 12px; font-family: inherit; }

/* remember me + forgot password row */
.login-split .ls-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin: 4px 0 16px; }
.login-split .remember-me-row { margin: 0; }
.login-split .remember-me-row input[type="checkbox"] { border: 1.5px solid var(--grid-border); background: #ffffff; accent-color: var(--grid-navy); border-radius: 0; }
.login-split .remember-me-row .rm-label { color: var(--grid-muted); font-size: 12px; }
.login-split .ls-forgot { background: none; border: none; padding: 0; cursor: pointer; font-family: inherit; font-size: 12px; font-style: italic; color: var(--grid-navy); white-space: nowrap; }
.login-split .ls-forgot:hover { text-decoration: underline; }
.login-split .remember-me-hint { padding-left: 0; margin: 10px 0 0; color: var(--grid-muted); font-size: 11px; }
/* UPDATED: subtle informational note — no box/border, small muted text and a soft "i" icon,
   so it no longer competes with the input fields and the Login button. */
.login-split .remember-me-active-badge {
    background: transparent; border: none; border-radius: 0; padding: 0 2px;
    margin: 0 0 12px; gap: 7px; align-items: flex-start;
    color: #7b8497; font-size: 11px; font-weight: 500; line-height: 1.5;
}
.login-split .remember-me-active-badge .badge-icon { font-size: 12px; margin-top: 1px; color: #98a1b3; }

/* buttons */
.login-split .login-submit {
    width: auto; min-width: 130px; display: block; margin-top: 0; padding: 11px 26px;
    background: var(--grid-navy); color: #ffffff; border: none; border-radius: 0;
    font-family: inherit; font-weight: 600; font-size: 12.5px; text-transform: uppercase; letter-spacing: 1px;
    transition: opacity 0.2s;
}
.login-split .login-submit:hover { background: var(--grid-navy); transform: none; box-shadow: none; opacity: 0.9; }
/* UPDATED: the step-1 Login button now spans the same width and height (42px) as the input fields. */
.login-split .ls-login-btn { width: 100%; height: 42px; padding: 0 26px; box-sizing: border-box; }
.login-split .btn-secondary {
    background: #ffffff; color: var(--grid-navy); border: 1px solid var(--grid-border); border-radius: 0;
    font-family: inherit; font-weight: 600; font-size: 12.5px; text-transform: uppercase; letter-spacing: 0.5px;
}
.login-split .btn-secondary:hover { background: var(--grid-bg); color: var(--grid-navy); }

/* step 2 (login OTP) */
.login-split .ls-otp .login-submit,
.login-split .ls-otp .btn-secondary { width: 100%; }
.login-split .ls-otp .input-group label { text-align: center; }
.login-split .otp-ring-bg { stroke: #e2e8f0; }
.login-split .otp-ring-time { color: var(--grid-navy); }
.login-split .otp-countdown-label { color: var(--grid-muted); }
.login-split .otp-expired-msg { border-radius: 0; color: var(--grid-red); background: #fdf2f2; border-color: #f0c4c4; }
.login-split .otp-box { border: 1px solid var(--grid-border); border-radius: 0; background: #ffffff; caret-color: var(--grid-navy); }
.login-split .otp-box:focus { border-color: var(--grid-navy); box-shadow: 0 0 0 3px rgba(27,42,74,0.15); transform: none; }
.login-split .otp-box.otp-box-filled { border-color: var(--grid-navy); }

/* status messages on the light panel */
.login-split .card-msg { text-align: left !important; border-radius: 0 !important; font-size: 13px; }
.login-split .card-msg-error   { color: var(--grid-red) !important; background: #fdf2f2 !important; border: 1px solid #f0c4c4 !important; }
.login-split .card-msg-success { color: #2C5A2C !important; background: #EAF3EA !important; border: 1px solid #b7d7b7 !important; }

@media (max-width: 760px) {
    .card.login-split { flex-direction: column; }
    .login-split .ls-left { width: auto; margin-top: -1px; padding: 20px 18px; }
    .login-split .ls-left::after { right: auto; left: 50%; top: auto; bottom: -9px; margin: 0 0 0 -9px; border-left: 9px solid transparent; border-right: 9px solid transparent; border-top: 9px solid var(--grid-navy); border-bottom: none; }
    .login-split .ls-right { padding: 24px 20px 20px; }
}

/* ═══════════════════════════════════════════════════════════════════
   NEW: POPUP NOTIFICATIONS — Style D
   ------------------------------------------------------------------
   Every popup on this page (login notification, deactivated, company /
   admin redirect, password-changed) now uses the same look as the login
   card: white flat box, thin #C3CADA border, gold top line, navy uppercase
   title/buttons, square corners, Segoe UI. Colors/shape only — ids, texts,
   buttons and the scripts that open/close them are unchanged.
   ═══════════════════════════════════════════════════════════════════ */
#fpSuccessOverlay,
#companyRedirectOverlay,
#adminRedirectOverlay,
#deactivatedOverlay,
#loginNotifyOverlay { background: rgba(27,42,74,0.55); }
#fpSuccessBox,
#companyRedirectBox,
#adminRedirectBox,
#deactivatedBox,
#loginNotifyBox {
    background: #ffffff; border: 1px solid #C3CADA; border-top: 3px solid #FFD700; border-radius: 0;
    padding: 30px 32px 26px; width: 380px; max-width: 92%; box-sizing: border-box;
    box-shadow: 0 14px 36px rgba(27,42,74,0.28);
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}
#fpSuccessBox .sp-icon,
#companyRedirectBox .sp-icon,
#adminRedirectBox .sp-icon,
#deactivatedBox .sp-icon,
#loginNotifyBox .ln-icon { display: flex; align-items: center; justify-content: center; width: 56px; height: 56px; margin: 0 auto 16px; border-radius: 50%; background: #1B2A4A; color: #ffffff; font-size: 24px; }
#deactivatedBox .sp-icon,
#loginNotifyBox .ln-icon { background: #A02A2A; }
#loginNotifyBox.ln-info .ln-icon { background: #1B2A4A; }
#fpSuccessBox .sp-title,
#companyRedirectBox .sp-title,
#adminRedirectBox .sp-title,
#deactivatedBox .sp-title,
#loginNotifyBox .ln-title { margin: 0 0 8px; color: #1B2A4A; font-size: 15px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; }
#deactivatedBox .sp-title,
#loginNotifyBox .ln-title { color: #A02A2A; }
#loginNotifyBox.ln-info .ln-title { color: #1B2A4A; }
#fpSuccessBox .sp-msg,
#companyRedirectBox .sp-msg,
#adminRedirectBox .sp-msg,
#deactivatedBox .sp-msg,
#loginNotifyBox .ln-msg { margin: 0 0 22px; color: #5A6272; font-size: 13px; line-height: 1.6; }
#fpSuccessBox .sp-btn,
#companyRedirectBox .sp-btn,
#adminRedirectBox .sp-btn,
#deactivatedBox .sp-btn,
#loginNotifyBox .ln-btn { padding: 11px 28px; background: #1B2A4A; color: #ffffff; border: none; border-radius: 0; font-family: inherit; font-size: 12.5px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.8px; cursor: pointer; transition: opacity 0.2s; }
#fpSuccessBox .sp-btn:hover,
#companyRedirectBox .sp-btn:hover,
#adminRedirectBox .sp-btn:hover,
#deactivatedBox .sp-btn:hover,
#loginNotifyBox .ln-btn:hover { background: #1B2A4A; opacity: 0.9; }
#companyRedirectBox .sp-btn-cancel,
#adminRedirectBox .sp-btn-cancel { padding: 11px 24px; background: #ffffff; color: #1B2A4A; border: 1px solid #C3CADA; border-radius: 0; font-family: inherit; font-size: 12.5px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.8px; cursor: pointer; transition: 0.2s; }
#companyRedirectBox .sp-btn-cancel:hover,
#adminRedirectBox .sp-btn-cancel:hover { background: #EEF1F6; }
#companyRedirectBox .sp-btn-row,
#adminRedirectBox .sp-btn-row { gap: 10px; }

/* ═══════════════════════════════════════════════════════════════════
   NEW: split-card design on Forgot Password (4), its OTP (5) and Recover Email (8)
   ------------------------------------------------------------------
   These cards keep their .am-card form styling and simply gain the navy left
   panel (see .ls-left above), so the form fields/buttons keep the flat student-list look.
   ═══════════════════════════════════════════════════════════════════ */
.card.am-card.login-split { flex-direction: row; width: 620px; max-width: calc(100% - 40px); padding: 0; overflow: visible; max-height: none; }
.card.am-card.am-card-wide.login-split { width: 940px; padding: 0; }
body.reg-design .card.am-card.login-split { max-height: none; overflow: visible; }
.login-split.am-card .ls-right { justify-content: flex-start; padding: 26px 30px 6px 32px; }
.login-split.am-card .ls-left { align-self: stretch; }

/* Forgot Password / Recover Email links on the login card */
.login-split .ls-links { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-top: 16px; padding-top: 14px; border-top: 1px solid #DCE1EC; }
.login-split .ls-links .ls-forgot i { margin-right: 5px; font-style: normal; }

@media (max-width: 1280px) {
    .card.am-card.am-card-wide.login-split .am-grid { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 760px) {
    .card.am-card.login-split { flex-direction: column; padding: 0; }
    .card.am-card.am-card-wide.login-split { padding: 0; }
    .card.am-card.am-card-wide.login-split .am-grid { grid-template-columns: 1fr; }
    .login-split.am-card .ls-right { padding: 20px 16px 4px; }
}

/* ═══════════════════════════════════════════════════════════════════
   NEW (this adjustment): RECOVER EMAIL SIZE  +  FORGOT-PASSWORD OTP LAYOUT
   ------------------------------------------------------------------
   Presentation only — no ids, names, forms, scripts or PHP flow changed.

   1) Recover Email (step 8) was too big for the page: the card was 940px
      wide and flipped to a tall 2-column grid at <= 1280px. It is now a
      smaller card (800px) with a slimmer navy panel, tighter spacing and
      shorter fields, and it keeps the 3-column grid on any screen wider
      than 900px so it fits without a huge scroll. Phones / narrow screens
      (<= 760px) keep the original stacked layout untouched.
   2) Forgot-Password OTP (step 5): the info block (intro, countdown ring,
      OTP boxes, hint) is centered as one column, and the action buttons
      are placed as: [ Verify & Reset Password ] on its own full-width
      row, then [ Resend OTP ] [ Cancel ] side by side underneath.
   ═══════════════════════════════════════════════════════════════════ */

/* ── 1) RECOVER EMAIL (step 8) — smaller card ── */
@media (min-width: 761px) {
    .card.am-card.am-card-wide.login-split { width: 800px; max-width: calc(100% - 40px); }
    .card.am-card.am-card-wide.login-split .ls-left { width: 160px; padding: 16px 12px; }
    .card.am-card.am-card-wide.login-split .ls-logo { width: 62px; height: 62px; margin-bottom: 10px; padding: 5px; }
    .card.am-card.am-card-wide.login-split .ls-campus { font-size: 9.5px; letter-spacing: 1.2px; }
    .card.am-card.am-card-wide.login-split .ls-title { font-size: 10.5px; line-height: 1.45; }
    .login-split.am-card.am-card-wide .ls-right { padding: 16px 22px 0 24px; }

    .card.am-card.am-card-wide.login-split .am-modal-header { margin-bottom: 8px; padding-bottom: 7px; }
    .card.am-card.am-card-wide.login-split .am-modal-intro { margin-bottom: 8px; font-size: 12px; line-height: 1.45; }
    .card.am-card.am-card-wide.login-split .am-grid { column-gap: 12px; }
    .card.am-card.am-card-wide.login-split .form-group { margin-bottom: 7px; }
    .card.am-card.am-card-wide.login-split .form-group label { margin-bottom: 3px; font-size: 11.5px; }
    .card.am-card.am-card-wide.login-split .form-group input:not(.otp-box),
    .card.am-card.am-card-wide.login-split .form-group select { height: 34px; padding: 6px 10px; font-size: 13px; text-overflow: ellipsis; }
    .card.am-card.am-card-wide.login-split .form-group select { padding-right: 32px; }
    .card.am-card.am-card-wide.login-split .form-group textarea { height: 34px; min-height: 34px; padding: 6px 10px; font-size: 13px; }
    .card.am-card.am-card-wide.login-split .help-text { margin-top: 2px; font-size: 10px; line-height: 1.3; }
    .card.am-card.am-card-wide.login-split .modal-actions { margin-top: 2px; padding: 10px 0 12px; gap: 8px; }
    .card.am-card.am-card-wide.login-split .btn-submit,
    .card.am-card.am-card-wide.login-split .btn-cancel-modal { padding: 9px 18px; font-size: 12px; }

    /* selfie phase (step 2 of 2) */
    .card.am-card.am-card-wide.login-split .re-phase2-grid { grid-template-columns: 1fr 250px; gap: 18px; }
    .card.am-card.am-card-wide.login-split .re-phase2-grid .re-selfie-wrap { max-width: 250px; }
    .card.am-card.am-card-wide.login-split .re-summary { padding: 8px 12px; }
    .card.am-card.am-card-wide.login-split .re-summary-row { padding: 4px 0; font-size: 12px; }
    .card.am-card.am-card-wide.login-split .re-snap-btn { padding: 9px; font-size: 12.5px; }
    .card.am-card.am-card-wide.login-split .re-retake-btn { padding: 8px; }
}
/* keep the 3-column grid on normal laptop/desktop widths (was 2 columns at <= 1280px) */
@media (min-width: 901px) {
    .card.am-card.am-card-wide.login-split .am-grid { grid-template-columns: repeat(3, 1fr); }
}

/* ── 2) FORGOT-PASSWORD OTP (step 5) — tidy layout ── */
.am-card .fp-otp-intro { text-align: center; margin-bottom: 8px; }
.am-card .fp-otp-intro strong { overflow-wrap: anywhere; word-break: break-word; }
.am-card .otp-countdown-wrap { margin: 0 0 10px; gap: 4px; }
.am-card .otp-countdown-ring,
.am-card .otp-countdown-ring svg { width: 68px; height: 68px; }
.am-card .otp-ring-time { font-size: 15px; }
.am-card .otp-countdown-label { font-size: 10.5px; }

#fpOtpForm .form-group { margin-bottom: 0; }
#fpOtpForm .form-group label { margin-bottom: 8px; }
#fpOtpForm .otp-box-group { gap: 8px; margin: 0 0 8px; }
#fpOtpForm .otp-box { flex: 1 1 0; min-width: 0; max-width: 44px; width: auto; height: 48px; font-size: 19px; }
#fpOtpForm .help-text { text-align: center; margin-top: 6px; }

/* buttons: Verify on its own row, Resend + Cancel share the row below
   (DOM order is unchanged, so pressing Enter still triggers "Verify") */
#fpOtpForm .modal-actions {
    display: grid; grid-template-columns: 1fr 1fr; gap: 8px;
    margin: 12px 0 0; padding: 14px 0 18px;
}
#fpOtpForm .modal-actions .btn-submit { grid-column: 1 / -1; }
#fpOtpForm .modal-actions .btn-submit,
#fpOtpForm .modal-actions .btn-cancel-modal {
    width: 100%; box-sizing: border-box; padding: 11px 10px; text-align: center; white-space: nowrap;
}

/* ═══════════════════════════════════════════════════════════════════
   NEW: LOGIN CARD ENLARGED (steps 1 & 2 only)
   ------------------------------------------------------------------
   Makes the login card a little bigger (620x340 -> 720x400): wider card,
   wider navy panel + logo, taller fields (40 -> 48px) with slightly larger
   text/icons, a taller Login button (42 -> 50px) and roomier spacing.
   Scoped with :not(.am-card) so Forgot Password (4), its OTP (5) and
   Recover Email (8) keep their current size, and wrapped in a min-width
   query so the stacked mobile layout (<= 760px) is untouched. Sizes only —
   no colors, PHP flow, form names, ids or scripts were changed.
   ═══════════════════════════════════════════════════════════════════ */
@media (min-width: 761px) {
    .card.login-split:not(.am-card) { width: 720px; min-height: 400px; }
    .card.login-split:not(.am-card) .ls-left { width: 240px; padding: 28px 22px; }
    .card.login-split:not(.am-card) .ls-logo { width: 100px; height: 100px; margin-bottom: 18px; }
    .card.login-split:not(.am-card) .ls-campus { font-size: 12px; }
    .card.login-split:not(.am-card) .ls-title { font-size: 13.5px; }
    .card.login-split:not(.am-card) .ls-right { padding: 40px 40px 30px 44px; }
    .card.login-split:not(.am-card) .input-group { margin-bottom: 16px; }
    .card.login-split:not(.am-card) .ls-ic { width: 46px; font-size: 16px; }
    .card.login-split:not(.am-card) .ls-field input { height: 48px; font-size: 15px; }
    .card.login-split:not(.am-card) .toggle-btn { font-size: 12px; }
    .card.login-split:not(.am-card) .ls-row { margin: 6px 0 20px; }
    .card.login-split:not(.am-card) .remember-me-row input[type="checkbox"] { width: 17px; height: 17px; min-width: 17px; }
    .card.login-split:not(.am-card) .remember-me-row .rm-label { font-size: 13px; }
    .card.login-split:not(.am-card) .ls-login-btn { height: 50px; font-size: 14px; }
    .card.login-split:not(.am-card) .ls-forgot { font-size: 13px; }
    .card.login-split:not(.am-card) .ls-links { margin-top: 20px; padding-top: 18px; }
}

/* ═══════════════════════════════════════════════════════════════════
   NEW: ANIMATED BACKGROUND — Option A (all steps)
   ------------------------------------------------------------------
   Fixed full-screen decorative layer behind the whole page (see the
   .bg-anim markup + drawing script near the top of <body>). Navy
   diagonal bands + gold lines flow across the page in a loop with dot patterns; it is
   drawn on a single <canvas> for smooth sub-pixel motion. Sits under
   every card/popup (z-index 0) and ignores the mouse. Colors match the
   page palette (navy #1B2A4A, gold #FFD700, light #EEF1F6).
   ═══════════════════════════════════════════════════════════════════ */
.bg-anim { position: fixed; top: 0; left: 0; right: 0; bottom: 0; overflow: hidden; pointer-events: none; z-index: 0; }
.bg-anim canvas { display: block; width: 100%; height: 100%; }

/* UPDATED (smoother page): the hidden "loading" overlay kept its spinner/dots animations
   running invisibly behind the page, which competes with the background for frames. They are
   paused while the overlay is hidden; nothing changes visually (they run again when it shows). */
#globalLoadingOverlay.hidden,
#globalLoadingOverlay.hidden * { animation-play-state: paused; }

/* NEW (this adjustment): optional sub-line under the loading label. It is only
   filled in for the Forgot Password "Sending OTP" loading page; on every other
   loading screen it stays empty and hidden, so those look exactly as before. */
.global-loading-sub {
    display: none; max-width: 300px; margin-top: -6px; text-align: center;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    font-size: 12px; line-height: 1.5; color: var(--grid-muted, #5B6478);
}
.global-loading-sub.show { display: block; }
#globalLoadingOverlay.success-state .global-loading-sub { display: none; }

/* UPDATED: login OTP (step 2) - "OTP expires in" sits at the top-left of the countdown circle.
   Scoped to .ls-otp (login OTP) and .am-card (Forgot Password OTP). */
.ls-otp .otp-countdown-ring .otp-countdown-label,
.am-card .otp-countdown-ring .otp-countdown-label {   /* UPDATED: same placement on the Forgot Password OTP (step 5) */
    position: absolute; top: 4px; right: calc(100% + 2px);
    white-space: nowrap; text-align: right; line-height: 1.2;
}
</style>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<?php
// NEW: the company_register.php nav header + form design is applied ONLY to the
// Forgot Password (steps 4 & 5) and Recover Email (step 8) screens.
$regDesign = in_array((int)$step, [4, 5, 8], true);
// NEW (login redesign): the split-card design (navy panel + form) is used for login (1), login OTP (2),
// Forgot Password (4), Forgot Password OTP (5) and Recover Email (8).
$loginSplit = in_array((int)$step, [1, 2, 4, 5, 8], true);
?>
<body<?= $regDesign ? ' class="reg-design"' : '' ?>>

<!-- ══════════════════════════════════════════════════════════
     NEW: Global loading page (same as admin_student_list.php).
     Visible by default (covers the page while it loads), hidden once
     the page has finished loading, and shown again whenever the page
     is left. On step 9 (email recovery request saved) it opens in its
     success state — check icon + message — and then returns to login.
     ══════════════════════════════════════════════════════════ -->
<?php
// UPDATED (this adjustment): the success state is also used for step 6
// (password changed) — it replaces the old "Password Successfully Changed!"
// popup, exactly like the email recovery "Request Submitted" screen.
$glsSuccessStep  = in_array((int)$step, [6, 9], true);
// UPDATED (this adjustment): when this page is the result of pressing "Send OTP" (Forgot Password step 4)
// or "Resend OTP" (step 5), the previous page has already been showing the "Sending OTP" loading page for
// the whole wait. The normal "Loading..." page that used to flash up again here on load is skipped, so the
// "Sending OTP" page simply stays until this page is ready and then goes straight to the result.
$glsSkipInitial  = !$glsSuccessStep && (isset($_POST['fp_submit_email']) || isset($_POST['fp_resend_otp']) || isset($_POST['send_otp']) || isset($_POST['verify_otp']) || isset($_POST['fp_verify_otp']));  // UPDATED: also the login OTP "Send OTP" (step 2) and both "Verify" buttons ("Verifying OTP" page)
$glsSuccessTitle = ((int)$step === 9) ? 'Request Submitted' : (((int)$step === 6) ? 'Password Changed' : 'Success');
$glsSuccessMsg   = ((int)$step === 9)
    ? 'Your email recovery request has been sent to the administrator for review. You will be notified once your request is approved.'
    : (((int)$step === 6) ? 'Your new password has been sent to your email address. Please log in with your new password.' : '');
?>
<div id="globalLoadingOverlay"<?= $glsSuccessStep ? ' class="success-state"' : ($glsSkipInitial ? ' class="hidden"' : '') ?>>
    <div class="global-loading-box">
        <div class="global-loading-spinner"></div>
        <div class="global-loading-text">
            <span id="globalLoadingLabel">Loading</span>
            <span class="global-loading-dots"><span>.</span><span>.</span><span>.</span></span>
        </div>
        <div class="global-loading-sub" id="globalLoadingSub"></div>
        <div class="global-loading-success" id="globalLoadingSuccess" role="status" aria-live="polite">
            <div class="gls-check"><i class="fas fa-check"></i></div>
            <div class="gls-title" id="globalLoadingSuccessTitle"><?= htmlspecialchars($glsSuccessTitle) ?></div>
            <div class="gls-message" id="globalLoadingSuccessMsg"><?= htmlspecialchars($glsSuccessMsg) ?></div>
            <div class="gls-sub"><i class="fas fa-sync-alt fa-spin"></i> <span id="globalLoadingSuccessSub">Returning to the login page...</span></div>
        </div>
    </div>
</div>
<script>
(function () {
    'use strict';
    var ov    = document.getElementById('globalLoadingOverlay');
    var label = document.getElementById('globalLoadingLabel');
    var sub   = document.getElementById('globalLoadingSub');
    if (!ov) return;

    // NEW: text used when the page is left (e.g. "Sending OTP"); empty = the normal "Loading"
    var pendingText = '', pendingSub = '';
    function setSub(t) { if (sub) { sub.textContent = t || ''; sub.classList.toggle('show', !!t); } }

    var MIN_VISIBLE_MS = 450;   // always visible for at least this long on a page load
    var start = Date.now();
    var initialDone = false;

    function isSuccess() { return ov.classList.contains('success-state'); }

    // 1) page load: hide once loaded (never while the success screen is up)
    function finishInitialLoad() {
        if (initialDone) return;
        initialDone = true;
        if (!isSuccess()) ov.classList.add('hidden');
    }
    function afterLoad() { setTimeout(finishInitialLoad, Math.max(0, MIN_VISIBLE_MS - (Date.now() - start))); }
    if (document.readyState === 'complete') afterLoad(); else window.addEventListener('load', afterLoad);
    setTimeout(finishInitialLoad, 4000);   // safety net if a slow asset holds up 'load'

    // 2) leaving the page (form submits, links, redirects): show it right away
    window.addEventListener('beforeunload', function () {
        if (isSuccess()) return;   // keep the check + message visible while leaving
        if (label) label.textContent = pendingText || 'Loading';
        setSub(pendingSub);
        ov.classList.remove('hidden');
    });

    // 3) back/forward cache restore: clear anything left over from leaving
    window.addEventListener('pageshow', function (e) {
        if (e.persisted) {
            initialDone = true;
            ov.classList.remove('success-state');
            ov.classList.add('hidden');
            pendingText = ''; pendingSub = ''; setSub('');   // NEW: clear any "Sending OTP" text
        }
    });

    // helpers (same names as admin_student_list.php)
    window.showGlobalLoading = function (text) {
        if (label) label.textContent = text || 'Loading';
        setSub(pendingSub);
        ov.classList.remove('hidden');
    };
    // NEW: remember the label / sub-line to show while the page is being left
    window.setGlobalLoadingOnLeave = function (text, subText) {
        pendingText = text || ''; pendingSub = subText || '';
    };
    window.hideGlobalLoading = function () {
        if (!isSuccess()) ov.classList.add('hidden');
        pendingText = ''; pendingSub = ''; setSub('');   // NEW
    };
})();
</script>

<!-- ══════════════════════════════════════════════════════════════
     UPDATED: SIDE MENU REMOVED + ANIMATED BACKGROUND (Option A)
     ------------------------------------------------------------
     The Home / Student Portal / Company Portal / Admin Portal side menu
     was removed from this page, so the cards are now centered on the
     full width of the screen. In its place, a decorative, slowly moving
     background sits behind EVERY screen of this file (login, login OTP,
     forgot password, its OTP and recover email): soft navy diagonal bands
     with a gold edge line flow continuously across the page in a seamless
     loop, carrying small dot patterns along with them.
     It is fixed behind the page (z-index 0, pointer-events none), so it
     never blocks clicks or typing, and it stops moving for visitors whose
     device is set to "reduce motion". Presentation only.
══════════════════════════════════════════════════════════════ -->
<div class="bg-anim" aria-hidden="true"><canvas id="bgCanvas"></canvas></div>
<script>
/* UPDATED (looping flow): the background no longer swings back and forth. Soft navy diagonal
   bands (same angle as the earlier triangles) with a gold edge line now travel continuously
   across the whole page - entering at the bottom-left and leaving at the top-right, then
   repeating seamlessly - while small dot patterns float along the same direction and re-enter
   from the opposite edge when they leave. It is drawn on ONE canvas by a time-based
   requestAnimationFrame loop (exact fractional positions + anti-aliasing = smooth, constant
   speed). Same colors as Option A. It pauses automatically in background tabs and, for
   visitors who prefer reduced motion, draws a single still frame. */
(function () {
    var cv = document.getElementById('bgCanvas');
    if (!cv || !cv.getContext) return;
    var ctx = cv.getContext('2d');
    if (!ctx) return;
    var W = 0, H = 0;
    var reduce = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    var t0 = performance.now();

    /* Flow settings (change these to tune the look) */
    var SPEED  = 30;    /* px per second the bands and dots travel            */
    var PERIOD = 520;   /* distance between two bands (px)                    */
    var BAND   = 170;   /* thickness of each navy band (px)                   */
    var TH = Math.atan(300 / 480);                 /* band angle = old triangle slope (~32 deg) */
    var DX = Math.cos(TH), DY = Math.sin(TH);      /* unit vector ALONG the bands               */
    var NX = -DY, NY = DX;                         /* unit vector ACROSS the bands              */
    /* dot patches: [x fraction, y fraction, width, height, speed multiplier] */
    var PATCHES = [[0.06, 0.07, 130, 52, 1], [0.86, 0.68, 150, 160, 1], [0.32, 0.80, 110, 80, 0.9], [0.66, 0.09, 120, 60, 1.1]];

    function size() {
        var dpr = Math.min(window.devicePixelRatio || 1, 2);
        W = cv.clientWidth; H = cv.clientHeight;
        cv.width = Math.round(W * dpr); cv.height = Math.round(H * dpr);
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    }
    function mod(a, n) { return ((a % n) + n) % n; }
    function bandPath(s, L, w) {
        var px = s * NX, py = s * NY, qx = (s + w) * NX, qy = (s + w) * NY;
        ctx.beginPath();
        ctx.moveTo(px - L * DX, py - L * DY); ctx.lineTo(px + L * DX, py + L * DY);
        ctx.lineTo(qx + L * DX, qy + L * DY); ctx.lineTo(qx - L * DX, qy - L * DY);
        ctx.closePath();
    }
    function goldLine(s, L) {
        var px = s * NX, py = s * NY;
        ctx.beginPath(); ctx.moveTo(px - L * DX, py - L * DY); ctx.lineTo(px + L * DX, py + L * DY);
        ctx.strokeStyle = 'rgba(255,215,0,0.8)'; ctx.lineWidth = 3; ctx.stroke();
    }
    function dots(x, y, w, h) {
        ctx.beginPath();
        for (var gx = x + 2; gx < x + w; gx += 16) {
            for (var gy = y + 2; gy < y + h; gy += 16) {
                ctx.moveTo(gx + 1.6, gy); ctx.arc(gx, gy, 1.6, 0, 6.2832);
            }
        }
        ctx.fillStyle = 'rgba(27,42,74,0.22)'; ctx.fill();
    }
    function draw(t) {
        ctx.clearRect(0, 0, W, H);

        /* bands: their position across the page shifts steadily and wraps every PERIOD px */
        var L = Math.sqrt(W * W + H * H) + 50;
        var s1 = W * NX, s2 = H * NY, s3 = W * NX + H * NY;
        var sMin = Math.min(0, s1, s2, s3), sMax = Math.max(0, s1, s2, s3);
        var off = mod(-SPEED * t, PERIOD);
        for (var k = Math.floor((sMin - off - BAND) / PERIOD); ; k++) {
            var s0 = k * PERIOD + off;
            if (s0 > sMax) break;
            bandPath(s0, L, BAND); ctx.fillStyle = 'rgba(27,42,74,0.07)'; ctx.fill();
            goldLine(s0 + 16, L);
        }

        /* dot patches: drift the same way as the bands and re-enter from the opposite edge */
        for (var i = 0; i < PATCHES.length; i++) {
            var P = PATCHES[i], v = SPEED * P[4];
            var x = mod(P[0] * W + DY * v * t + P[2], W + P[2]) - P[2];
            var y = mod(P[1] * H - DX * v * t + P[3], H + P[3]) - P[3];
            dots(x, y, P[2], P[3]);
        }
    }
    function frame(now) { draw((now - t0) / 1000); requestAnimationFrame(frame); }

    size();
    window.addEventListener('resize', function () { size(); if (reduce) draw(0); });
    if (reduce) { draw(0); } else { requestAnimationFrame(frame); }
})();
</script>

<!-- ── PASSWORD CHANGED SUCCESS POPUP ── -->
<div id="fpSuccessOverlay">
    <div id="fpSuccessBox">
        <span class="sp-icon"><i class="fas fa-lock"></i></span>
        <p class="sp-title">Password Successfully Changed!</p>
        <p class="sp-msg">Your new password has been sent to your email address. Please log in with your new password.</p>
        <button class="sp-btn" onclick="document.getElementById('fpSuccessOverlay').style.display='none'; window.location='login.php';">
            OK, Go to Login
        </button>
    </div>
</div>

<!-- ── COMPANY ACCOUNT REDIRECT POPUP ── -->
<div id="companyRedirectOverlay">
    <div id="companyRedirectBox">
        <span class="sp-icon"><i class="fas fa-building"></i></span>
        <p class="sp-title">Company Account Detected</p>
        <p class="sp-msg">This portal is for student logins only. Please use the Company Login page to access your account.</p>
        <div class="sp-btn-row">
            <button class="sp-btn" onclick="window.location='company_login.php';">OK</button>
            <button class="sp-btn-cancel" onclick="document.getElementById('companyRedirectOverlay').style.display='none';">Cancel</button>
        </div>
    </div>
</div>
<?php if (!empty($showCompanyRedirectPopup)): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('companyRedirectOverlay');
    if (el) el.style.display = 'flex';
});
</script>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════════
     NEW: ADMIN ACCOUNT REDIRECT POPUP
     Shown right after a successful email/password check on step 1
     when the matched account is found in the `admins` table. This
     student-facing login page is not the correct destination for an
     admin account, so the user is offered a one-click redirect to
     the dedicated Admin Portal (admin_login.php) instead. The user
     is kept on the login page — no OTP step is started, and no
     session is created — unless they click "OK".
══════════════════════════════════════════════════════════════ -->
<div id="adminRedirectOverlay">
    <div id="adminRedirectBox">
        <span class="sp-icon"><i class="fas fa-user-shield"></i></span>
        <p class="sp-title">Admin Account Detected</p>
        <p class="sp-msg">This portal is for student logins only. Please use the Admin Portal to access your account.</p>
        <div class="sp-btn-row">
            <button class="sp-btn" onclick="window.location='admin_login.php';">OK</button>
            <button class="sp-btn-cancel" onclick="document.getElementById('adminRedirectOverlay').style.display='none';">Cancel</button>
        </div>
    </div>
</div>
<?php if (!empty($showAdminRedirectPopup)): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('adminRedirectOverlay');
    if (el) el.style.display = 'flex';
});
</script>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════════
     NEW: ACCOUNT DEACTIVATED POPUP
     Shown right after a successful email/password check on step 1
     when the matched account's is_active flag is 0 (toggled off from
     monitoring.php's Status buttons). The user is kept on the login
     page — no OTP step is started, and no session is created.
══════════════════════════════════════════════════════════════ -->
<div id="deactivatedOverlay">
    <div id="deactivatedBox">
        <span class="sp-icon"><i class="fas fa-ban"></i></span>
        <p class="sp-title">Account Deactivated</p>
        <p class="sp-msg">Your account has been deactivated by the administrator and can no longer log in. Please contact your school administrator for assistance.</p>
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

<!-- ══════════════════════════════════════════════════════════════
     NEW: GENERIC LOGIN NOTIFICATION POPUP
     Ported from company_login.php's popup pattern. Covers step-1
     "Missing Fields", "Account Not Found", and "Incorrect Password"
     — set server-side via $popup_type / $popup_title / $popup_msg.
══════════════════════════════════════════════════════════════ -->
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
    // NEW (popup style): informational popups (e.g. "Existing Request Found") get the navy info look;
    // error popups keep the red accent.
    if (<?= json_encode($popup_type) ?> === 'existing_request') {
        document.getElementById('loginNotifyBox').classList.add('ln-info');
        document.getElementById('lnIcon').innerHTML = '<i class="fas fa-circle-info"></i>';
    }
    document.getElementById('loginNotifyOverlay').style.display = 'flex';
});
</script>
<?php endif; ?>

<?php if (!$loginSplit): ?>
<!-- ── HEADER ── -->
<div style="display:flex; align-items:center; gap:18px;">
    <img src="logo.webp" alt="System Logo" style="width:72px; height:72px; border-radius:50%; object-fit:contain; box-shadow:0 4px 16px rgba(0,0,0,0.4);">
    <div style="border-left: 3px solid rgba(255,255,255,0.4); padding-left: 16px;">
        <p style="margin:0 0 2px; font-size:11px; font-weight:600; color:rgba(255,255,255,0.6); text-transform:uppercase; letter-spacing:1.5px;">NEUST Atate Campus</p>
        <p style="margin:0; font-size:18px; font-weight:700; color:#ffffff; line-height:1.3;">Web-Based Smart OJT<br>Monitoring and Supervision<br>Analytics System</p>
    </div>
</div>
<?php endif; ?>

<!-- UPDATED: steps 4 & 5 (Forgot Password) and 8 (Recover Email) switch the
     card to the admin "Add Student" modal design via .am-card (.am-card-wide
     for the 3-column Recover Email grid). All other steps are unchanged. -->
<?php if ($regDesign): ?><div class="reg-page-container"><?php endif; ?>
<div class="card<?= in_array((int)$step, [4, 5, 8], true) ? ' am-card' : '' ?><?= ((int)$step === 8) ? ' am-card-wide' : '' ?><?= $loginSplit ? ' login-split' : '' ?>">
<?php if ($loginSplit): ?>
<div class="ls-left">
    <div class="ls-logo"><img src="logo.webp" alt="System Logo"></div>
    <p class="ls-campus">NEUST Atate Campus</p>
    <p class="ls-title">Web-Based Smart OJT Monitoring and Supervision Analytics System</p>
</div>
<div class="ls-right">
<?php endif; ?>


<?php if (!empty($error))    echo "<p class='card-msg card-msg-error' style='color:#fca5a5;text-align:center;background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.3);border-radius:10px;padding:10px;'>$error</p>"; ?>
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
<?php /* UPDATED: the "OTP has been sent to your email. Valid for 5 minutes." banner is no longer shown on the login OTP step (2). $success is still set by the send_otp handler; UPDATED (Forgot Password OTP): the "OTP sent to ... Valid for 5 minutes." / "OTP resent to ..." banner is hidden on step 5 too; step 5 error messages ($fp_error) still show. */ if (!empty($success) && !in_array((int)$step, [2, 5], true))  echo "<p class='card-msg card-msg-success' style='color:#86efac;text-align:center;background:rgba(34,197,94,0.15);border:1px solid rgba(34,197,94,0.3);border-radius:10px;padding:10px;'>$success</p>"; ?>
<?php if (!empty($fp_error)) echo "<p class='card-msg card-msg-error' style='color:#fca5a5;text-align:center;background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.3);border-radius:10px;padding:10px;'>$fp_error</p>"; ?>

<?php if ($step == 1): ?>
<!-- ════════════════════ STEP 1: LOGIN ════════════════════ -->
<form action="login.php" method="POST">
<div class="input-group">
    <label class="ls-sr" for="loginEmail">Email</label>
    <div class="ls-field">
        <span class="ls-ic"><i class="fas fa-envelope"></i></span>
        <input type="email" name="email" id="loginEmail" placeholder="Enter your email" required>
    </div>
</div>
<div class="input-group">
    <label class="ls-sr" for="password">Password</label>
    <div class="ls-field">
        <span class="ls-ic"><i class="fas fa-lock"></i></span>
        <div class="password-wrapper">
            <input type="password" name="password" id="password" placeholder="Enter your password" required>
            <button type="button" id="togglePassword" class="toggle-btn">Show</button>
        </div>
    </div>
</div>

<?php if ($hasValidRememberCookie): ?>
<div class="remember-me-active-badge">
    <span class="badge-icon"><i class="fas fa-circle-info"></i></span>
    <span>A remembered session exists on this device &mdash; OTP will be skipped if it matches your account.</span>
</div>
<?php endif; ?>
<div class="ls-row">
    <div class="remember-me-row">
        <input type="checkbox" name="remember_me" id="rememberMe" value="1">
        <label class="rm-label" for="rememberMe">Remember me for today</label>
    </div>
</div>

<button type="submit" name="login" class="login-submit ls-login-btn">Login</button>

<!-- NEW: Forgot Password / Recover Email Address now live on the login card (they used to be in the
     Customer Service menu). They submit the same fp_go / re_go actions through the two hidden forms below. -->
<div class="ls-links">
    <button type="submit" form="fpGoForm" formnovalidate class="ls-forgot"><i class="fas fa-key"></i> Forgot password?</button>
    <button type="submit" form="reGoForm" formnovalidate class="ls-forgot"><i class="fas fa-envelope"></i> Recover email address</button>
</div>
</form>
<form id="fpGoForm" action="login.php" method="POST" style="display:none;">
    <input type="hidden" name="fp_go" value="1">
</form>
<form id="reGoForm" action="login.php" method="POST" style="display:none;">
    <input type="hidden" name="re_go" value="1">
</form>

<?php elseif ($step == 2): ?>
<!-- ════════════════════ STEP 2: LOGIN OTP ════════════════════ -->
<form action="login.php" method="POST" class="ls-otp" id="loginOtpForm"><!-- UPDATED: id added so the "Sending OTP" loading page can hook the Send OTP button -->
<p style="text-align:center;color:#1B2A4A;margin-bottom:6px;">
    Hello, <strong><?php echo htmlspecialchars($_SESSION['first_name_temp']); ?></strong><br>
    Enter the OTP sent to your email.
</p>

<?php if ($showOtpRing): ?>
<div class="otp-countdown-wrap">
    <div class="otp-countdown-ring">
        <!-- UPDATED: "OTP expires in" label moved from under the circle to its top-left (same id, so the countdown script still hides it on expiry). -->
        <span class="otp-countdown-label" id="otpCountdownLabel">OTP expires in</span>
        <svg viewBox="0 0 80 80" xmlns="http://www.w3.org/2000/svg">
            <circle class="otp-ring-bg" cx="40" cy="40" r="33"/>
            <circle class="otp-ring-fill" id="otpRingFill" cx="40" cy="40" r="33"/>
        </svg>
        <div class="otp-ring-time" id="otpTimeDisplay">5:00</div>
    </div>
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
<!-- ════════════════════ STEP FP1: FORGOT — ENTER EMAIL ════════════════════
     UPDATED: admin "Add Student" modal design (header + × close, form-group,
     required *, help text, bottom action bar). Same field name (fp_email)
     and same submit names (fp_submit_email / fp_cancel) as before. -->
<!-- UPDATED: × close button removed — the form's own "Back to Login" button handles this. -->
<div class="am-modal-header">
    <h3><i class="fas fa-key"></i> Forgot Password</h3>
</div>
<p class="am-modal-intro">
    Enter the email address associated with your account and we'll send you an OTP to reset your password.
</p>
<form action="login.php" method="POST" id="fpEmailForm">
    <div class="am-grid">
        <div class="form-group">
            <label>Email Address <span class="required">*</span></label>
            <input type="email" name="fp_email" placeholder="Enter your registered email" required
                value="<?= htmlspecialchars($_POST['fp_email'] ?? '') ?>">
            <div class="help-text">
                <i class="fas fa-info-circle"></i> Use the same email address registered on your account.
            </div>
        </div>
    </div>
    <div class="modal-actions">
        <button type="submit" name="fp_submit_email" class="btn-submit">Send OTP</button>
        <button type="submit" name="fp_cancel" class="btn-cancel-modal" formnovalidate>Back to Login</button>
    </div>
</form>

<?php elseif ($step == 5): ?>
<!-- ════════════════════ STEP FP2: FORGOT — VERIFY OTP ════════════════════
     UPDATED: admin "Add Student" modal design. OTP boxes, hidden
     fp_otp_input, countdown ring and all button names are unchanged. -->
<!-- UPDATED: × close button removed — the form's own "Cancel" button handles this. -->
<div class="am-modal-header">
    <h3><i class="fas fa-shield-halved"></i> Verify OTP</h3>
</div>
<p class="am-modal-intro fp-otp-intro">
    An OTP was sent to <strong><?= htmlspecialchars($_SESSION['fp_email'] ?? '') ?></strong>.<br>
    Enter it below to reset your password.
</p>

<?php if ($showFpOtpRing): ?>
<div class="otp-countdown-wrap">
    <div class="otp-countdown-ring">
        <!-- UPDATED: "OTP expires in" label moved from under the circle to its top-left (same id, so the countdown script still hides it on expiry). -->
        <span class="otp-countdown-label" id="fpOtpCountdownLabel">OTP expires in</span>
        <svg viewBox="0 0 80 80" xmlns="http://www.w3.org/2000/svg">
            <circle class="otp-ring-bg" cx="40" cy="40" r="33"/>
            <circle class="otp-ring-fill" id="fpOtpRingFill" cx="40" cy="40" r="33"/>
        </svg>
        <div class="otp-ring-time" id="fpOtpTimeDisplay">5:00</div>
    </div>
    <div class="otp-expired-msg" id="fpOtpExpiredMsg"><i class="fas fa-triangle-exclamation"></i> OTP expired &mdash; please resend</div>
</div>
<?php endif; ?>

<form action="login.php" method="POST" id="fpOtpForm">
    <div class="form-group">
        <label>Enter OTP <span class="required">*</span></label>
        <div class="otp-box-group" id="otpBoxesFp">
            <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-box" autocomplete="one-time-code">
            <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-box">
            <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-box">
            <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-box">
            <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-box">
            <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-box">
        </div>
        <input type="hidden" name="fp_otp_input" id="fp_otp_hidden">
        <!-- UPDATED: removed the "The OTP is valid for 5 minutes. A new password will be emailed to you after verification." help text. -->
    </div>
    <div class="modal-actions">
        <button type="submit" name="fp_verify_otp" id="fpVerifyBtn" class="btn-submit">Verify &amp; Reset Password</button>
        <button type="submit" name="fp_resend_otp" class="btn-cancel-modal">Resend OTP</button>
        <button type="submit" name="fp_cancel" class="btn-cancel-modal">Cancel</button>
    </div>
</form>

<?php elseif ($step == 6): ?>
<!-- ════════════════════ STEP FP3: SUCCESS ════════════════════
     UPDATED (this adjustment): the "Password Successfully Changed!" popup was
     replaced by the loading page's success state (check icon + message,
     rendered above as #globalLoadingOverlay.success-state) — the same screen
     the email recovery request uses — which then returns to login.php.
     This text is only a fallback if scripts are blocked. -->
<noscript>
<p style="text-align:center;color:rgba(255,255,255,0.75);font-size:14px;margin:0;">
    Password changed.
    <a href="login.php" style="color:#ffffff;font-weight:700;">Back to Login</a>
</p>
</noscript>
<script>
(function () {
    // This page is the result of the OTP POST — make its history entry a plain
    // GET so refreshing can never re-send the request.
    if (window.history && history.replaceState) {
        history.replaceState(null, '', 'login.php');
    }
    setTimeout(function () { window.location.replace('login.php'); }, 4000);
})();
</script>

<?php elseif ($step == 7): ?>
<!-- ════════════════════ STEP RE1: CHOOSE ACCOUNT TYPE ════════════════════ -->
<p style="text-align:center;color:rgba(255,255,255,0.75);font-size:14px;margin-bottom:20px;">
    Select the type of account whose email you want to recover.
</p>
<?php if (!empty($re_error)) echo "<p style='color:#fca5a5;text-align:center;font-size:13px;background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.3);border-radius:10px;padding:10px;'>$re_error</p>"; ?>
<form action="login.php" method="POST">
    <div style="display:flex;gap:12px;margin-bottom:20px;">
        <label class="re-type-card" id="card-student">
            <input type="radio" name="re_type" value="student" required style="display:none;" onchange="selectReType('student')">
            <span class="re-icon"><i class="fas fa-graduation-cap"></i></span>
            <span class="re-label">Student</span>
        </label>
        <label class="re-type-card" id="card-company">
            <input type="radio" name="re_type" value="company" required style="display:none;" onchange="selectReType('company')">
            <span class="re-icon"><i class="fas fa-building"></i></span>
            <span class="re-label">Company</span>
        </label>
    </div>
    <button type="submit" name="re_choose_type" class="login-submit">Continue</button>
    <a href="login.php?re_cancel=1" class="btn-secondary" style="display:block; box-sizing:border-box; text-align:center; text-decoration:none; margin-top:10px; padding:13px; border-radius:12px; font-weight:600; font-size:14px;">&#8592; Back to Login</a>
</form>

<?php elseif ($step == 8): ?>
<!-- ════════════════════ STEP RE2: FILL REQUEST FORM ════════════════════
     UPDATED (Recover Email ↔ Student List match): the fields now match the
     data the admin enters in admin_student_list.php's manual "Add Student"
     form — First / Middle / Last Name, Course, Major, Section, Email and
     Campus Branch — and the Course / Major / Section / Campus dropdowns are
     filled from the Student List itself. The form also uses that modal's
     design (header + ×, 3-column grid, required *, help text, sticky
     action bar). Selfie capture, hidden inputs, reason, new email and all
     scripts (handleReFormSubmit / takeSelfie / cancelReForm) are unchanged. -->
<?php
$re_type = $_SESSION['re_type'] ?? 'student';
$re_post = function ($key) { return trim((string)($_POST[$key] ?? '')); };
?>
<!-- UPDATED: × close button removed (the form's "Back to Login" button does the same
     thing). A "Step x of 2" badge sits in its place. The form is split into
     two phases: 1) Details, 2) Live Selfie — still ONE form / ONE submit, so
     every field name, hidden input and the re_submit_request handler are unchanged. -->
<div class="am-modal-header">
    <h3><?= $re_type === 'student' ? '<i class="fas fa-graduation-cap"></i> Student' : '<i class="fas fa-building"></i> Company' ?> Email Recovery Request</h3>
    <span class="re-phase-badge" id="rePhaseBadge">Step 1 of 2 &middot; Details</span>
</div>
<p class="am-modal-intro" id="reIntro">
    Fill in your details <strong>exactly as they appear in your student record</strong> kept by the administrator.
</p>
<?php if (!empty($re_error)) echo "<p class='card-msg card-msg-error' style='padding:10px;'>" . htmlspecialchars($re_error) . "</p>"; ?>
<form action="login.php" method="POST" id="reForm" data-phase="1" onsubmit="return handleReFormSubmit(event)">
    <input type="hidden" name="re_selfie_data" id="reSelfieData">
    <input type="hidden" name="re_action" id="reAction" value="submit">

    <!-- ── PHASE 1: DETAILS ── -->
    <div class="re-phase re-phase-1" id="rePhase1">
        <div class="am-grid">
            <div class="form-group">
                <label>First Name <span class="required">*</span></label>
                <input type="text" name="re_first_name" id="reFirstName" placeholder="Enter first name" required
                    value="<?= htmlspecialchars($re_post('re_first_name')) ?>">
            </div>
            <div class="form-group">
                <label>Middle Name</label>
                <input type="text" name="re_middle_name" id="reMiddleName" placeholder="Enter middle name (optional)"
                    value="<?= htmlspecialchars($re_post('re_middle_name')) ?>">
            </div>
            <div class="form-group">
                <label>Last Name <span class="required">*</span></label>
                <input type="text" name="re_last_name" id="reLastName" placeholder="Enter last name" required
                    value="<?= htmlspecialchars($re_post('re_last_name')) ?>">
            </div>

            <?php if ($re_type === 'student'): ?>
            <div class="form-group span-2">
                <label>Course <span class="required">*</span></label>
                <select name="re_course" id="reCourse" required>
                    <option value="">Select Course</option>
                    <?php foreach ($re_course_options as $co): ?>
                        <option value="<?= htmlspecialchars($co) ?>" <?= re_norm($re_post('re_course')) === re_norm($co) ? 'selected' : '' ?>><?= htmlspecialchars($co) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="help-text">
                    <i class="fas fa-info-circle"></i> Select the course listed on your student record.
                </div>
            </div>

            <div class="form-group">
                <label>Major <span class="required">*</span></label>
                <select name="re_major" required>
                    <option value="">Select Major</option>
                    <option value="none" <?= strtolower($re_post('re_major')) === 'none' ? 'selected' : '' ?>>None</option>
                    <?php foreach ($re_major_options as $mo): ?>
                        <?php if (strtolower(trim($mo)) === 'none') continue; ?>
                        <option value="<?= htmlspecialchars($mo) ?>" <?= re_norm($re_post('re_major')) === re_norm($mo) ? 'selected' : '' ?>><?= htmlspecialchars($mo) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="help-text">
                    <i class="fas fa-info-circle"></i> Select "None" if you have no major.
                </div>
            </div>

            <div class="form-group">
                <label>Section <span class="required">*</span></label>
                <select name="re_section" required>
                    <option value="">Select Section</option>
                    <?php foreach ($re_section_options as $so): ?>
                        <option value="<?= htmlspecialchars($so) ?>" <?= re_norm($re_post('re_section')) === re_norm($so) ? 'selected' : '' ?>><?= htmlspecialchars($so) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Campus Branch <span class="required">*</span></label>
                <select name="re_campus_branch" required>
                    <option value="">Select Campus Branch</option>
                    <?php foreach ($re_campus_options as $cb): ?>
                        <option value="<?= htmlspecialchars($cb) ?>" <?= re_norm($re_post('re_campus_branch')) === re_norm($cb) ? 'selected' : '' ?>><?= htmlspecialchars($cb) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php else: ?>
            <div class="form-group span-2">
                <label>Company Location / Address <span class="required">*</span></label>
                <input type="text" name="re_company_location" placeholder="Registered company address" required
                    value="<?= htmlspecialchars($re_post('re_company_location')) ?>">
            </div>
            <?php endif; ?>

            <div class="form-group">
                <label>Old Email Address (Registered) <span class="required">*</span></label>
                <input type="email" name="re_old_email" id="reOldEmail" placeholder="student@example.com" required
                    value="<?= htmlspecialchars($re_post('re_old_email')) ?>">
                <div class="help-text">
                    <i class="fas fa-info-circle"></i> The email registered for you in the Student List.
                </div>
            </div>

            <div class="form-group">
                <label>New Email Address <span class="required">*</span></label>
                <input type="email" name="re_new_email" id="reNewEmail" placeholder="New email you want to use" required
                    value="<?= htmlspecialchars($re_post('re_new_email')) ?>">
            </div>

            <div class="form-group span-2">
                <label>Reason <span class="required">*</span></label>
                <textarea name="re_reason" rows="2" placeholder="Why is your email becoming unavailable?" required><?= htmlspecialchars($_POST['re_reason'] ?? '') ?></textarea>
            </div>
        </div>
    </div>

    <!-- ── PHASE 2: LIVE SELFIE ── -->
    <div class="re-phase re-phase-2" id="rePhase2">
        <div class="re-phase2-grid">
            <div class="re-phase2-info">
                <p class="re-section-title"><i class="fas fa-camera"></i> Live Selfie Verification</p>
                <p class="re-camera-hint">Take a live photo of yourself for identity verification. Required.</p>
                <div class="re-summary">
                    <p class="re-summary-title"><i class="fas fa-clipboard-check"></i> Request Summary</p>
                    <div class="re-summary-row"><span>Name</span><strong id="reSumName">&mdash;</strong></div>
                    <div class="re-summary-row"><span>Course</span><strong id="reSumCourse">&mdash;</strong></div>
                    <div class="re-summary-row"><span>Old Email</span><strong id="reSumOld">&mdash;</strong></div>
                    <div class="re-summary-row"><span>New Email</span><strong id="reSumNew">&mdash;</strong></div>
                </div>
                <p class="re-summary-note"><i class="fas fa-info-circle"></i> Need to change something? Click <strong>Back</strong> to edit your details.</p>
            </div>
            <div class="re-selfie-wrap">
                <div class="re-camera-box" id="reCameraBox">
                    <video id="reVideo" autoplay playsinline></video>
                    <canvas id="reCanvas"></canvas>
                </div>
                <img id="reSelfiePreview" class="re-preview-img" alt="Selfie preview">
                <button type="button" class="re-snap-btn" id="reSnapBtn" onclick="takeSelfie()"><i class="fas fa-camera"></i> Take Photo</button>
                <button type="button" class="re-retake-btn" id="reRetakeBtn" onclick="retakeSelfie()"><i class="fas fa-rotate"></i> Retake</button>
            </div>
        </div>
    </div>

    <!-- Action bar: submit-type button first in the DOM (row-reverse shows it on the right) -->
    <div class="modal-actions">
        <button type="submit" name="re_submit_request" class="btn-submit re-only-2" id="reSubmitBtn" disabled>
            Submit Recovery Request
        </button>
        <button type="button" class="btn-submit re-only-1" id="reNextBtn" onclick="reGoToPhase(2)">
            Next: Take Selfie <i class="fas fa-arrow-right"></i>
        </button>
        <button type="button" class="btn-cancel-modal re-only-2" id="rePrevBtn" onclick="reGoToPhase(1)">
            <i class="fas fa-arrow-left"></i> Back
        </button>
        <button type="button" class="btn-cancel-modal" onclick="cancelReForm()">Back to Login</button>
    </div>
</form>

<?php elseif ($step == 9): ?>
<!-- ════════════════════ STEP RE3: REQUEST SUBMITTED ════════════════════
     UPDATED: the old "Request Submitted!" card was replaced by the global
     loading page's success state (check icon + message, rendered above as
     #globalLoadingOverlay.success-state), which then returns to login.php.
     This card text is only a fallback if scripts are blocked. -->
<noscript>
<p style="text-align:center;color:rgba(255,255,255,0.75);font-size:14px;margin:0;">
    Request submitted.
    <a href="login.php" style="color:#ffffff;font-weight:700;">Back to Login</a>
</p>
</noscript>
<script>
(function () {
    // This page is the result of the submit POST — turn its history entry
    // into a plain GET so refreshing can never re-send the request.
    if (window.history && history.replaceState) {
        history.replaceState(null, '', 'login.php');
    }
    setTimeout(function () { window.location.replace('login.php'); }, 4000);
})();
</script>

<?php endif; ?>

<?php if ($loginSplit): ?></div><!-- .ls-right --><?php endif; ?>
</div><!-- .card -->
<?php if ($regDesign): ?></div><!-- .reg-page-container --><?php endif; ?>

<!-- UPDATED: the Customer Service floating button was removed — Forgot Password and Recover Email Address are now links on the login card itself. -->

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

<!-- ══════════════════════════════════════════════════════════════
     NEW: OTP BOX INPUT BEHAVIOR
     ------------------------------------------------------------
     Wires up the six-box OTP fields (step 2 login OTP, step 5
     forgot-password OTP) added above: typing a digit auto-advances
     to the next box, Backspace on an empty box moves back, pasting
     a full code distributes it across all boxes, and every keystroke
     keeps a hidden input (name="otp" / name="fp_otp_input") in sync
     so the existing PHP handlers ($_POST['otp'] / $_POST['fp_otp_input'])
     keep working completely unchanged.
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
    setupOtpBoxes('otpBoxesLogin', 'otp_hidden_login');
    setupOtpBoxes('otpBoxesFp', 'fp_otp_hidden');

    // Preserves the original required-field behavior of the old
    // fp_otp_input text field: block the "Verify & Reset Password"
    // submit if the six boxes haven't all been filled in yet.
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

<!-- ── RECOVER EMAIL SCRIPTS ── -->
<script>
function selectReType(type) {
    document.getElementById('card-student').classList.toggle('selected', type === 'student');
    document.getElementById('card-company').classList.toggle('selected', type === 'company');
}

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
    // NEW (two-phase form): never submit from the Details phase.
    var reFormEl = document.getElementById('reForm');
    if (reFormEl && reFormEl.getAttribute('data-phase') !== '2') { reGoToPhase(2); return false; }
    return validateSelfie();
}

/* ══════════════════════════════════════════════════════════════
   NEW: TWO-PHASE RECOVER EMAIL FORM
   ------------------------------------------------------------
   Phase 1 = Details, Phase 2 = Live Selfie. Both phases live in the
   same #reForm, so a single submit still posts every field to the
   unchanged re_submit_request handler. Moving to phase 2 first runs
   the browser's own required/email validation on every phase-1 field.
   The camera is only switched on when phase 2 opens, and switched off
   again when going back (a selfie already taken is kept).
══════════════════════════════════════════════════════════════ */
function reGoToPhase(n) {
    var form = document.getElementById('reForm');
    if (!form) return;

    if (n === 2) {
        var p1 = document.getElementById('rePhase1');
        var fields = p1 ? p1.querySelectorAll('input, select, textarea') : [];
        for (var i = 0; i < fields.length; i++) {
            if (!fields[i].checkValidity()) { fields[i].reportValidity(); return; }
        }
        var val = function (id) { var el = document.getElementById(id); return el ? el.value.trim() : ''; };
        var setText = function (id, text) { var el = document.getElementById(id); if (el) el.textContent = text || '\u2014'; };
        setText('reSumName', [val('reFirstName'), val('reMiddleName'), val('reLastName')].filter(Boolean).join(' '));
        setText('reSumCourse', val('reCourse'));
        setText('reSumOld', val('reOldEmail'));
        setText('reSumNew', val('reNewEmail'));
    }

    form.setAttribute('data-phase', String(n));
    var badge = document.getElementById('rePhaseBadge');
    if (badge) badge.innerHTML = n === 2 ? 'Step 2 of 2 &middot; Live Selfie' : 'Step 1 of 2 &middot; Details';
    var intro = document.getElementById('reIntro');
    if (intro) intro.style.display = n === 2 ? 'none' : '';

    if (n === 2) {
        if (!reSelfieCapture && !reStream) startReCamera();
    } else if (reStream) {
        reStream.getTracks().forEach(function (t) { t.stop(); });
        reStream = null;
    }
}

function cancelReForm() {
    if (reStream) { reStream.getTracks().forEach(function(t){ t.stop(); }); reStream = null; }
    window.location.href = 'login.php?re_cancel=1';
}

document.addEventListener('DOMContentLoaded', function() {
    // UPDATED (two-phase form): the camera now starts when the Live Selfie
    // phase opens (see reGoToPhase) instead of on page load.
    // Enter in a phase-1 field moves to the next phase instead of submitting.
    var reFormEl = document.getElementById('reForm');
    if (reFormEl) {
        reFormEl.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA' && e.target.tagName !== 'BUTTON'
                && reFormEl.getAttribute('data-phase') === '1') {
                e.preventDefault();
                reGoToPhase(2);
            }
        });
    }
});
</script>

<!-- ══════════════════════════════════════════════════════════════
     NEW: FORGOT PASSWORD — "SENDING OTP" LOADING PAGE
     ------------------------------------------------------------
     Sending the OTP email takes a few seconds, so when the person presses
     "Send OTP" (step 4) or "Resend OTP" (step 5), the global loading page
     opens right away with "SENDING OTP..." and a short note, instead of the
     plain "LOADING...". It only starts after the browser's own field checks
     pass, and it never runs for Verify / Cancel / Back. Nothing about the
     form fields, button names, PHP handlers or OTP logic is changed.
══════════════════════════════════════════════════════════════ -->
<script>
(function () {
    var MSG = 'Sending OTP';
    var SUB = 'Please wait while we send the verification code to your email.';

    function hook(formId, sendName, emptyMeansSend) {
        var form = document.getElementById(formId);
        if (!form) return;
        var lastName = '';
        form.addEventListener('click', function (e) {
            var b = e.target && e.target.closest ? e.target.closest('button') : null;
            lastName = b ? (b.name || '') : '';
        });
        form.addEventListener('submit', function (e) {
            var name = (e.submitter && e.submitter.name) ? e.submitter.name : lastName;
            if (name ? name !== sendName : !emptyMeansSend) return;
            if (window.setGlobalLoadingOnLeave) window.setGlobalLoadingOnLeave(MSG, SUB);
            if (window.showGlobalLoading) window.showGlobalLoading(MSG);
        });
    }

    hook('fpEmailForm', 'fp_submit_email', true);    // step 4: Send OTP (Enter key also sends)
    hook('fpOtpForm',   'fp_resend_otp',   false);   // step 5: Resend OTP only
    hook('loginOtpForm', 'send_otp',       false);   // UPDATED: step 2 (login OTP): Send OTP only - Verify OTP / Back to Login are not affected

    // UPDATED (this adjustment): the Verify buttons now show a "VERIFYING OTP..." loading page instead of the
    // normal "Loading...". Same idea as the Sending OTP hook above: it starts only after the browser's own
    // checks pass, never runs for Resend / Send / Cancel / Back, and stays until the result page is ready.
    var V_MSG = 'Verifying OTP';
    var V_SUB = 'Please wait while we verify your code.';
    function hookVerify(formId, verifyName) {
        var form = document.getElementById(formId);
        if (!form) return;
        var lastName = '';
        form.addEventListener('click', function (e) {
            var b = e.target && e.target.closest ? e.target.closest('button') : null;
            lastName = b ? (b.name || '') : '';
        });
        form.addEventListener('submit', function (e) {
            var name = (e.submitter && e.submitter.name) ? e.submitter.name : lastName;
            if (name ? name !== verifyName : false) return;   // Enter key with no button info = the first (Verify) button
            if (window.setGlobalLoadingOnLeave) window.setGlobalLoadingOnLeave(V_MSG, V_SUB);
            if (window.showGlobalLoading) window.showGlobalLoading(V_MSG);
        });
    }
    hookVerify('fpOtpForm',    'fp_verify_otp');   // Forgot Password OTP: Verify & Reset Password
    hookVerify('loginOtpForm', 'verify_otp');      // Login OTP: Verify OTP
})();
</script>
</body>
</html>