<?php
session_start();
include "db.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require 'PHPMailer/src/Exception.php';

/* ================= SESSION CHECK ================= */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != "company") {
    header("Location: login.php");
    exit;
}

$company_id = $_SESSION['user_id'];

// Fetch pending late requests count (sidebar badge)
$pending_lr_count = 0;
$stmt_plr = $conn->prepare("SELECT COUNT(*) as total FROM late_requests WHERE company_id=? AND status='pending'");
$stmt_plr->bind_param("i", $company_id);
$stmt_plr->execute();
$res_plr = $stmt_plr->get_result()->fetch_assoc();
$pending_lr_count = $res_plr['total'] ?? 0;
$stmt_plr->close();

$pageTitle = "OJT Student Management";
$message   = "";

/* ================= FETCH COMPANY PROFILE INFO (for emails) ================= */
$ci = $conn->query("SELECT company, contact_first_name, contact_middle_initial, contact_last_name, position FROM company_information WHERE user_id=$company_id")->fetch_assoc();
$company_name    = $ci['company'] ?? 'Our Company';
$supervisor_name = trim(($ci['contact_first_name'] ?? '') . ' ' . ($ci['contact_middle_initial'] ?? '') . ' ' . ($ci['contact_last_name'] ?? ''));
$supervisor_pos  = $ci['position'] ?? '';

/* ================= HELPER: send email ================= */
function sendMail($to, $subject, $body) {
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
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->send();
        return true;
    } catch (Exception $e) {
        // Log the real reason (auth failure, wrong password, connection
        // refused, etc.) to the server's PHP error log instead of silently
        // swallowing it, so future mail problems are easy to diagnose.
        error_log('sendMail() failed for ' . $to . ': ' . $mail->ErrorInfo);
        return false;
    }
}

/* ================= NEW (this adjustment): SHARED EMAIL DESIGN =================
   Every automated email this page sends now uses the same layout as the
   administrator.php application emails (mail.php): navy header with the gold
   "NEUST OJT Portal" title, the tinted status banner, one whole message ("Dear <name>," + text +
   reason when given + closing + sign-off), the navy "Log In to OJT Portal"
   button and the grey automated-message footer. No emoji are used anywhere. Only the look
   changed — every email keeps its recipient, subject and information. */
function ojtPortalUrl($page = 'login.php') {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir   = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https' : 'http') . '://' . $host . $dir . '/' . ltrim($page, '/');
}

function buildOjtEmail(array $o) {
    /* UPDATED: one whole message instead of many sections. The separate
       reason box and the company box were removed — everything below the
       status banner is written as one continuous message: greeting, the message, the reason
       (only when one was given) as a normal line, the closing note, the
       sign-off and the "Log In to OJT Portal" button. No emoji are used.
       Every caller and every email's recipient/subject stay the same. */
    $h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    // UPDATED (this adjustment): the status banner is back (green for accepted,
    // red for rejected) — only the message below it stays one whole section.
    $themes = [
        'success' => ['#16a34a', '#f0fdf4', '#bbf7d0'],
        'danger'  => ['#dc2626', '#fff1f1', '#fecaca'],
        'warning' => ['#b45309', '#fffbeb', '#fde68a'],
    ];
    [$statusColor, $statusBg, $statusBorder] = $themes[$o['theme'] ?? 'success'] ?? $themes['success'];
    $p = function ($html, $extra = '') {
        return "<p style='margin:0 0 14px; font-size:14px; color:#4b5563; line-height:1.7;{$extra}'>{$html}</p>";
    };

    $body  = "<p style='margin:0 0 14px; font-size:15px; color:#374151;'>Dear <strong>" . $h($o['name'] ?? '') . "</strong>,</p>";
    foreach (($o['paragraphs'] ?? []) as $para) {
        $body .= $p($para);
    }
    if (isset($o['reason']) && trim((string)$o['reason']) !== '') {
        $body .= $p("<strong style='color:#374151;'>" . $h($o['reason_label'] ?? 'Reason') . ":</strong> " . nl2br($h($o['reason'])));
    }
    if (!empty($o['note'])) {
        $body .= $p($o['note']);
    }
    if (!empty($o['signoff'])) {
        $body .= $p($o['signoff'], ' margin-bottom:0;');
    }

    return "
        <div style='font-family:Arial,sans-serif; background:#f4f4f4; padding:30px 0; margin:0;'>
          <table width='100%' cellpadding='0' cellspacing='0'>
            <tr><td align='center'>
              <table width='560' cellpadding='0' cellspacing='0' style='max-width:560px; background:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 4px 20px rgba(0,0,0,0.08);'>

                <!-- Header -->
                <tr>
                  <td style='background:#07145f; padding:26px 36px; text-align:center;'>
                    <div style='font-size:20px; font-weight:700; color:#FFD700; letter-spacing:0.5px;'>NEUST OJT Portal</div>
                    <div style='font-size:12px; color:rgba(255,255,255,0.6); margin-top:4px;'>Atate Campus &mdash; On the Job Training System</div>
                  </td>
                </tr>

                <!-- Message (one whole section) -->
                <tr>
                  <td style='padding:30px 36px 24px;'>
                    {$body}
                  </td>
                </tr>

                <!-- CTA -->
                <tr>
                  <td style='padding:0 36px 28px; text-align:center;'>
                    <a href='" . $h(ojtPortalUrl('login.php')) . "' style='display:inline-block; background:#07145f; color:#FFD700; text-decoration:none; font-weight:700; font-size:13px; padding:12px 30px; border-radius:8px;'>Log In to OJT Portal</a>
                  </td>
                </tr>

                <!-- Footer -->
                <tr>
                  <td style='background:#f9fafb; border-top:1px solid #e5e7eb; padding:16px 36px; text-align:center;'>
                    <p style='margin:0; font-size:11px; color:#9ca3af;'>This is an automated message from the NEUST OJT Validation System. Please do not reply to this email.</p>
                  </td>
                </tr>

              </table>
            </td></tr>
          </table>
        </div>
    ";
}

/* NEW (this adjustment): sign-off with the company representative's name above
   the company name (same as the Application Accepted email). Falls back to
   the company name alone if no representative name is on file. */
function ojtEmailSignoff($greeting, $companyName, $repName = null) {
    if ($repName === null) $repName = $GLOBALS['supervisor_name'] ?? '';
    $repName = trim(preg_replace('/\s+/', ' ', (string)$repName));
    if ($repName === '') {
        return $greeting . ",<br><strong>" . htmlspecialchars($companyName) . "</strong>";
    }
    return $greeting . ",<br><strong>" . htmlspecialchars($repName) . "</strong><br>" . htmlspecialchars($companyName);
}

/* Endorsement letter rejected — shared by the direct reject and the undo-window commit. */
function buildEndorsementRejectedEmail($studentName, $companyName, $remark, $reasonLabel) {
    return buildOjtEmail([
        'theme'        => 'danger',
        'heading'      => 'Endorsement Letter Needs Correction',
        'name'         => $studentName,
        'paragraphs'   => [
            "The endorsement letter you uploaded for your OJT application to <strong>" . htmlspecialchars($companyName) . "</strong> was <strong style='color:#dc2626;'>REJECTED</strong>.",
            "Please open your <strong>Inbox</strong> on the Company List page, correct the letter, and upload it again.",
        ],
        'reason_label' => $reasonLabel,
        'reason'       => $remark,
        'card_label'   => 'Company Applied To',
        'card_value'   => $companyName,
        'note'         => "Once your corrected letter is uploaded, the company will review it again.",
        'signoff'      => ojtEmailSignoff('Regards', $companyName), // UPDATED: representative name + company
    ]);
}

/* ================= NEW (schedule change): STUDENT TRAINING SCHEDULE =================
   The Day / Evening Schedule a student sets on AccomForm.php lives in student_information
   (day_sched, evening_sched) as Monday–Friday acronyms ("MWF", "TTh", "None" …).
   The helpers below read / validate / label those values exactly like AccomForm.php does. */
function aplSchedDays() {
    return ['M' => 'Mon', 'T' => 'Tue', 'W' => 'Wed', 'Th' => 'Thu', 'F' => 'Fri'];
}
function aplParseSched($value) {
    $value  = trim((string)$value);
    $result = ['none' => false, 'days' => [], 'legacy' => ''];
    if ($value === '') return $result;
    if (strcasecmp($value, 'None') === 0) { $result['none'] = true; return $result; }
    $rest  = $value;
    $found = [];
    while ($rest !== '') {
        if (stripos($rest, 'Th') === 0)    { $found['Th'] = true; $rest = substr($rest, 2); }
        elseif (stripos($rest, 'M') === 0) { $found['M']  = true; $rest = substr($rest, 1); }
        elseif (stripos($rest, 'T') === 0) { $found['T']  = true; $rest = substr($rest, 1); }
        elseif (stripos($rest, 'W') === 0) { $found['W']  = true; $rest = substr($rest, 1); }
        elseif (stripos($rest, 'F') === 0) { $found['F']  = true; $rest = substr($rest, 1); }
        else { $found = []; break; }
    }
    if (empty($found)) { $result['legacy'] = $value; return $result; }
    foreach (aplSchedDays() as $acr => $name) {
        if (isset($found[$acr])) $result['days'][] = $acr;
    }
    return $result;
}
// "MWF" -> "Mon, Wed, Fri"; "None" -> "None"; empty -> "Not set"
function aplSchedLabel($value) {
    $p = aplParseSched($value);
    if ($p['none']) return 'None';
    if (!empty($p['days'])) {
        $names = aplSchedDays();
        return implode(', ', array_map(fn($a) => $names[$a], $p['days']));
    }
    return $p['legacy'] !== '' ? $p['legacy'] : 'Not set';
}
// Returns the clean acronym string ("MWF" / "None"), or null when the value is not a valid Mon–Fri schedule.
function aplNormalizeSched($value) {
    $value = trim((string)$value);
    if ($value === '') return null;
    if (strcasecmp($value, 'None') === 0) return 'None';
    $p = aplParseSched($value);
    if ($p['legacy'] !== '' || empty($p['days'])) return null;
    return implode('', $p['days']);
}

/* History of schedule changes made by company supervisors. administrator.php reads it to bring the student
   back for validation (same idea as the placement-replaced flow) and AccomForm.php shows it to the student. */
function ensureScheduleChangesTable($conn) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS student_schedule_changes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            company_id INT NOT NULL,
            old_day_sched VARCHAR(100) NULL,
            old_evening_sched VARCHAR(100) NULL,
            new_day_sched VARCHAR(100) NULL,
            new_evening_sched VARCHAR(100) NULL,
            reason TEXT NULL,
            contract_removed TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_ssc_student (student_id),
            KEY idx_ssc_company (company_id))");
        return $ok = true;
    } catch (\Throwable $e) {
        error_log('add_ojt_student.php: student_schedule_changes ensure failed: ' . $e->getMessage());
        return $ok = false;
    }
}

/* Removes the verified copies of the student's Student/University Contract from uploads/<First>_<Middle>_<Last>/.
   Same rules as company_list.php (cl_remove_application_sit): the folder name and file names are rebuilt exactly
   like administrator.php builds them when it verifies a requirement
   (<Label>_verified_<time>.<ext> / <Label>_<n>_verified_<time>.<ext>), only matching regular files are deleted,
   the folder must resolve inside uploads/, and a folder shared by two students with the same name is left alone.
   Never throws; the caller only reads the summary. */
function aplStudentUploadFolder($first, $middle, $last) {
    $f = preg_replace("/[^a-zA-Z0-9]/", "_", (string)$first);
    $m = preg_replace("/[^a-zA-Z0-9]/", "_", (string)$middle);
    $l = preg_replace("/[^a-zA-Z0-9]/", "_", (string)$last);
    return !empty($m) ? $f . "_" . $m . "_" . $l : $f . "_" . $l;
}
function aplContractFiles($dir, $labels) {
    $out  = [];
    $alts = [];
    foreach ((array)$labels as $lbl) {
        $s = preg_replace('/[^a-zA-Z0-9]/', '_', (string)$lbl);
        if (trim($s, '_') === '') continue;
        $alts[strtolower($s)] = preg_quote($s, '/');
    }
    if (!$alts) return $out;
    $re = '/^(?:' . implode('|', $alts) . ')(?:_\d+)?_verified_\d+(?:_\d+)?\.[A-Za-z0-9]{1,5}$/i';
    $names = @scandir($dir);
    if (!is_array($names)) return $out;
    foreach ($names as $n) {
        if ($n === '.' || $n === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $n;
        if (is_link($path) || !is_file($path)) continue;
        if (preg_match($re, $n)) $out[] = $path;
    }
    return $out;
}
function aplRemoveContractFiles($conn, $student_id) {
    $res = ['files_removed' => 0, 'files_failed' => 0, 'folder' => '', 'note' => ''];
    try {
        $uq = $conn->prepare("SELECT first_name, middle_name, last_name FROM users WHERE id = ?");
        $uq->bind_param("i", $student_id);
        $uq->execute();
        $u = $uq->get_result()->fetch_assoc();
        $uq->close();
        if (!$u || trim((string)$u['first_name']) === '' || trim((string)$u['last_name']) === '') { $res['note'] = 'student name incomplete'; return $res; }
        $folder = aplStudentUploadFolder($u['first_name'], $u['middle_name'], $u['last_name']);
        $res['folder'] = $folder;

        $cq = $conn->prepare("SELECT first_name, middle_name, last_name FROM users WHERE id <> ? AND role = 'student' AND first_name = ? AND last_name = ?");
        $fn = (string)$u['first_name']; $ln = (string)$u['last_name'];
        $cq->bind_param("iss", $student_id, $fn, $ln);
        $cq->execute();
        $cres = $cq->get_result();
        while ($o = $cres->fetch_assoc()) {
            if (aplStudentUploadFolder($o['first_name'], $o['middle_name'], $o['last_name']) === $folder) {
                $cq->close();
                $res['note'] = 'folder shared with another student';
                error_log("add_ojt_student.php: contract files of student " . $student_id . " left in place - folder '" . $folder . "' is shared with another student");
                return $res;
            }
        }
        $cq->close();

        $base = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'uploads');
        if ($base === false) { $res['note'] = 'no uploads folder'; return $res; }
        $dir = realpath($base . DIRECTORY_SEPARATOR . $folder);
        if ($dir === false || !is_dir($dir)) { $res['note'] = 'no student folder'; return $res; }
        if (strpos($dir . DIRECTORY_SEPARATOR, $base . DIRECTORY_SEPARATOR) !== 0 || $dir === $base) {
            $res['note'] = 'folder outside uploads';
            error_log("add_ojt_student.php: refusing to clean '" . $dir . "' (outside uploads)");
            return $res;
        }
        foreach (aplContractFiles($dir, ['Student/University Contract', 'Student/University contract']) as $file) {
            if (@unlink($file)) $res['files_removed']++;
            else { $res['files_failed']++; error_log("add_ojt_student.php: could not delete contract file " . $file); }
        }
    } catch (\Throwable $e) {
        error_log("add_ojt_student.php: contract folder cleanup failed for student " . $student_id . ": " . $e->getMessage());
    }
    return $res;
}

// Active administrators that have an e-mail address (the recipients of the schedule-change notice).
function aplAdminRecipients($conn) {
    $out = [];
    try {
        $hasActive = false;
        $c = $conn->query("SHOW COLUMNS FROM admins LIKE 'is_active'");
        if ($c && $c->num_rows > 0) $hasActive = true;
        $r = $conn->query("SELECT first_name, middle_name, last_name, email FROM admins WHERE email IS NOT NULL AND email <> ''" . ($hasActive ? " AND COALESCE(is_active, 1) = 1" : ""));
        $seen = [];
        if ($r) while ($a = $r->fetch_assoc()) {
            $em = trim((string)$a['email']);
            if (!filter_var($em, FILTER_VALIDATE_EMAIL) || isset($seen[strtolower($em)])) continue;
            $seen[strtolower($em)] = true;
            $out[] = ['email' => $em, 'name' => trim(preg_replace('/\s+/', ' ', ($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? ''))) ?: 'Administrator'];
        }
    } catch (\Throwable $e) {
        error_log('add_ojt_student.php: admin recipients lookup failed: ' . $e->getMessage());
    }
    return $out;
}

/* Schedule changed — e-mail to the STUDENT (formal, friendly, informative; the supervisor's reason is attached). */
function buildScheduleChangedStudentEmail($studentName, $companyName, $oldDay, $oldEve, $newDay, $newEve, $reason, $contractRemoved = true) {
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    return buildOjtEmail([
        'theme'        => 'warning',
        'heading'      => 'Your OJT Schedule Has Been Updated',
        'name'         => $studentName,
        'paragraphs'   => [
            "We hope you are doing well. We would like to let you know that your OJT supervisor at <strong>" . $h($companyName) . "</strong> has set up a new training schedule for you.",
            "<strong style='color:#374151;'>Previous schedule</strong><br>Day: " . $h(aplSchedLabel($oldDay)) . "<br>Evening: " . $h(aplSchedLabel($oldEve)),
            "<strong style='color:#16a34a;'>New schedule</strong><br>Day: <strong>" . $h(aplSchedLabel($newDay)) . "</strong><br>Evening: <strong>" . $h(aplSchedLabel($newEve)) . "</strong>",
            ($contractRemoved
                ? "Because your Student/University Contract was prepared with your previous schedule in mind, it has been removed from your requirements. Please prepare a new Student/University Contract that reflects your updated schedule and upload it on the <strong>Requirements</strong> page of the OJT portal. The administrator will validate it once it has been submitted, and some portal features may stay limited until then."
                : "Please prepare a Student/University Contract that reflects your updated schedule and upload it on the <strong>Requirements</strong> page of the OJT portal. The administrator will validate it once it has been submitted, and some portal features may stay limited until then."),
        ],
        'reason_label' => 'Reason for the change',
        'reason'       => $reason,
        'note'         => "If anything is unclear, please reach out to your OJT supervisor or the administrator. Thank you for your understanding and cooperation.",
        'signoff'      => ojtEmailSignoff('Warm regards', $companyName),
    ]);
}

/* Schedule changed — e-mail to the ADMINISTRATOR. */
function buildScheduleChangedAdminEmail($adminName, $studentName, $course, $companyName, $supervisorName, $oldDay, $oldEve, $newDay, $newEve, $reason, $contractRemoved = true) {
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $who = ($supervisorName !== '' ? "<strong>" . $h($supervisorName) . "</strong> of " : '') . "<strong>" . $h($companyName) . "</strong>";
    return buildOjtEmail([
        'theme'        => 'warning',
        'heading'      => 'Student Schedule Updated - Contract Re-validation Required',
        'name'         => $adminName,
        'paragraphs'   => [
            "Good day. This is to inform you that " . $who . " has updated the training schedule of <strong>" . $h($studentName) . "</strong>" . ($course !== '' ? " (" . $h($course) . ")" : '') . ".",
            "<strong style='color:#374151;'>Previous schedule</strong><br>Day: " . $h(aplSchedLabel($oldDay)) . "<br>Evening: " . $h(aplSchedLabel($oldEve)),
            "<strong style='color:#16a34a;'>New schedule</strong><br>Day: <strong>" . $h(aplSchedLabel($newDay)) . "</strong><br>Evening: <strong>" . $h(aplSchedLabel($newEve)) . "</strong>",
            ($contractRemoved
                ? "As a result, the student's Student/University Contract (the record and its stored file copies) has been removed and the student has returned to your validation list. The student has been notified by email and asked to upload a new contract that reflects the updated schedule."
                : "The student had no Student/University Contract on file at the time, and has returned to your validation list. The student has been notified by email and asked to upload a contract that reflects the updated schedule."),
        ],
        'reason_label' => 'Reason given by the supervisor',
        'reason'       => $reason,
        'note'         => "Please review and validate the new Student/University Contract once the student submits it. Thank you.",
        'signoff'      => "Best regards,<br><strong>Atate On the Job Training System</strong>",
    ]);
}

/* ================= HELPER: build full application card data =================
   Shared by the initial (server-rendered) inbox loop AND the
   check_new_applications AJAX polling endpoint, so both paths always
   produce identical data for a given application — one source of truth
   instead of two copies of the same parsing logic drifting apart. */
function buildAppCardData($conn, $company_id, $company_name, $app, $currentCount) {
    $SKILL_ENTRY_DELIM = "\x1F"; // Unit Separator — matches company_list.php
    $EXP_ENTRY_DELIM   = "\x1E"; // Record Separator — matches company_list.php

    $skillsFull = [];
    if (!empty($app['skill1'])) $skillsFull[] = $app['skill1'];
    if (!empty($app['skill2'])) $skillsFull[] = $app['skill2'];
    if (!empty($app['skill3'])) {
        foreach (explode($SKILL_ENTRY_DELIM, $app['skill3']) as $extraSkill) {
            if (trim($extraSkill) !== '') $skillsFull[] = $extraSkill;
        }
    }

    $expFull = [];
    if (!empty($app['exp1'])) $expFull[] = $app['exp1'];
    if (!empty($app['exp2'])) {
        foreach (explode($EXP_ENTRY_DELIM, $app['exp2']) as $extraExp) {
            if (trim($extraExp) !== '') $expFull[] = $extraExp;
        }
    }

    $photo = !empty($app['student_photo']) ? base64_encode($app['student_photo']) : null;

    $reqLabelsLocal = [
        "cert_registration"  => "Certification of Registration",
        "certificate_pdos"   => "Certificate of participation (PDOS)",
        "ojt_sheet"          => "OJT Program and Information Sheet",
        "application_sit"    => "Application for Supervised Industrial Training",
        "waiver_form"        => "Waiver and Permission Form",
        "student_contract"   => "Student/University Contract",
        "psych_result"       => "Psych Test Result",
        "medical_result"     => "Medical Result"
    ];

    $snapMap = [];
    $snapRes = $conn->query("SELECT requirement_type, file_name, status FROM application_requirements WHERE student_id = " . intval($app['student_id']) . " AND company_id = " . intval($company_id));
    if ($snapRes) {
        while ($sr = $snapRes->fetch_assoc()) {
            $snapMap[$sr['requirement_type']] = [
                'status' => $sr['status'],
                'file'   => !empty($sr['file_name']) ? base64_encode($sr['file_name']) : null,
            ];
        }
    }

    $liveMap = [];
    $liveRes = $conn->query("SELECT requirement_type, file_name, status FROM requirements WHERE user_id = " . intval($app['student_id']));
    if ($liveRes) {
        while ($lr = $liveRes->fetch_assoc()) {
            $liveMap[$lr['requirement_type']] = [
                'status' => $lr['status'],
                'file'   => !empty($lr['file_name']) ? base64_encode($lr['file_name']) : null,
            ];
        }
    }

    $reqDocs = [];
    foreach ($reqLabelsLocal as $rType => $rLabel) {
        $src = isset($snapMap[$rType]) ? $snapMap[$rType] : ($liveMap[$rType] ?? null);
        $reqDocs[] = [
            'label'  => $rLabel,
            'status' => $src['status'] ?? 'Pending',
            'file'   => $src['file'] ?? null,
        ];
    }

    $siRow = $conn->query("SELECT photo_status FROM student_information WHERE user_id = " . intval($app['student_id']));
    $photoStatus = ($siRow && $siRow->num_rows > 0) ? ($siRow->fetch_assoc()['photo_status'] ?? 'Pending') : 'Pending';

    return [
        'id'          => (int)$app['id'],
        'name'        => trim($app['first_name'] . ' ' . $app['last_name']),
        'email'       => $app['email'],
        'course'      => $app['course'] ?? '',
        'company'     => $company_name,
        'skills'      => $skillsFull,
        'exps'        => $expFull,
        'photo'       => $photo,
        'submitted'   => !empty($app['created_at']) ? date('M j, Y', strtotime($app['created_at'])) : '',
        'ojtCount'    => (int)$currentCount,
        'reqDocs'     => $reqDocs,
        'photoStatus' => $photoStatus,
    ];
}

/* ================= NEW (endorsement flow): ENDORSEMENT LETTERS =================
   After the administrator allows an application it lands here (ojt_applications,
   phase='pending') AND the student receives an Endorsement Letter in their Inbox
   (company_list.php). The student uploads the signed letter; this page validates it:
     • Verified → the student is registered to this company (same logic as Accept)
     • Rejected → remarks are sent back and the student can upload a corrected letter
   Applications approved before this flow existed have no letter on file and can
   still be accepted exactly as before. */
require_once __DIR__ . '/ENDORSEMENT_form_builder.php';

if (!function_exists('ensureEndorsementLettersTable')) {
    function ensureEndorsementLettersTable($conn) {
        try {
            $conn->query("CREATE TABLE IF NOT EXISTS endorsement_letters (
                id INT AUTO_INCREMENT PRIMARY KEY,
                student_id INT NOT NULL,
                company_id INT NOT NULL,
                letter_data LONGTEXT NULL,
                sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                student_viewed TINYINT(1) NOT NULL DEFAULT 0,
                uploaded_file LONGBLOB NULL,
                uploaded_mime VARCHAR(100) NULL,
                uploaded_name VARCHAR(255) NULL,
                uploaded_at DATETIME NULL,
                validation_status VARCHAR(20) NOT NULL DEFAULT 'Awaiting Upload',
                validation_remark TEXT NULL,
                validated_at DATETIME NULL,
                UNIQUE KEY unique_endorsement (student_id, company_id)
            )");
        } catch (\Throwable $e) { /* never break the page over this */ }
    }
}
ensureEndorsementLettersTable($conn);

/* ADJUSTMENT (batch applications from admin_monitoring_dashboard.php):
   • endorsement_letters.batch_id — students applied together share one letter and one upload
   • ojt_applications.source / in_table — dashboard applications wait in this page's
     application INBOX (in_table = 0) until the company clicks "Move to Table";
     student-initiated applications keep going straight to the table (in_table = 1). */
function ensureBatchFlowColumns($conn) {
    try {
        $c = $conn->query("SHOW COLUMNS FROM endorsement_letters LIKE 'batch_id'");
        if ($c && $c->num_rows === 0) $conn->query("ALTER TABLE endorsement_letters ADD COLUMN batch_id VARCHAR(40) NULL");
    } catch (\Throwable $e) {}
    try {
        $cols = [];
        $r = $conn->query("SHOW COLUMNS FROM ojt_applications");
        while ($r && ($col = $r->fetch_assoc())) $cols[$col['Field']] = true;
        if ($cols && !isset($cols['source']))   $conn->query("ALTER TABLE ojt_applications ADD COLUMN source VARCHAR(20) NOT NULL DEFAULT 'student'");
        if ($cols && !isset($cols['in_table'])) $conn->query("ALTER TABLE ojt_applications ADD COLUMN in_table TINYINT(1) NOT NULL DEFAULT 1");
    } catch (\Throwable $e) {}
}
ensureBatchFlowColumns($conn);

/* Endorsement row for one student at THIS company (no file blob). */
function getEndorsementRow($conn, $student_id, $company_id) {
    $q = $conn->prepare("SELECT id, validation_status, validation_remark, uploaded_at, (uploaded_file IS NOT NULL) AS has_file
                         FROM endorsement_letters WHERE student_id = ? AND company_id = ?");
    $q->bind_param("ii", $student_id, $company_id);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    $q->close();
    return $row ?: null;
}

/* Returns a message when the application may NOT be accepted yet, or null when it may.
   ADJUSTMENT: the endorsement letter is now validated separately (status dropdown + Save),
   and the application is accepted from its own column — so accepting requires the letter
   to be VERIFIED first. Applications from before endorsement letters are unaffected. */
function endorsementAcceptBlockMessage($conn, $student_id, $company_id) {
    $e = getEndorsementRow($conn, $student_id, $company_id);
    if (!$e) return null; // legacy application — no letter was ever issued
    if ($e['validation_status'] === 'Verified') return null;
    // ADJUSTMENT: there is no separate Verify step any more — an uploaded letter can be accepted.
    if ($e['validation_status'] === 'Pending' && !empty($e['has_file'])) return null;
    if ($e['validation_status'] === 'Rejected') {
        return "This endorsement letter was rejected. Please wait for the student to upload a corrected letter, then verify it before accepting.";
    }
    if (empty($e['has_file']) || $e['validation_status'] === 'Awaiting Upload') {
        return "The student has not uploaded the signed endorsement letter yet. It must be uploaded and verified before the application can be accepted.";
    }
    return "Please verify the endorsement letter first (set it to Verified and click Save), then accept the application.";
}

/* NEW (contract gate): once the student has uploaded the endorsement letter, the application can only be accepted
   or rejected after the student's Student/University Contract requirement has been VERIFIED by the administrator
   (the requirements row must hold a file with status 'Verified'). Returns a message while it is still blocked,
   or null. Applications without an uploaded letter (and legacy ones) are not affected. */
function studentContractVerified($conn, $student_id) {
    $q = $conn->prepare("SELECT 1 FROM requirements WHERE user_id = ? AND requirement_type = 'student_contract'
                         AND status = 'Verified' AND file_name IS NOT NULL AND LENGTH(file_name) > 0 LIMIT 1");
    $q->bind_param("i", $student_id);
    $q->execute();
    $ok = (bool)$q->get_result()->fetch_row();
    $q->close();
    return $ok;
}
function contractBlockMessage($conn, $student_id, $company_id) {
    try {
        $e = getEndorsementRow($conn, $student_id, $company_id);
        if (!$e || empty($e['has_file'])) return null;          // the rule starts once the letter is uploaded
        if (studentContractVerified($conn, (int)$student_id)) return null;
        return "The student's Student/University Contract has not been verified yet. The application can be accepted or rejected once the administrator verifies it.";
    } catch (\Throwable $e) {
        error_log('add_ojt_student.php: contract gate check failed: ' . $e->getMessage());
        return null; // never block on an error of our own
    }
}
// table rows: letter uploaded + contract not verified (needs the contract_verified column from fetchApplicantRows)
function aplContractBlocked($app) {
    return !empty($app['endo_has_file']) && (int)($app['contract_verified'] ?? 0) === 0;
}

function endoStatusMeta($status) {
    switch ($status) {
        case 'Awaiting Upload': return ['awaiting', 'fa-hourglass-half',  'Awaiting Upload'];
        case 'Pending':         return ['pending',  'fa-magnifying-glass', 'For Validation'];
        case 'Verified':        return ['verified', 'fa-circle-check',     'Verified'];
        case 'Rejected':        return ['rejected', 'fa-circle-xmark',     'Rejected'];
        default:                return ['none',     'fa-file-circle-question', 'No Letter on File'];
    }
}

/* Pending applications for this company, joined with their endorsement letter state. */
function fetchApplicantRows($conn, $company_id) {
    $st = $conn->prepare("
        SELECT a.*, u.first_name, u.last_name, u.email, u.course, si.student_photo,
               si.day_sched, si.evening_sched,
               el.id AS endo_id, el.validation_status AS endo_status, el.validation_remark AS endo_remark,
               el.uploaded_at AS endo_uploaded_at, el.sent_at AS endo_sent_at, el.uploaded_mime AS endo_mime,
               el.uploaded_name AS endo_uploaded_name, el.batch_id AS endo_batch_id,
               (el.uploaded_file IS NOT NULL) AS endo_has_file,
               (SELECT COUNT(*) FROM requirements rq WHERE rq.user_id = a.student_id AND rq.requirement_type = 'student_contract'
                   AND rq.status = 'Verified' AND rq.file_name IS NOT NULL AND LENGTH(rq.file_name) > 0) AS contract_verified
        FROM ojt_applications a
        INNER JOIN users u ON u.id = a.student_id
        LEFT JOIN student_information si ON si.user_id = u.id
        LEFT JOIN endorsement_letters el ON el.student_id = a.student_id AND el.company_id = a.company_id
        WHERE a.company_id = ? AND a.phase = 'pending' AND a.in_table = 1 -- ADJUSTMENT: inbox items stay out until moved
        ORDER BY a.id DESC
    ");
    $st->bind_param("i", $company_id);
    $st->execute();
    $res = $st->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $st->close();
    return aplGroupBatches($rows);
}

/* ADJUSTMENT: keep students of one admin batch next to each other (newest batch / application
   first, members in their original order) and tell every row how big its batch is. */
function aplGroupBatches(array $rows) {
    $groups = [];
    foreach ($rows as $r) {
        $key = !empty($r['endo_batch_id']) ? 'b:' . $r['endo_batch_id'] : 'a:' . $r['id'];
        if (!isset($groups[$key])) $groups[$key] = ['max' => 0, 'rows' => []];
        $groups[$key]['rows'][] = $r;
        $groups[$key]['max'] = max($groups[$key]['max'], (int)$r['id']);
    }
    uasort($groups, fn($a, $b) => $b['max'] <=> $a['max']);
    $out = [];
    foreach ($groups as $g) {
        usort($g['rows'], fn($a, $b) => (int)$a['id'] <=> (int)$b['id']);
        $batchBlocked = (bool)array_filter($g['rows'], 'aplContractBlocked'); // a batch is accepted / rejected as a whole
        foreach ($g['rows'] as $r) { $r['batch_size'] = count($g['rows']); $r['batch_contract_blocked'] = $batchBlocked; $out[] = $r; }
    }
    return $out;
}

/* Lightweight fingerprint of the applicants table, so polling only re-renders on change. */
function applicantTableSignature($rows) {
    $parts = [];
    foreach ($rows as $r) {
        $parts[] = [(int)$r['id'], $r['endo_id'] ?? null, $r['endo_status'] ?? null, $r['endo_uploaded_at'] ?? null, $r['endo_remark'] ?? null,
                    $r['day_sched'] ?? null, $r['evening_sched'] ?? null, (int)($r['contract_verified'] ?? 0)]; // schedule / contract: the table re-renders when they change
    }
    return md5(json_encode($parts));
}

/* One row of the "OJT Applicants — Endorsement Letter Validation" table.
   Carries the SAME data-* attributes as an inbox .ar-card, so the existing
   Full View (Digital Resume) modal can open straight from the row. */
function renderApplicantRow($conn, $company_id, $company_name, $app, $currentCount) {
    $d   = buildAppCardData($conn, $company_id, $company_name, $app, $currentCount);
    $id  = (int)$app['id'];
    $h   = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
    $endoId = !empty($app['endo_id']) ? (int)$app['endo_id'] : 0;
    $status = $endoId ? ($app['endo_status'] ?: 'Awaiting Upload') : null;
    [$cls, $icon, $label] = endoStatusMeta($status);
    $nameJs = $h(json_encode($d['name']));

    $photo = $d['photo']
        ? '<img src="data:image/jpeg;base64,' . $d['photo'] . '" class="apl-photo" alt="" onclick="openPreview(this.src)">'
        : '<div class="apl-photo apl-photo-ph">' . $h(strtoupper(substr($app['first_name'] ?? '', 0, 1) . substr($app['last_name'] ?? '', 0, 1))) . '</div>';

    /* ADJUSTMENT: the skill / experience count (Skills & Experience column) and the
       issued / uploaded date-time stamp (Endorsement Letter Validation column) are no
       longer shown, so they are no longer computed here. */

    $remark = ($status === 'Rejected' && trim((string)$app['endo_remark']) !== '')
        ? '<div class="endo-remark"><i class="fas fa-comment-dots"></i> ' . nl2br($h($app['endo_remark'])) . '</div>' : '';

    $btnIssued = $endoId ? '<button type="button" class="endo-btn ghost" onclick="openEndoViewer(' . $endoId . ', \'letter\', ' . $id . ', ' . $nameJs . ', \'' . $h($status) . '\')"><i class="fas fa-envelope-open-text"></i> Issued Letter</button>' : '';
    $btnUpload = ($endoId && !empty($app['endo_has_file']))
        ? '<button type="button" class="endo-btn view" onclick="openEndoViewer(' . $endoId . ', \'upload\', ' . $id . ', ' . $nameJs . ', \'' . $h($status) . '\')"><i class="fas fa-file-signature"></i> ' . ($status === 'Rejected' ? 'Last Upload' : 'View Uploaded') . '</button>' : '';

    $actions = '';
    $note    = '';
    if ($status === null) {
        $note    = 'Approved before endorsement letters were issued.';
        // ADJUSTMENT: Accept moved to the new Application column.
    } elseif ($status === 'Awaiting Upload') {
        $note    = 'Waiting for the student to upload the signed letter.';
        $actions = $btnIssued;
    } elseif ($status === 'Pending') {
        $actions = $btnUpload
                 . '<button type="button" class="endo-btn verify" onclick="verifyEndorsement(' . $id . ', this, ' . $nameJs . ')"><i class="fas fa-check"></i> Verify</button>'
                 . '<button type="button" class="endo-btn reject" onclick="openEndoRejectModal(' . $id . ', ' . $nameJs . ')"><i class="fas fa-times"></i> Reject</button>';
    } elseif ($status === 'Rejected') {
        $note    = 'Waiting for the student to upload a corrected letter.';
        $actions = $btnUpload . $btnIssued;
    }

    /* ADJUSTMENT: Endorsement Letter Validation — same style as the administrator's requirement
       validation: status dropdown (+ Reason when Rejected) + Save, with an undo toast after saving.
       Letters not uploaded yet, and applications from before endorsement letters, keep their
       previous display. A verified letter is locked, like an admin-verified requirement. */
    if (in_array($status, ['Pending', 'Verified', 'Rejected'], true) && !empty($app['endo_has_file'])) {
        global $ENDO_REJECT_REASONS;
        $reasons = $ENDO_REJECT_REASONS;
        $curRemark = trim((string)($app['endo_remark'] ?? ''));
        if ($curRemark !== '' && !in_array($curRemark, $reasons, true)) $reasons[] = $curRemark; // keep an older free-text remark selectable
        $viewBtn = '<button type="button" class="endo-btn view" onclick="openEndoViewer(' . $endoId . ', \'upload\', ' . $id . ', ' . $nameJs . ', \'' . $h($status) . '\')"><i class="fas fa-file-signature"></i> ' . ($status === 'Rejected' ? 'Last Upload' : 'View Uploaded') . '</button>';
        if ($status === 'Verified') {
            // ADJUSTMENT: only the uploaded file is shown — no "Letter verified" status text.
            // (Still locked: a verified letter has no dropdown / Save.)
            $validationCell = '<div class="endo-actions is-first endo-val-top">' . $viewBtn . '</div>'; // ADJUSTMENT: View Uploaded button (card design reverted)
        } else {
            /* ADJUSTMENT: no Verified / Rejected dropdown in the column any more — the Remark dropdown
               and Save now live on the uploaded-letter preview (View Uploaded). */
            $validationCell = '<div class="endo-actions is-first endo-val-top">' . $viewBtn . '</div>'
                . ($status === 'Rejected' && $curRemark !== ''
                    ? '<div class="endo-remark"><i class="fas fa-comment-dots"></i> <b>Remark:</b> ' . nl2br($h($curRemark)) . '</div>' : '');
        }
    } else {
        /* ADJUSTMENT: this column only shows the student's uploaded file — no status badge
           (Awaiting Upload / No Letter on File), no status note, and no Issued Letter button.
           Until a file is uploaded the cell shows a quiet placeholder. */
        $validationCell = ($status === 'Awaiting Upload')
            // ADJUSTMENT: letter issued, nothing uploaded yet → the "Waiting for the student to upload
            // the signed letter." note (card design reverted).
            ? '<div class="endo-note is-first">Waiting for the student to upload the signed letter.</div>'
            : (($status === 'Rejected')
                // ADJUSTMENT: rejected → the uploaded file was deleted; the company's remark is shown.
                ? '<div class="endo-remark is-first"><i class="fas fa-comment-dots"></i> <b>Remark:</b> '
                  . (trim((string)($app['endo_remark'] ?? '')) !== '' ? nl2br($h($app['endo_remark'])) : '&mdash;') . '</div>'
                : '<span class="endo-no-file" title="No uploaded file yet">&mdash;</span>');
    }

    /* ADJUSTMENT: new Application column — Accept (existing acceptApp confirm) and Reject
       (existing reject-with-reason modal). Accept is available once the letter is Verified
       (or for applications from before endorsement letters). */
    // ADJUSTMENT: no Verify step — accept once the student's letter is uploaded (or for older applications).
    $contractBlocked = aplContractBlocked($app) || !empty($app['batch_contract_blocked']); // NEW (contract gate)
    $canAccept = ($status === null || $status === 'Verified' || ($status === 'Pending' && !empty($app['endo_has_file']))) && !$contractBlocked;
    // ADJUSTMENT (batches): one Accept / Reject for the whole admin batch — it acts on every member.
    $batchId   = (string)($app['endo_batch_id'] ?? '');
    $isBatch   = ($batchId !== '' && (int)($app['batch_size'] ?? 1) > 1);
    $bidJs     = $h(json_encode($batchId));
    // ADJUSTMENT: icon buttons with tooltips (the tooltip sits on a wrapper so it also shows on the disabled Accept).
    $contractTip = "Waiting for the student's Student/University Contract to be verified";
    $acceptTip = $contractBlocked ? $contractTip : ($canAccept ? ($isBatch ? 'Accept the whole batch' : 'Accept application') : "Waiting for the student's uploaded endorsement letter"); // ADJUSTMENT
    $rejectTip = $contractBlocked ? $contractTip : ($isBatch ? 'Reject the whole batch' : 'Reject application');
    $applicationCell = '<div class="apl-app-actions">'
        . '<span class="apl-tip" data-tip="' . $h($acceptTip) . '">'
        .   '<button type="button" class="endo-btn verify apl-icon-btn" aria-label="' . $h($acceptTip) . '"' . ($canAccept ? ' onclick="' . ($isBatch ? 'acceptBatch(' . $bidJs . ', this)' : 'acceptApp(' . $id . ', this)') . '"' : ' disabled') . '><i class="fas fa-check"></i></button>'
        . '</span>'
        . '<span class="apl-tip" data-tip="' . $h($rejectTip) . '">'
        .   '<button type="button" class="endo-btn reject apl-icon-btn" aria-label="' . $h($rejectTip) . '"' . ($contractBlocked ? ' disabled' : ' onclick="' . ($isBatch ? 'rejectBatch(' . $bidJs . ')' : 'openRejectModal(' . $id . ')') . '"') . '><i class="fas fa-times"></i></button>'
        . '</span>'
        . '</div>'
        . ($contractBlocked ? '<div class="endo-note">Waiting for the Student/University Contract to be verified.</div>'
            : ($canAccept ? '' : '<div class="endo-note">Waiting for the uploaded letter.</div>')); // ADJUSTMENT

    /* NEW (schedule change): the student's Day / Evening Schedule from AccomForm.php + an Edit button */
    $dayRaw   = trim((string)($app['day_sched'] ?? ''));
    $eveRaw   = trim((string)($app['evening_sched'] ?? ''));
    $hasSched = ($dayRaw !== '' || $eveRaw !== '');
    $schedCell = '<div class="apl-sched-row"><div class="apl-sched-lines">'
        . '<div class="apl-sched-line"><span class="apl-sched-k">Day</span><span class="apl-sched-v">' . $h(aplSchedLabel($dayRaw)) . '</span></div>'
        . '<div class="apl-sched-line"><span class="apl-sched-k">Evening</span><span class="apl-sched-v">' . $h(aplSchedLabel($eveRaw)) . '</span></div>'
        . '</div>'
        . ($hasSched
            ? '<button type="button" class="apl-sched-edit" onclick="openSchedModal(' . $id . ')" title="Set up a new schedule for this student"><i class="fas fa-pen"></i> Edit</button>'
            : '')
        . '</div>'
        . ($hasSched ? '' : '<div class="apl-sched-note">Not set by the student yet.</div>');

    return '<tr id="appRow' . $id . '" class="applicant-row"'
        . ' data-app-id="' . $id . '"'
        . ' data-day-sched="' . $h($dayRaw) . '"'
        . ' data-evening-sched="' . $h($eveRaw) . '"'
        . ' data-name="' . $h($d['name']) . '"'
        . ' data-email="' . $h($d['email']) . '"'
        . ' data-course="' . $h($d['course']) . '"'
        . ' data-company="' . $h($company_name) . '"'
        . ' data-skills="' . $h(json_encode($d['skills'])) . '"'
        . ' data-exps="' . $h(json_encode($d['exps'])) . '"'
        . ' data-photo="' . $h($d['photo'] ?? '') . '"'
        . ' data-submitted="' . $h($d['submitted']) . '"'
        . ' data-ojt-count="' . (int)$currentCount . '"'
        . ' data-req-docs="' . $h(json_encode($d['reqDocs'])) . '"'
        . ' data-photo-status="' . $h($d['photoStatus']) . '"'
        . ' data-endo-status="' . $h($status ?? '') . '"'
        . ' data-batch="' . ($isBatch ? $h($batchId) : '') . '">' // ADJUSTMENT: shared cells per batch (see aplRenderPage)
        . '<td><div class="apl-student">' . $photo . '<div class="apl-student-text">'
            . '<div class="apl-name">' . $h($d['name']) . '</div>'
            . '<div class="apl-sub">' . $h($d['email']) . '</div>'
            . ($d['submitted'] !== '' ? '<div class="apl-sub">Applied ' . $h($d['submitted']) . '</div>' : '')
            . ($isBatch ? '<div class="apl-batch-tag"><i class="fas fa-users"></i> Batch of ' . (int)$app['batch_size'] . '</div>' : '') // ADJUSTMENT
        . '</div></div></td>'
        . '<td class="apl-course">' . $h($d['course'] !== '' ? $d['course'] : '—') . '</td>'
        . '<td class="apl-sched">' . $schedCell . '</td>'
        . '<td><button type="button" class="btn-resume-preview" onclick="openAppFullView(' . $id . ')">View Skill &amp; Experience</button></td>' // ADJUSTMENT: renamed from "Preview Resume"
        . '<td>' . $validationCell . '</td>'
        . '<td>' . $applicationCell . '</td>'
        . '</tr>';
}

function renderApplicantRowsHtml($conn, $company_id, $company_name, $rows) {
    if (empty($rows)) {
        return '<tr class="apl-empty-row"><td colspan="6" class="table-empty-state"><i class="fas fa-envelope-open-text"></i>No applicants yet. Applications approved by the administrator appear here.</td></tr>';
    }
    $cq = $conn->prepare("SELECT COUNT(*) AS cnt FROM ojt_assignments WHERE company_id = ?");
    $cq->bind_param("i", $company_id);
    $cq->execute();
    $count = (int)($cq->get_result()->fetch_assoc()['cnt'] ?? 0);
    $cq->close();
    $html = '';
    foreach ($rows as $r) $html .= renderApplicantRow($conn, $company_id, $company_name, $r, $count);
    return $html;
}

/* ── GET: stream the student's uploaded endorsement letter (image/PDF) ── */
if (isset($_GET['view_endorsement_upload'])) {
    $eid = intval($_GET['view_endorsement_upload']);
    $q = $conn->prepare("SELECT uploaded_file, uploaded_mime, uploaded_name FROM endorsement_letters WHERE id = ? AND company_id = ?");
    $q->bind_param("ii", $eid, $company_id);
    $q->execute();
    $f = $q->get_result()->fetch_assoc();
    $q->close();
    if (!$f || $f['uploaded_file'] === null) { http_response_code(404); echo 'File not found.'; exit; }
    $mime = $f['uploaded_mime'] ?: 'application/octet-stream';
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $f['uploaded_name'] ?: 'endorsement_letter');
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . $name . '"');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . strlen($f['uploaded_file']));
    echo $f['uploaded_file'];
    exit;
}

/* ── GET: the endorsement letter exactly as issued by the administrator ── */
if (isset($_GET['view_endorsement_letter'])) {
    $eid = intval($_GET['view_endorsement_letter']);
    $q = $conn->prepare("SELECT letter_data FROM endorsement_letters WHERE id = ? AND company_id = ?");
    $q->bind_param("ii", $eid, $company_id);
    $q->execute();
    $f = $q->get_result()->fetch_assoc();
    $q->close();
    if (!$f) { http_response_code(404); echo 'Letter not found.'; exit; }
    $letter = json_decode($f['letter_data'] ?? '{}', true);
    header('Content-Type: text/html; charset=UTF-8');
    echo buildEndorsementFormHTML(is_array($letter) ? $letter : []);
    exit;
}

/* ── POST: reject the uploaded endorsement letter (remarks required) ── */
if (isset($_POST['reject_endorsement'])) {
    header('Content-Type: application/json');
    $app_id = intval($_POST['app_id'] ?? 0);
    $remark = trim($_POST['remark'] ?? '');
    if ((function_exists('mb_strlen') ? mb_strlen($remark) : strlen($remark)) < 5) {
        echo json_encode(['success' => false, 'message' => 'Please enter remarks (at least 5 characters).']);
        exit;
    }
    $q = $conn->prepare("
        SELECT el.id AS endo_id, el.validation_status, u.email, u.first_name, u.last_name
        FROM ojt_applications a
        INNER JOIN users u ON u.id = a.student_id
        INNER JOIN endorsement_letters el ON el.student_id = a.student_id AND el.company_id = a.company_id
        WHERE a.id = ? AND a.company_id = ?
    ");
    $q->bind_param("ii", $app_id, $company_id);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    $q->close();
    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Endorsement letter not found or application already processed.']);
        exit;
    }
    if ($row['validation_status'] !== 'Pending') {
        echo json_encode(['success' => false, 'message' => 'Only an uploaded letter that is awaiting validation can be rejected.']);
        exit;
    }
    // ADJUSTMENT: rejecting deletes the uploaded file (the student uploads a corrected one).
    $up = $conn->prepare("UPDATE endorsement_letters SET validation_status = 'Rejected', validation_remark = ?, validated_at = NOW(),
                          uploaded_file = NULL, uploaded_mime = NULL, uploaded_name = NULL, uploaded_at = NULL WHERE id = ?");
    $up->bind_param("si", $remark, $row['endo_id']);
    $ok = $up->execute();
    $up->close();
    if ($ok && !empty($row['email'])) {
        // UPDATED (this adjustment): administrator.php email design, no emoji
        $body = buildEndorsementRejectedEmail($row['first_name'] . ' ' . $row['last_name'], $company_name, $remark, 'Remarks');
        sendMail($row['email'], 'Endorsement Letter Rejected – ' . $company_name, $body);
    }
    echo json_encode(['success' => (bool)$ok, 'message' => $ok ? 'Endorsement letter rejected. The student was notified with your remarks.' : 'Failed to update. Please try again.']);
    exit;
}

/* ================= ADJUSTMENT: ENDORSEMENT LETTER VALIDATION — dropdown + Save + undo =================
   Same pattern as the administrator's requirement validation:
     • status dropdown (Pending / Verified / Rejected) + Reason dropdown when Rejected + Save
     • the change is saved immediately and an undo token is returned (5-minute undo toast)
     • a rejection email to the student is HELD until the undo window closes
       (toast expires / dismissed / another change / page left) — undo cancels it. */
$ENDO_REJECT_REASONS = [
    'Not signed by the required signatories',
    'Blurry or unreadable copy',
    'Incomplete pages',
    'Wrong document uploaded',
    'Details do not match the issued letter',
];
const ENDO_UNDO_SECONDS = 300;

function ensureEndoUndoTable($conn) {
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS endorsement_validation_undo (
            token VARCHAR(64) PRIMARY KEY,
            company_id INT NOT NULL,
            endo_id INT NOT NULL,
            app_id INT NOT NULL,
            prev_status VARCHAR(20) NULL,
            prev_remark TEXT NULL,
            prev_validated_at DATETIME NULL,
            new_status VARCHAR(20) NOT NULL,
            new_remark TEXT NULL,
            email_pending TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    } catch (\Throwable $e) {}
    /* ADJUSTMENT: a rejection deletes the uploaded file; a backup is kept ONLY inside the undo
       record so ↩ Undo can restore it. The backup is discarded when the undo window closes. */
    try {
        $chk = $conn->query("SHOW COLUMNS FROM endorsement_validation_undo LIKE 'prev_file'");
        if ($chk && $chk->num_rows === 0) {
            $conn->query("ALTER TABLE endorsement_validation_undo
                ADD COLUMN prev_file LONGBLOB NULL,
                ADD COLUMN prev_mime VARCHAR(100) NULL,
                ADD COLUMN prev_name VARCHAR(255) NULL,
                ADD COLUMN prev_uploaded_at DATETIME NULL");
        }
    } catch (\Throwable $e) {}
}
ensureEndoUndoTable($conn);
// ADJUSTMENT: remember the batch so a remark / undo on a shared letter covers every student in it.
try {
    $c = $conn->query("SHOW COLUMNS FROM endorsement_validation_undo LIKE 'batch_id'");
    if ($c && $c->num_rows === 0) $conn->query("ALTER TABLE endorsement_validation_undo ADD COLUMN batch_id VARCHAR(40) NULL");
} catch (\Throwable $e) {}

/* Sends the held rejection email (if the letter is still Rejected) and forgets the token. */
function endoCommitUndoToken($conn, $token, $company_id, $company_name) {
    $q = $conn->prepare("SELECT * FROM endorsement_validation_undo WHERE token = ? AND company_id = ?");
    $q->bind_param("si", $token, $company_id);
    $q->execute();
    $t = $q->get_result()->fetch_assoc();
    $q->close();
    if (!$t) return false;
    if ((int)$t['email_pending'] === 1 && $t['new_status'] === 'Rejected') {
        // ADJUSTMENT: a batch letter → every student in the batch gets the email.
        $cbid = (string)($t['batch_id'] ?? '');
        $s = $conn->prepare("SELECT el.validation_status, el.validation_remark, u.email, u.first_name, u.last_name
                             FROM endorsement_letters el INNER JOIN users u ON u.id = el.student_id
                             WHERE el.company_id = ? AND (el.id = ? OR (? <> '' AND el.batch_id = ?))");
        $s->bind_param("iiss", $company_id, $t['endo_id'], $cbid, $cbid);
        $s->execute();
        $allRows = $s->get_result()->fetch_all(MYSQLI_ASSOC);
        $s->close();
        foreach ($allRows as $row) {
        if ($row && $row['validation_status'] === 'Rejected' && !empty($row['email'])) {
            $remark = (string)$row['validation_remark'];
            // UPDATED (this adjustment): administrator.php email design, no emoji
            $body = buildEndorsementRejectedEmail($row['first_name'] . ' ' . $row['last_name'], $company_name, $remark, 'Reason');
            sendMail($row['email'], 'Endorsement Letter Rejected – ' . $company_name, $body);
        }
        } // ADJUSTMENT: end of the per-student loop (batch letters)
    }
    $d = $conn->prepare("DELETE FROM endorsement_validation_undo WHERE token = ?");
    $d->bind_param("s", $token);
    $d->execute();
    $d->close();
    return true;
}

/* Commits every expired token of this company (their undo window is over). */
function endoFlushExpiredUndo($conn, $company_id, $company_name) {
    $q = $conn->prepare("SELECT token FROM endorsement_validation_undo WHERE company_id = ? AND created_at < (NOW() - INTERVAL " . (int)ENDO_UNDO_SECONDS . " SECOND)");
    $q->bind_param("i", $company_id);
    $q->execute();
    $res = $q->get_result();
    $tokens = [];
    while ($r = $res->fetch_assoc()) $tokens[] = $r['token'];
    $q->close();
    foreach ($tokens as $tk) endoCommitUndoToken($conn, $tk, $company_id, $company_name);
    return count($tokens);
}

/* ── POST: save the letter's validation status (dropdown + Save) ── */
if (isset($_POST['endo_validate'])) {
    header('Content-Type: application/json');
    $app_id = intval($_POST['app_id'] ?? 0);
    $status = trim($_POST['status'] ?? '');
    $remark = trim($_POST['remark'] ?? '');
    if (!in_array($status, ['Pending', 'Verified', 'Rejected'], true)) {
        echo json_encode(['success' => false, 'message' => 'Please choose a valid status.']); exit;
    }
    if ($status === 'Rejected' && $remark === '') {
        echo json_encode(['success' => false, 'message' => 'Please choose a reason for rejecting the letter.']); exit;
    }
    if ($status !== 'Rejected') $remark = '';

    $q = $conn->prepare("
        SELECT el.id AS endo_id, el.validation_status, el.validation_remark, el.validated_at, el.batch_id,
               (el.uploaded_file IS NOT NULL) AS has_file, u.first_name, u.last_name
        FROM ojt_applications a
        INNER JOIN users u ON u.id = a.student_id
        INNER JOIN endorsement_letters el ON el.student_id = a.student_id AND el.company_id = a.company_id
        WHERE a.id = ? AND a.company_id = ? AND a.phase = 'pending'
    ");
    $q->bind_param("ii", $app_id, $company_id);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    $q->close();
    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Endorsement letter not found or the application was already processed.']); exit;
    }
    if (empty($row['has_file'])) {
        echo json_encode(['success' => false, 'message' => 'The student has not uploaded the signed letter yet.']); exit;
    }
    if ($row['validation_status'] === $status && (string)$row['validation_remark'] === $remark) {
        echo json_encode(['success' => false, 'message' => 'No changes to save.']); exit;
    }

    // A new change closes the previous undo window for this letter.
    $old = $conn->prepare("SELECT token FROM endorsement_validation_undo WHERE endo_id = ? AND company_id = ?");
    $old->bind_param("ii", $row['endo_id'], $company_id);
    $old->execute();
    $oldRes = $old->get_result();
    $oldTokens = [];
    while ($r = $oldRes->fetch_assoc()) $oldTokens[] = $r['token'];
    $old->close();
    foreach ($oldTokens as $tk) endoCommitUndoToken($conn, $tk, $company_id, $company_name);

    $remarkDb = ($status === 'Rejected') ? $remark : null;
    $token = bin2hex(random_bytes(16));
    $emailPending = ($status === 'Rejected') ? 1 : 0;

    if ($status === 'Rejected') {
        /* ADJUSTMENT: rejecting DELETES the student's uploaded file. The undo record is written
           first and copies the file inside the database (INSERT … SELECT — no large packet
           through PHP), so ↩ Undo can restore it; the copy goes away when the window closes. */
        $ins = $conn->prepare("INSERT INTO endorsement_validation_undo
            (token, company_id, endo_id, app_id, prev_status, prev_remark, prev_validated_at, new_status, new_remark, email_pending,
             prev_file, prev_mime, prev_name, prev_uploaded_at, batch_id)
            SELECT ?, ?, id, ?, validation_status, validation_remark, validated_at, ?, ?, ?,
                   uploaded_file, uploaded_mime, uploaded_name, uploaded_at, batch_id
            FROM endorsement_letters WHERE id = ?");
        $ins->bind_param("siissii", $token, $company_id, $app_id, $status, $remarkDb, $emailPending, $row['endo_id']);
        $okBackup = $ins->execute();
        $ins->close();
        if (!$okBackup) { echo json_encode(['success' => false, 'message' => 'Failed to save. Please try again.']); exit; }

        // ADJUSTMENT: a batch shares one letter — the remark (and the deletion) covers every student
        // in the batch who has not been accepted yet.
        $bid = (string)($row['batch_id'] ?? '');
        $up = $conn->prepare("UPDATE endorsement_letters
            SET validation_status = ?, validation_remark = ?, validated_at = NOW(),
                uploaded_file = NULL, uploaded_mime = NULL, uploaded_name = NULL, uploaded_at = NULL
            WHERE id = ? OR (? <> '' AND batch_id = ? AND company_id = ? AND validation_status <> 'Verified')");
        $up->bind_param("ssissi", $status, $remarkDb, $row['endo_id'], $bid, $bid, $company_id);
        $ok = $up->execute();
        $up->close();
        if (!$ok) {
            $rb = $conn->prepare("DELETE FROM endorsement_validation_undo WHERE token = ?");
            $rb->bind_param("s", $token); $rb->execute(); $rb->close();
            echo json_encode(['success' => false, 'message' => 'Failed to save. Please try again.']); exit;
        }
    } else {
        $up = $conn->prepare("UPDATE endorsement_letters SET validation_status = ?, validation_remark = ?, validated_at = " . ($status === 'Pending' ? "NULL" : "NOW()") . " WHERE id = ?");
        $up->bind_param("ssi", $status, $remarkDb, $row['endo_id']);
        $ok = $up->execute();
        $up->close();
        if (!$ok) { echo json_encode(['success' => false, 'message' => 'Failed to save. Please try again.']); exit; }

        $ins = $conn->prepare("INSERT INTO endorsement_validation_undo
            (token, company_id, endo_id, app_id, prev_status, prev_remark, prev_validated_at, new_status, new_remark, email_pending)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $ins->bind_param("siiisssssi", $token, $company_id, $row['endo_id'], $app_id,
            $row['validation_status'], $row['validation_remark'], $row['validated_at'], $status, $remarkDb, $emailPending);
        $ins->execute();
        $ins->close();
    }

    $name = trim($row['first_name'] . ' ' . $row['last_name']);
    echo json_encode([
        'success'    => true,
        'undo_token' => $token,
        'undo_label' => ($status === 'Rejected' ? 'Remark sent — uploaded letter deleted' : 'Endorsement letter → ' . $status) . ' · ' . $name, // ADJUSTMENT
        'status'     => $status,
        'undo_secs'  => ENDO_UNDO_SECONDS,
    ]);
    exit;
}

/* ── POST: undo the last validation change (within the undo window) ── */
if (isset($_POST['endo_undo'])) {
    header('Content-Type: application/json');
    $token = (string)($_POST['undo_token'] ?? '');
    $q = $conn->prepare("SELECT * FROM endorsement_validation_undo WHERE token = ? AND company_id = ?");
    $q->bind_param("si", $token, $company_id);
    $q->execute();
    $t = $q->get_result()->fetch_assoc();
    $q->close();
    if (!$t) { echo json_encode(['success' => false, 'message' => 'This change can no longer be undone.']); exit; }

    $c = $conn->prepare("SELECT el.validation_status FROM endorsement_letters el
                         INNER JOIN ojt_applications a ON a.student_id = el.student_id AND a.company_id = el.company_id AND a.phase = 'pending'
                         WHERE el.id = ? AND el.company_id = ?");
    $c->bind_param("ii", $t['endo_id'], $company_id);
    $c->execute();
    $cur = $c->get_result()->fetch_assoc();
    $c->close();
    $d = $conn->prepare("DELETE FROM endorsement_validation_undo WHERE token = ?");
    $d->bind_param("s", $token);
    if (!$cur) {
        $d->execute(); $d->close();
        echo json_encode(['success' => false, 'message' => 'The application was already accepted or rejected, so this can no longer be undone.']); exit;
    }
    if ($cur['validation_status'] !== $t['new_status']) {
        $d->execute(); $d->close();
        echo json_encode(['success' => false, 'message' => 'The letter has changed since (e.g. the student uploaded a new one), so this can no longer be undone.']); exit;
    }
    // ADJUSTMENT: for a batch letter, restore every student in the batch the remark was applied to.
    $ubid = (string)($t['batch_id'] ?? '');
    $up = $conn->prepare("UPDATE endorsement_letters SET validation_status = ?, validation_remark = ?, validated_at = ?
                          WHERE id = ? OR (? <> '' AND batch_id = ? AND company_id = ? AND validation_status = ?)");
    $up->bind_param("sssissis", $t['prev_status'], $t['prev_remark'], $t['prev_validated_at'], $t['endo_id'], $ubid, $ubid, $company_id, $t['new_status']);
    $ok = $up->execute();
    $up->close();
    // ADJUSTMENT: a rejection deleted the file — put it back from the undo record (copied inside the database).
    if ($ok && isset($t['prev_file']) && $t['prev_file'] !== null) {
        $rf = $conn->prepare("UPDATE endorsement_letters el
            INNER JOIN endorsement_validation_undo u ON u.token = ?
            SET el.uploaded_file = u.prev_file, el.uploaded_mime = u.prev_mime,
                el.uploaded_name = u.prev_name, el.uploaded_at = u.prev_uploaded_at
            WHERE el.id = ? OR (? <> '' AND el.batch_id = ? AND el.company_id = ? AND el.uploaded_file IS NULL AND el.validation_status = ?)");
        $prevSt = $t['prev_status'];
        $rf->bind_param("sissis", $token, $t['endo_id'], $ubid, $ubid, $company_id, $prevSt);
        $ok = $rf->execute();
        $rf->close();
    }
    $d->execute(); $d->close(); // undone → the held email is cancelled (and the file backup discarded)
    echo json_encode(['success' => (bool)$ok, 'app_id' => (int)$t['app_id'], 'prev_status' => $t['prev_status'],
                      'message' => $ok ? 'Change undone.' : 'Undo failed. Please try again.']);
    exit;
}

/* ── POST: undo window closed for a token (toast expired / dismissed / page left) ── */
if (isset($_POST['endo_commit'])) {
    header('Content-Type: application/json');
    endoCommitUndoToken($conn, (string)($_POST['undo_token'] ?? ''), $company_id, $company_name);
    echo json_encode(['success' => true]);
    exit;
}

/* ── POST: commit any expired tokens (called on page load) ── */
if (isset($_POST['endo_flush'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'committed' => endoFlushExpiredUndo($conn, $company_id, $company_name)]);
    exit;
}

/* ── POST: live refresh of the applicants table (re-renders only when something changed) ── */
if (isset($_POST['check_applicants_table'])) {
    /* FIX: discard any stray PHP output (warnings/notices printed while building the rows)
       so the browser always receives valid JSON — otherwise the live refresh silently stops
       and newly approved applications never appear in the table without a reload. */
    ob_start(); // our OWN buffer only — never touch the server's buffers (e.g. zlib compression)
    try {
        $rows = fetchApplicantRows($conn, $company_id);
        $sig  = applicantTableSignature($rows);
        $out  = ['success' => true, 'sig' => $sig, 'count' => count($rows), 'changed' => ($sig !== ($_POST['sig'] ?? ''))];
        if ($out['changed']) $out['html'] = renderApplicantRowsHtml($conn, $company_id, $company_name, $rows);
    } catch (\Throwable $e) {
        error_log('check_applicants_table failed: ' . $e->getMessage());
        $out = ['success' => false, 'message' => 'Unable to refresh the applicants table.'];
    }
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode($out, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/* ================= NEW (schedule change): SUPERVISOR SETS A NEW SCHEDULE FOR AN APPLICANT =================
   1) the student's Day / Evening Schedule (student_information, the values shown on AccomForm.php) is updated;
   2) the student's Student/University Contract is removed: the `requirements` row (the uploaded file is the blob in
      that row), the application snapshots of it, and the verified copies in uploads/<student>/ — the same clean-up
      company_list.php does for the Application SIT when a student replaces the preferred placement;
   3) the student goes back to "Pending" validation and the change is recorded in student_schedule_changes, which
      administrator.php reads to show the student again (like a replaced preferred placement);
   4) the student and the administrator(s) are e-mailed with the supervisor's reason.
   The database part runs in ONE transaction. Folder clean-up and e-mails run afterwards and can never undo or
   fail the change — problems are logged and reported in the message. */
if (isset($_POST['update_student_schedule'])) {
    ob_start(); // our OWN buffer only — stray warnings must never break the JSON
    $out = ['success' => false, 'message' => 'The schedule could not be updated. Please try again.'];
    $inTx = false;
    try {
        $appId  = (int)($_POST['app_id'] ?? 0);
        $reason = trim(preg_replace('/\s+/u', ' ', (string)($_POST['reason'] ?? '')));
        $newDay = aplNormalizeSched($_POST['day_sched'] ?? '');
        $newEve = aplNormalizeSched($_POST['evening_sched'] ?? '');

        if ($appId <= 0) throw new RuntimeException('Invalid application.');
        if ($newDay === null || $newEve === null) throw new RuntimeException('Please choose a Day Schedule and an Evening Schedule (Monday to Friday, or None).');
        if ($newDay === 'None' && $newEve === 'None') throw new RuntimeException('Day Schedule and Evening Schedule cannot both be "None". Please select at least one schedule.');
        $reasonLen = function_exists('mb_strlen') ? mb_strlen($reason) : strlen($reason);
        if ($reasonLen < 10)  throw new RuntimeException('Please give a reason for the change (at least 10 characters).');
        if ($reasonLen > 500) throw new RuntimeException('The reason is too long (500 characters at most).');

        $q = $conn->prepare("SELECT a.id, a.student_id, u.first_name, u.last_name, u.email, u.course
                             FROM ojt_applications a INNER JOIN users u ON u.id = a.student_id
                             WHERE a.id = ? AND a.company_id = ? AND a.phase = 'pending'");
        $q->bind_param("ii", $appId, $company_id);
        $q->execute();
        $app = $q->get_result()->fetch_assoc();
        $q->close();
        if (!$app) throw new RuntimeException('This application is no longer in your applicants table.');
        $sid = (int)$app['student_id'];

        if (!ensureScheduleChangesTable($conn)) throw new RuntimeException('The schedule history could not be prepared. Please try again.');

        $q = $conn->prepare("SELECT day_sched, evening_sched FROM student_information WHERE user_id = ? LIMIT 1");
        $q->bind_param("i", $sid);
        $q->execute();
        $cur = $q->get_result()->fetch_assoc();
        $q->close();
        if (!$cur) throw new RuntimeException('This student has not saved the SIT application form (AccomForm) yet, so there is no schedule to change.');
        $oldDay = (string)($cur['day_sched'] ?? '');
        $oldEve = (string)($cur['evening_sched'] ?? '');
        if (aplNormalizeSched($oldDay) === $newDay && aplNormalizeSched($oldEve) === $newEve) {
            throw new RuntimeException('The new schedule is the same as the current one. Nothing was changed.');
        }

        $conn->begin_transaction();
        $inTx = true;

        $u = $conn->prepare("UPDATE student_information SET day_sched = ?, evening_sched = ? WHERE user_id = ?");
        $u->bind_param("ssi", $newDay, $newEve, $sid);
        $u->execute();
        $u->close();

        // the Student/University Contract: the requirement row (= the uploaded file) and its application snapshots
        $d = $conn->prepare("DELETE FROM requirements WHERE user_id = ? AND requirement_type = 'student_contract'");
        $d->bind_param("i", $sid);
        $d->execute();
        $contractRemoved = $d->affected_rows > 0 ? 1 : 0;
        $d->close();
        $d = $conn->prepare("DELETE FROM application_requirements WHERE student_id = ? AND requirement_type = 'student_contract'");
        $d->bind_param("i", $sid);
        $d->execute();
        $d->close();

        // back to "Pending" so administrator.php lists the student again (the same value it stores while a requirement is not verified)
        try {
            $v = $conn->prepare("UPDATE users SET validation_status = 'Pending' WHERE id = ?");
            $v->bind_param("i", $sid);
            $v->execute();
            $v->close();
        } catch (\Throwable $e) { error_log('add_ojt_student.php: validation_status reset failed for student ' . $sid . ': ' . $e->getMessage()); }

        $h = $conn->prepare("INSERT INTO student_schedule_changes
                (student_id, company_id, old_day_sched, old_evening_sched, new_day_sched, new_evening_sched, reason, contract_removed)
                VALUES (?,?,?,?,?,?,?,?)");
        $h->bind_param("iisssssi", $sid, $company_id, $oldDay, $oldEve, $newDay, $newEve, $reason, $contractRemoved);
        $h->execute();
        $h->close();

        $conn->commit();
        $inTx = false;

        // ── after the commit: stored files + e-mails (never able to undo the change) ──
        $notes = [];
        $clean = aplRemoveContractFiles($conn, $sid);
        if (!empty($clean['files_failed'])) $notes[] = (int)$clean['files_failed'] . ' stored contract file(s) could not be deleted from the uploads folder';

        $studentName = trim($app['first_name'] . ' ' . $app['last_name']);
        $mail = ['student' => false, 'admins' => 0, 'admins_total' => 0];
        try {
            if (filter_var((string)$app['email'], FILTER_VALIDATE_EMAIL)) {
                $mail['student'] = sendMail($app['email'], 'Your OJT Schedule Has Been Updated',
                    buildScheduleChangedStudentEmail($studentName, $company_name, $oldDay, $oldEve, $newDay, $newEve, $reason, (bool)$contractRemoved));
            }
            $admins = aplAdminRecipients($conn);
            $mail['admins_total'] = count($admins);
            foreach ($admins as $adm) {
                if (sendMail($adm['email'], 'Student Schedule Updated: ' . $studentName,
                        buildScheduleChangedAdminEmail($adm['name'], $studentName, (string)($app['course'] ?? ''), $company_name, $supervisor_name, $oldDay, $oldEve, $newDay, $newEve, $reason, (bool)$contractRemoved))) {
                    $mail['admins']++;
                }
            }
        } catch (\Throwable $e) { error_log('add_ojt_student.php: schedule change e-mails failed: ' . $e->getMessage()); }
        if (!$mail['student'])                              $notes[] = "the e-mail to the student could not be sent";
        if ($mail['admins'] < $mail['admins_total'])        $notes[] = "the e-mail to the administrator could not be sent";
        if ($mail['admins_total'] === 0)                    $notes[] = "no administrator e-mail address is on file";

        $msg = "Schedule updated for " . $studentName . ". " . ($contractRemoved
            ? "The Student/University Contract was removed and has to be submitted again."
            : "No Student/University Contract was on file, so the student has to submit one that reflects the new schedule.");
        if (!$notes) $msg .= " The student and the administrator were notified by e-mail.";
        else         $msg .= " Note: " . implode('; ', $notes) . ".";
        $out = ['success' => true, 'message' => $msg, 'mail' => $mail, 'contract_removed' => (bool)$contractRemoved,
                'day_sched' => $newDay, 'evening_sched' => $newEve];
    } catch (RuntimeException $e) {
        if ($inTx) { try { $conn->rollback(); } catch (\Throwable $e2) {} }
        $out = ['success' => false, 'message' => $e->getMessage()];
    } catch (\Throwable $e) {
        if ($inTx) { try { $conn->rollback(); } catch (\Throwable $e2) {} }
        error_log('update_student_schedule failed: ' . $e->getMessage());
        $out = ['success' => false, 'message' => 'The schedule could not be updated. Nothing was changed. Please try again.'];
    }
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode($out, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/* ================= ADD OJT STUDENT LOGIC (UNTOUCHED) ================= */
if (isset($_POST['add_student'])) {
    $search = trim($_POST['student_search']);
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? OR CONCAT(first_name,' ',last_name) = ?");
    $stmt->bind_param("ss", $search, $search);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user       = $result->fetch_assoc();
        $student_id = $user['id'];

        $reqCheck = $conn->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN status='Verified' THEN 1 ELSE 0 END) as verified_count FROM requirements WHERE user_id=?");
        $reqCheck->bind_param("i", $student_id);
        $reqCheck->execute();
        $reqResult = $reqCheck->get_result()->fetch_assoc();

        if ($reqResult['total'] > 0 && $reqResult['total'] == $reqResult['verified_count']) {
            $companyCheck = $conn->prepare("SELECT company_id FROM ojt_assignments WHERE student_id=?");
            $companyCheck->bind_param("i", $student_id);
            $companyCheck->execute();
            if ($companyCheck->get_result()->num_rows > 0) {
                $message = "Student is already registered in another company.";
            } else {
                $insert = $conn->prepare("INSERT INTO ojt_assignments (company_id, student_id) VALUES (?,?)");
                $insert->bind_param("ii", $company_id, $student_id);
                if ($insert->execute()) {
                    if (!empty($student_id)) {
                        $conn->query("UPDATE users SET deploy_status='Deployed' WHERE id=" . intval($student_id));
                    }
                    $message = "Student successfully added.";
                }
            }
        } else {
            $message = "Cannot add student. Requirements not fully verified.";
        }
    } else {
        $message = "Student not found in system.";
    }
}

/* ================= REMOVE STUDENT LOGIC — AJAX ================= */
if (isset($_POST['remove_student'])) {
    $ajax_response = ['success' => false, 'message' => 'Unknown error'];

    $ojt_id = intval($_POST['ojt_id']);

    $stmt_student = $conn->prepare("SELECT student_id FROM ojt_assignments WHERE id=? AND company_id=?");
    $stmt_student->bind_param("ii", $ojt_id, $company_id);
    $stmt_student->execute();
    $stmt_student->bind_result($s_id);
    $stmt_student->fetch();
    $stmt_student->close();

    if (!$s_id) {
        $ajax_response['message'] = "Assignment not found or access denied.";
        header('Content-Type: application/json');
        echo json_encode($ajax_response);
        exit;
    }

    $delete = $conn->prepare("DELETE FROM ojt_assignments WHERE id=? AND company_id=?");
    $delete->bind_param("ii", $ojt_id, $company_id);
    if ($delete->execute() && $delete->affected_rows > 0) {
        if (!empty($s_id)) {
            $conn->query("UPDATE users SET deploy_status='Waiting' WHERE id=" . intval($s_id));
        }
        $ajax_response['success'] = true;
        $ajax_response['message'] = "Student has been removed from your OJT program.";
    } else {
        $ajax_response['message'] = "Failed to remove student. Please try again.";
    }
    $delete->close();

    header('Content-Type: application/json');
    echo json_encode($ajax_response);
    exit;
}

/* ================= ACCEPT APPLICATION ================= */
if (isset($_POST['accept_app'])) {
    $app_id = intval($_POST['app_id']);
    $ajax_response = ['success' => false, 'message' => 'Unknown error'];

    $stmt = $conn->prepare("
        SELECT a.student_id, u.email, u.first_name, u.last_name
        FROM ojt_applications a
        INNER JOIN users u ON u.id = a.student_id
        WHERE a.id = ? AND a.company_id = ?
    ");
    $stmt->bind_param("ii", $app_id, $company_id);
    $stmt->execute();
    $srow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($srow) {
        $student_id = intval($srow['student_id']);

        /* NEW (endorsement flow): accepting == verifying the uploaded endorsement letter.
           Blocked until the student has uploaded it (legacy applications with no letter
           on file are unaffected). */
        $endoBlockMsg = endorsementAcceptBlockMessage($conn, $student_id, $company_id);
        if ($endoBlockMsg !== null) {
            $ajax_response['message'] = $endoBlockMsg;
            header('Content-Type: application/json');
            echo json_encode($ajax_response);
            exit;
        }
        $contractBlockMsg = contractBlockMessage($conn, $student_id, $company_id); // NEW (contract gate)
        if ($contractBlockMsg !== null) {
            $ajax_response['message'] = $contractBlockMsg;
            $ajax_response['blocked'] = true;
            header('Content-Type: application/json');
            echo json_encode($ajax_response);
            exit;
        }
        $endoRowForAccept = getEndorsementRow($conn, $student_id, $company_id);

        $chk = $conn->prepare("SELECT id FROM ojt_assignments WHERE student_id=?");
        $chk->bind_param("i", $student_id);
        $chk->execute();
        $chk->store_result();

        if ($chk->num_rows > 0) {
            $ajax_response['message'] = "Student is already assigned to a company.";
        } else {
            $ins = $conn->prepare("INSERT INTO ojt_assignments (company_id, student_id) VALUES (?,?)");
            $ins->bind_param("ii", $company_id, $student_id);
            if ($ins->execute()) {
                $new_ojt_id = $conn->insert_id;
                $conn->query("UPDATE users SET deploy_status='Deployed' WHERE id=" . $student_id);
                // NEW (endorsement flow): the uploaded letter is now Verified.
                if ($endoRowForAccept) {
                    $conn->query("UPDATE endorsement_letters SET validation_status='Verified', validation_remark=NULL, validated_at=NOW() WHERE id=" . intval($endoRowForAccept['id']));
                    // ADJUSTMENT: accepted → the letter's validation can no longer be undone.
                    try { $conn->query("DELETE FROM endorsement_validation_undo WHERE endo_id=" . intval($endoRowForAccept['id'])); } catch (\Throwable $e) {}
                }
                // Delete ONLY the specific application that was accepted
                $conn->query("DELETE FROM ojt_applications WHERE id=" . $app_id . " AND company_id=" . $company_id);

                /* Build data for the newly registered student so the front-end
                   can insert the row into the "Registered OJT Students" table
                   immediately — the Add Student UI is "directly activated"
                   without requiring the user to manually reload the page. */
                $studentPhotoQ = $conn->prepare("
                    SELECT users.first_name, users.middle_name, users.last_name, users.email, users.deploy_status,
                           student_information.student_photo
                    FROM users
                    LEFT JOIN student_information ON student_information.user_id = users.id
                    WHERE users.id = ?
                ");
                $studentPhotoQ->bind_param("i", $student_id);
                $studentPhotoQ->execute();
                $newStudentRow = $studentPhotoQ->get_result()->fetch_assoc();
                $studentPhotoQ->close();

                $ajax_response['new_student'] = [
                    'ojt_id'        => $new_ojt_id,
                    'first_name'    => $newStudentRow['first_name'] ?? '',
                    'middle_name'   => $newStudentRow['middle_name'] ?? '',
                    'last_name'     => $newStudentRow['last_name'] ?? '',
                    'email'         => $newStudentRow['email'] ?? $srow['email'],
                    'deploy_status' => $newStudentRow['deploy_status'] ?? 'Deployed',
                    'photo'         => !empty($newStudentRow['student_photo']) ? base64_encode($newStudentRow['student_photo']) : null,
                ];

                // UPDATED (this adjustment): administrator.php email design, no emoji
                $acceptParas = [
                    "We are pleased to inform you that your OJT application to <strong>" . htmlspecialchars($company_name) . "</strong> has been <strong style='color:#16a34a;'>ACCEPTED</strong>.",
                ];
                if ($endoRowForAccept) {
                    $acceptParas[] = "Your endorsement letter has been <strong style='color:#16a34a;'>VERIFIED</strong>.";
                }
                $acceptParas[] = "You are now officially registered as an OJT student. Please report to the company at the earliest convenience.";
                $body = buildOjtEmail([
                    'theme'      => 'success',
                    'heading'    => 'Your Application Has Been Accepted',
                    'name'       => $srow['first_name'] . ' ' . $srow['last_name'],
                    'paragraphs' => $acceptParas,
                    'card_label' => 'Company',
                    'card_value' => $company_name,
                    'note'       => "We look forward to working with you during your OJT. Good luck!",
                    'signoff'    => "Best regards,<br><strong>" . htmlspecialchars($supervisor_name) . "</strong><br>" . htmlspecialchars($company_name),
                ]);
                sendMail($srow['email'], 'OJT Application Accepted – ' . $company_name, $body);
                $ajax_response['success'] = true;
                $ajax_response['endorsement_verified'] = (bool)$endoRowForAccept;
                $ajax_response['message'] = "Application accepted. Student has been registered and notified via email.";
            } else {
                $ajax_response['message'] = "Database error. Please try again.";
            }
        }
        $chk->close();
    } else {
        $ajax_response['message'] = "Application not found or already processed.";
    }

    header('Content-Type: application/json');
    echo json_encode($ajax_response);
    exit;
}

/* ================= REJECT APPLICATION ================= */
if (isset($_POST['reject_app'])) {
    $app_id = intval($_POST['reject_app_id']);
    $reason = trim($_POST['reject_reason'] ?? '');
    $ajax_response = ['success' => false, 'message' => 'Unknown error'];

    $stmt = $conn->prepare("
        SELECT u.email, u.first_name, u.last_name, a.student_id
        FROM ojt_applications a
        INNER JOIN users u ON u.id = a.student_id
        WHERE a.id = ? AND a.company_id = ?
    ");
    $stmt->bind_param("ii", $app_id, $company_id);
    $stmt->execute();
    $srow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($srow && ($contractBlockMsg = contractBlockMessage($conn, (int)$srow['student_id'], $company_id)) !== null) { // NEW (contract gate)
        $ajax_response['message'] = $contractBlockMsg;
        $ajax_response['blocked'] = true;
    } elseif ($srow) {
        // UPDATED (this adjustment): administrator.php email design, no emoji
        $body = buildOjtEmail([
            'theme'        => 'danger',
            'heading'      => 'Application Not Accepted',
            'name'         => $srow['first_name'] . ' ' . $srow['last_name'],
            'paragraphs'   => [
                "We regret to inform you that your OJT application to <strong>" . htmlspecialchars($company_name) . "</strong> has been <strong style='color:#dc2626;'>REJECTED</strong>.",
                "Please consider applying to other companies through the portal.",
            ],
            'reason_label' => 'Reason',
            'reason'       => $reason,   // box only appears when a reason was given (same as before)
            'card_label'   => 'Company Applied To',
            'card_value'   => $company_name,
            'note'         => "Don't be discouraged &mdash; other opportunities are available. We wish you the best in your OJT journey.",
            'signoff'      => ojtEmailSignoff('Regards', $company_name, $supervisor_name), // UPDATED: representative name + company
        ]);
        sendMail($srow['email'], 'OJT Application Update – ' . $company_name, $body);

        // Delete ONLY the specific application that was rejected
        $del = $conn->prepare("DELETE FROM ojt_applications WHERE id=? AND company_id=?");
        $del->bind_param("ii", $app_id, $company_id);
        if ($del->execute() && $del->affected_rows > 0) {
            // NEW (endorsement flow): the letter belongs to the application — remove it too.
            $endoStudentId = intval($srow['student_id']);
            $endoDel = $conn->prepare("DELETE FROM endorsement_letters WHERE company_id = ? AND student_id = ? AND validation_status <> 'Verified'");
            $endoDel->bind_param("ii", $company_id, $endoStudentId);
            $endoDel->execute();
            $endoDel->close();
            $ajax_response['success'] = true;
            $ajax_response['message'] = "Application rejected and student notified via email.";
        } else {
            $ajax_response['message'] = "Failed to reject application. Please try again.";
        }
        $del->close();
    } else {
        $ajax_response['message'] = "Application not found or already processed.";
    }

    header('Content-Type: application/json');
    echo json_encode($ajax_response);
    exit;
}

/* ================= CHECK NEW APPLICATIONS — AJAX POLLING =================
   Lets the front-end detect newly submitted student applications in real
   time (via periodic polling) so the inbox badge/drawer UI is "directly
   activated" as soon as a new application arrives, without the user
   having to manually reload the page. Returns only applications the
   client doesn't already know about (known_ids), built with the exact
   same buildAppCardData() helper used for the initial server-rendered
   inbox, so the client-built card matches the server-rendered one.

   ── FIX: also detect cancellations/withdrawals ──
   Previously this endpoint only ever told the client about NEW
   applications. If a student withdrew/cancelled an application (or it
   otherwise stopped being pending) while the inbox drawer was already
   open, the stale card stayed on screen until the page was manually
   reloaded. `current_ids` now returns every application id that is
   STILL pending right now; the client diffs this against what it has
   rendered so it can detect BOTH directions of change in the same
   poll — new arrivals AND cards that must be removed because they are
   no longer pending. */
/* ══ ADJUSTMENT: "Students Endorsed by the Admin" inbox, grouped by the admin's batch ══
   One card per batch (members listed with their own Full View) and one "Move Batch to
   Table" button. Each member keeps id="appCard{id}" and the same data-* attributes as
   before, so the Full View (digital resume) and the live polling keep working. */
function renderInboxGroupsHtml($conn, $company_id, $company_name, array $rows, $currentCount) {
    if (!$rows) return '<div class="ar-empty"><i class="fas fa-inbox"></i>No pending applications.</div>';
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
    $groups = [];
    foreach ($rows as $r) {
        $key = !empty($r['endo_batch_id']) ? $r['endo_batch_id'] : ('single-' . $r['id']);
        $groups[$key][] = $r;
    }
    $countTxt = $currentCount > 0 ? $currentCount . ' student' . ($currentCount !== 1 ? 's' : '') . ' currently registered' : 'No students registered yet';
    $html = '';
    foreach ($groups as $key => $members) {
        $isBatch = strpos($key, 'single-') !== 0;
        $course  = trim((string)($members[0]['course'] ?? ''));
        $memberHtml = '';
        foreach ($members as $app) {
            $d = buildAppCardData($conn, $company_id, $company_name, $app, $currentCount);
            $memberHtml .= '<div class="ar-member" id="appCard' . (int)$app['id'] . '"'
                . ' data-app-id="' . (int)$app['id'] . '" data-name="' . $h($d['name']) . '" data-email="' . $h($d['email']) . '"'
                . ' data-course="' . $h($d['course']) . '" data-company="' . $h($company_name) . '"'
                . " data-skills='" . $h(json_encode($d['skills'])) . "' data-exps='" . $h(json_encode($d['exps'])) . "'"
                . ' data-photo="' . ($d['photo'] ?? '') . '" data-submitted="' . $h($d['submitted']) . '"'
                . ' data-ojt-count="' . (int)$currentCount . '"'
                . " data-req-docs='" . $h(json_encode($d['reqDocs'])) . "'"
                . ' data-photo-status="' . $h($d['photoStatus']) . '">'
                . '<span class="ar-member-name">' . $h($d['name']) . '</span>'
                . '<button class="ar-fullview-btn ar-member-fv" onclick="openAppFullView(' . (int)$app['id'] . ')">View Skill &amp; Experience</button>' // ADJUSTMENT: renamed from "Full View"
                . '</div>';
        }
        $moveJs = $isBatch ? 'moveBatchToTable(' . $h(json_encode($key)) . ', this)' : 'moveAppToTable(' . (int)$members[0]['id'] . ', this)';
        /* ADJUSTMENT: same layout for one student or a batch — the course, then each student's
           name with their digital resume (Full View), then "Move to Table". The "Batch of N ·
           course" line and the company-name line (and their icons) are no longer shown. */
        $html .= '<div class="ar-card ar-batch-card"' . ($isBatch ? ' data-batch="' . $h($key) . '"' : '') . '>'
            . '<div class="ar-batch-course">' . $h($course !== '' ? $course : '—') . '</div>'
            . '<div class="ar-batch-members">' . $memberHtml . '</div>'
            // ADJUSTMENT: the registered-students count line is no longer shown on these cards.
            . '<div class="ar-actions"><button class="ar-allow-btn" onclick="' . $moveJs . '"><i class="fas fa-table-list"></i> Move to Table</button></div>'
            . '</div>';
    }
    return $html;
}

/* ── ADJUSTMENT: POST — the grouped inbox (used after live changes) ── */
if (isset($_POST['inbox_groups_html'])) {
    header('Content-Type: application/json');
    $gq = $conn->prepare("SELECT a.*, u.first_name, u.last_name, u.email, u.course, si.student_photo, el.batch_id AS endo_batch_id
                          FROM ojt_applications a INNER JOIN users u ON u.id = a.student_id
                          LEFT JOIN student_information si ON si.user_id = u.id
                          LEFT JOIN endorsement_letters el ON el.student_id = a.student_id AND el.company_id = a.company_id
                          WHERE a.company_id = ? AND a.phase = 'pending' AND a.in_table = 0 ORDER BY a.id DESC");
    $gq->bind_param("i", $company_id); $gq->execute();
    $grows = $gq->get_result()->fetch_all(MYSQLI_ASSOC); $gq->close();
    $cq = $conn->prepare("SELECT COUNT(*) AS cnt FROM ojt_assignments WHERE company_id = ?");
    $cq->bind_param("i", $company_id); $cq->execute();
    $gcount = (int)($cq->get_result()->fetch_assoc()['cnt'] ?? 0); $cq->close();
    echo json_encode(['success' => true, 'html' => renderInboxGroupsHtml($conn, $company_id, $company_name, $grows, $gcount),
                      'ids' => array_map(fn($r) => (int)$r['id'], $grows)]);
    exit;
}

/* ── ADJUSTMENT: POST — move a whole admin batch from the inbox into the table ── */
if (isset($_POST['move_batch_to_table'])) {
    header('Content-Type: application/json');
    $bid = (string)($_POST['batch_id'] ?? '');
    $mv = $conn->prepare("UPDATE ojt_applications a INNER JOIN endorsement_letters el ON el.student_id = a.student_id AND el.company_id = a.company_id
                          SET a.in_table = 1 WHERE el.batch_id = ? AND a.company_id = ? AND a.phase = 'pending' AND a.in_table = 0");
    $mv->bind_param("si", $bid, $company_id); $mv->execute();
    $n = $mv->affected_rows; $mv->close();
    echo json_encode(['success' => $n > 0, 'moved' => $n, 'message' => $n > 0 ? 'Batch moved to the applicants table.' : 'This batch is no longer in the inbox.']);
    exit;
}

/* ── ADJUSTMENT: POST — every pending application in a batch (Accept / Reject the batch) ── */
if (isset($_POST['batch_app_ids'])) {
    header('Content-Type: application/json');
    $bid = (string)($_POST['batch_id'] ?? '');
    $bq = $conn->prepare("SELECT a.id, u.first_name, u.last_name FROM ojt_applications a
                          INNER JOIN endorsement_letters el ON el.student_id = a.student_id AND el.company_id = a.company_id
                          INNER JOIN users u ON u.id = a.student_id
                          WHERE el.batch_id = ? AND a.company_id = ? AND a.phase = 'pending' ORDER BY a.id");
    $bq->bind_param("si", $bid, $company_id); $bq->execute();
    $brows = $bq->get_result()->fetch_all(MYSQLI_ASSOC); $bq->close();
    echo json_encode(['success' => true, 'apps' => array_map(fn($r) => ['id' => (int)$r['id'], 'name' => trim($r['first_name'] . ' ' . $r['last_name'])], $brows)]);
    exit;
}

/* ── ADJUSTMENT: POST — move an inbox application into the applicants table ── */
if (isset($_POST['move_app_to_table'])) {
    header('Content-Type: application/json');
    $app_id = intval($_POST['app_id'] ?? 0);
    $mv = $conn->prepare("UPDATE ojt_applications SET in_table = 1 WHERE id = ? AND company_id = ? AND phase = 'pending' AND in_table = 0");
    $mv->bind_param("ii", $app_id, $company_id);
    $mv->execute();
    $ok = $mv->affected_rows > 0;
    $mv->close();
    echo json_encode(['success' => $ok, 'message' => $ok ? 'Moved to the applicants table.' : 'This application is no longer in the inbox.']);
    exit;
}

if (isset($_POST['check_new_applications'])) {
    header('Content-Type: application/json');

    $known_ids_raw = $_POST['known_ids'] ?? '[]';
    $known_ids = json_decode($known_ids_raw, true);
    if (!is_array($known_ids)) $known_ids = [];
    $known_ids = array_map('intval', $known_ids);

    $cntQ2 = $conn->prepare("SELECT COUNT(*) AS cnt FROM ojt_assignments WHERE company_id = ?");
    $cntQ2->bind_param("i", $company_id);
    $cntQ2->execute();
    $cntRow2 = $cntQ2->get_result()->fetch_assoc();
    $cntQ2->close();
    $currentCount2 = (int)($cntRow2['cnt'] ?? 0);

    $stmt2 = $conn->prepare("
        SELECT a.*, u.first_name, u.last_name, u.email, u.course, si.student_photo, el.batch_id AS endo_batch_id
        FROM ojt_applications a
        INNER JOIN users u ON u.id = a.student_id
        LEFT JOIN student_information si ON si.user_id = u.id
    LEFT JOIN endorsement_letters el ON el.student_id = a.student_id AND el.company_id = a.company_id -- ADJUSTMENT: batch
        WHERE a.company_id = ? AND a.phase = 'pending' AND a.in_table = 0 -- ADJUSTMENT: inbox = dashboard applications not yet moved
        ORDER BY a.id DESC
    ");
    $stmt2->bind_param("i", $company_id);
    $stmt2->execute();
    $res2 = $stmt2->get_result();

    $new_apps    = [];
    $current_ids = [];
    while ($app2 = $res2->fetch_assoc()) {
        $current_ids[] = (int)$app2['id'];
        if (in_array((int)$app2['id'], $known_ids, true)) continue;
        $new_apps[] = buildAppCardData($conn, $company_id, $company_name, $app2, $currentCount2);
    }
    $stmt2->close();

    echo json_encode([
        'success'          => true,
        'new_applications' => $new_apps,
        'current_ids'      => $current_ids,
    ]);
    exit;
}

/* ================= FETCH INBOX (pending phase) ================= */
$inbox_stmt = $conn->prepare("
    SELECT a.*, u.first_name, u.last_name, u.email, u.course,
           si.student_photo, el.batch_id AS endo_batch_id
    FROM ojt_applications a
    INNER JOIN users u ON u.id = a.student_id
    LEFT JOIN student_information si ON si.user_id = u.id
    LEFT JOIN endorsement_letters el ON el.student_id = a.student_id AND el.company_id = a.company_id -- ADJUSTMENT: batch
    WHERE a.company_id = ? AND a.phase = 'pending' AND a.in_table = 0 -- ADJUSTMENT: inbox = dashboard applications not yet moved
    ORDER BY a.id DESC
");
$inbox_stmt->bind_param("i", $company_id);
$inbox_stmt->execute();
$inbox_result = $inbox_stmt->get_result();
$inbox_count  = $inbox_result->num_rows;
$inbox_rows   = [];
while ($r = $inbox_result->fetch_assoc()) $inbox_rows[] = $r;
$inbox_stmt->close();

/* ================= NEW (endorsement flow): APPLICANTS TABLE DATA ================= */
$applicant_rows = fetchApplicantRows($conn, $company_id);
$applicant_sig  = applicantTableSignature($applicant_rows);

/* ================= FETCH UNGRADED COUNT ================= */
$ungraded_count = 0;
$stmt_ungraded = $conn->prepare("
    SELECT COUNT(*) as total
    FROM reports r
    JOIN ojt_assignments oa ON oa.student_id = r.user_id AND oa.company_id = r.company_id
    WHERE r.company_id = ?
      AND r.week_start <= CURDATE()
      AND (r.remark IS NULL OR r.remark != 'Wrong Document')
      AND r.company_grade IS NULL
");
$stmt_ungraded->bind_param("i", $company_id);
$stmt_ungraded->execute();
$res_ungraded = $stmt_ungraded->get_result()->fetch_assoc();
$ungraded_count = $res_ungraded['total'] ?? 0;
$stmt_ungraded->close();

/* ================= FETCH OJT STUDENTS (UNTOUCHED) ================= */
$stmt = $conn->prepare("
    SELECT ojt_assignments.id as ojt_id, users.first_name, users.middle_name, users.last_name,
           users.email, users.deploy_status, student_information.student_photo
    FROM users
    INNER JOIN ojt_assignments ON ojt_assignments.student_id = users.id
    LEFT JOIN student_information ON student_information.user_id = users.id
    WHERE ojt_assignments.company_id = ?
");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$result = $stmt->get_result();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $pageTitle ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --neust-maroon: #07145fe5;
            --neust-gold: #FFD700;
            --bg: #fcfaf7;
            --white: #ffffff;
            --sidebar-active: #1a237e;
        }
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background: var(--bg); margin: 0; display: flex; min-height: 100vh; }

        /* ── SIDEBAR ── */
        .sidebar { width: 260px; background: var(--neust-maroon); height: 100vh; position: fixed; display: flex; flex-direction: column; transition: 0.3s; z-index: 1000; }
        .sidebar.collapsed { width: 80px; }
        .sidebar-header { padding: 20px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-header h2 { color: var(--neust-gold); margin: 0; font-size: 20px; white-space: nowrap; overflow: hidden; }
        .sidebar.collapsed h2 { opacity: 0; width: 0; }
        .sidebar-links { flex: 1; padding: 10px 0; }
        .sidebar a { padding: 15px 25px; color: #cbd5e0; text-decoration: none; font-size: 14px; display: flex; align-items: center; position: relative; }
        .sidebar a i { width: 30px; font-size: 18px; margin-right: 15px; }
        .sidebar.collapsed .link-text { display: none; }
        .sidebar a:hover { background: rgba(255,255,255,0.05); color: white; }
        .sidebar a.active { background: var(--sidebar-active); color: white; border-left: 4px solid var(--neust-gold); }
        .logout-link { margin-top: auto; padding: 20px; border-top: 1px solid rgba(255,255,255,0.1); }
        .logout-link a { border: 1px solid var(--neust-gold); color: var(--neust-gold); border-radius: 6px; justify-content: center; padding: 10px; text-decoration: none; display: flex; }

        /* ── SIDEBAR BADGES ── */
        .sidebar-badge {
            background: #dc2626; color: white; border-radius: 50%;
            width: 18px; height: 18px; font-size: 10px; font-weight: 700;
            display: inline-flex; align-items: center; justify-content: center;
            position: absolute; right: 18px; top: 50%; transform: translateY(-50%);
        }
        .sidebar-badge-ungraded {
            background: #d97706; color: white; border-radius: 50%;
            width: 18px; height: 18px; font-size: 10px; font-weight: 700;
            display: inline-flex; align-items: center; justify-content: center;
            position: absolute; right: 18px; top: 50%; transform: translateY(-50%);
        }
        .sidebar-badge-late {
            background: #d97706; color: white; border-radius: 50%;
            width: 18px; height: 18px; font-size: 10px; font-weight: 700;
            display: inline-flex; align-items: center; justify-content: center;
            position: absolute; right: 18px; top: 50%; transform: translateY(-50%);
            animation: badge-pulse-late 2s ease-in-out infinite;
        }
        @keyframes badge-pulse-late {
            0%,100% { box-shadow: 0 0 0 0 rgba(217,119,6,0.55); }
            50%      { box-shadow: 0 0 0 6px rgba(217,119,6,0); }
        }

        /* ── MAIN CONTENT ── */
        .main-content { margin-left: 260px; width: 100%; transition: 0.3s; }
        .sidebar.collapsed + .main-content { margin-left: 80px; }
        /* FIX (side menu covering the page): .main-content is a flex item, and by default a flex item cannot shrink below
           its widest content. The applicants table (now with the Schedule column) is wider than a small window, so the
           whole page grew wider than the window and scrolling sideways slid the content under the fixed side menu.
           With min-width: 0 the page keeps the window's width and the table scrolls inside its own box (.apl-table-wrap). */
        .main-content { min-width: 0; }
        .navbar {
            background: var(--neust-maroon);
            padding: 10px 30px;
            display: flex;
            align-items: center;
            color: white;
            height: 60px;
            flex-shrink: 0;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        .navbar-right { margin-left: auto; display: flex; align-items: center; gap: 16px; }
        .container { padding: 30px; max-width: 1100px; margin: 0 auto; }

        /* ── CARD & TABLE ── */
        .card { background: white; padding: 25px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); margin-bottom: 30px; }
        .card h2 { color: var(--neust-maroon); font-size: 18px; margin-top: 0; border-bottom: 2px solid var(--neust-gold); padding-bottom: 10px; }
        .search-box { display: flex; gap: 10px; margin-top: 15px; }
        input[type="text"], textarea { padding: 10px; border: 1px solid #ddd; border-radius: 6px; font-family: inherit; }
        input[type="text"] { flex: 1; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { background: #f8fafc; color: var(--neust-maroon); text-align: left; padding: 15px; font-size: 13px; border-bottom: 2px solid #edf2f7; }
        td { padding: 15px; border-bottom: 1px solid #edf2f7; font-size: 14px; vertical-align: middle; }
        tr:hover { background: #fcfcfc; }
        .student-photo { width: 45px; height: 45px; border-radius: 50%; object-fit: cover; border: 2px solid #eee; }
        .btn-reg     { background: var(--neust-maroon); color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-size: 13px; }
        .btn-remove  { background: #fff1f1; color: #d9534f; border: 1px solid #f5c6cb; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 13px; }
        .btn-remove:hover  { background: #d9534f; color: white; }
        .btn-success { background: #16a34a; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; }
        .btn-success:hover { opacity: 0.85; }
        .status-badge { background: #e7f3ef; color: #2d6a4f; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: bold; }

        /* ── TABLE ROW FADE-OUT ── */
        tbody tr { transition: opacity 0.35s ease, background 0.2s; }
        tbody tr.removing { opacity: 0; pointer-events: none; }

        /* ── NEW ROW HIGHLIGHT (student just added via Accept, no reload) ── */
        tbody tr.just-added { animation: row-flash-in 1.6s ease; }
        @keyframes row-flash-in {
            0%   { background: #dcfce7; }
            100% { background: transparent; }
        }

        /* ── EMPTY STATE ── */
        .table-empty-state { text-align: center; color: #a0aec0; padding: 30px 0; font-size: 14px; }
        .table-empty-state i { display: block; font-size: 32px; margin-bottom: 10px; color: #cbd5e0; }

        /* ── INBOX FAB ── */
        #inboxFab {
            position: relative; display: inline-flex; align-items: center; justify-content: center;
            width: 42px; height: 42px; border-radius: 50%;
            background: rgba(255,255,255,0.15); color: white;
            border: 2px solid rgba(255,255,255,0.3); cursor: pointer;
            font-size: 18px; transition: background 0.2s;
        }
        #inboxFab:hover { background: rgba(255,255,255,0.25); }
        #inboxFab.fab-pulse { animation: fab-pulse-new 1.4s ease-in-out 2; }
        @keyframes fab-pulse-new {
            0%,100% { box-shadow: 0 0 0 0 rgba(255,215,0,0.55); }
            50%      { box-shadow: 0 0 0 8px rgba(255,215,0,0); }
        }
        #inboxFabBadge {
            position: absolute; top: -5px; right: -5px;
            background: #dc2626; color: white; border-radius: 50%;
            width: 18px; height: 18px; font-size: 10px; font-weight: 700;
            display: none; align-items: center; justify-content: center;
            border: 2px solid var(--neust-maroon);
        }

        /* ── INBOX DRAWER ── */
        #inboxOverlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.45); z-index: 3000;
            justify-content: flex-end; align-items: stretch;
        }
        #inboxDrawer {
            background: white; width: 520px; max-width: 97vw;
            display: flex; flex-direction: column;
            box-shadow: -8px 0 32px rgba(0,0,0,0.18);
            animation: slideIn 0.3s ease;
        }
        @keyframes slideIn { from{transform:translateX(100%);} to{transform:translateX(0);} }
        #inboxHeader {
            padding: 18px 22px; background: var(--neust-maroon);
            display: flex; align-items: center; justify-content: space-between; flex-shrink: 0;
        }
        #inboxHeader h3 { margin: 0; color: var(--neust-gold); font-size: 15px; display: flex; align-items: center; gap: 10px; }
        #inboxClose { background: none; border: none; color: rgba(255,255,255,0.7); font-size: 22px; cursor: pointer; padding: 0; line-height: 1; }
        #inboxClose:hover { color: white; }
        #inboxBody { overflow-y: auto; flex: 1; padding: 18px 20px; }

        /* ── APPLICATION CARD (legacy — kept for compatibility) ── */
        .app-card {
            border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px;
            margin-bottom: 14px; background: #fafafa;
            transition: opacity 0.3s;
        }
        .app-card.removing { opacity: 0; pointer-events: none; }
        .app-card-top { display: flex; gap: 14px; align-items: flex-start; }
        .app-card-photo { width: 58px; height: 58px; border-radius: 50%; object-fit: cover; border: 2px solid #e2e8f0; flex-shrink: 0; }
        .app-card-photo-placeholder { width: 58px; height: 58px; border-radius: 50%; background: #e2e8f0; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
        .app-card-info { flex: 1; min-width: 0; }
        .app-card-name { font-weight: 700; font-size: 0.95rem; color: #1e293b; margin-bottom: 2px; }
        .app-card-meta { font-size: 0.79rem; color: #64748b; margin-bottom: 3px; }
        .app-card-skills { margin-top: 8px; font-size: 0.79rem; color: #475569; background: #f1f5f9; padding: 8px 11px; border-radius: 8px; }
        .app-card-actions { display: flex; gap: 8px; margin-top: 12px; flex-wrap: wrap; }
        .app-empty { text-align: center; color: #a0aec0; padding: 40px 0; font-size: 0.9rem; }

        /* ══════════════════════════════════════════════
           AR-CARD — SUMMARIZED APPLICATION REQUEST CARD
           ------------------------------------------------------------
           Ported from administrator.php's application inbox card:
           Student Name → Course → Company Name → count of students
           currently registered at the company, stacked vertically,
           followed by a single horizontal row of Accept / Reject /
           Full View buttons. Full applicant detail (photo, skills,
           experience, submitted documents) now lives entirely in the
           Full View document modal below, opened via openAppFullView().
           ══════════════════════════════════════════════ */
        .ar-card {
            border: 1px solid #e2e8f0; border-radius: 12px;
            padding: 15px; margin-bottom: 13px; background: #fafafa;
            transition: opacity 0.3s;
        }
        .ar-card.removing { opacity: 0; pointer-events: none; }
        .ar-card.just-arrived { animation: ar-card-flash-in 1.8s ease; }
        @keyframes ar-card-flash-in {
            0%   { background: #fef9c3; border-color: #fde68a; }
            100% { background: #fafafa; border-color: #e2e8f0; }
        }
        .ar-card-top { display: flex; gap: 12px; align-items: center; }
        .ar-photo { width: 52px; height: 52px; border-radius: 50%; object-fit: cover; border: 2px solid #e2e8f0; flex-shrink: 0; }
        .ar-photo-placeholder { width: 52px; height: 52px; border-radius: 50%; background: #dbeafe; display: flex; align-items: center; justify-content: center; font-size: 15px; font-weight: 700; color: #1d4ed8; flex-shrink: 0; }
        .ar-info { flex: 1; min-width: 0; }
        .ar-name { font-weight: 700; font-size: 14px; color: #1e293b; margin-bottom: 2px; }
        .ar-meta { font-size: 12px; color: #64748b; margin-bottom: 2px; }
        .ar-company { display: inline-flex; align-items: center; gap: 5px; margin-top: 5px; font-size: 11px; font-weight: 700; background: #f3e8ff; color: #7c3aed; padding: 3px 9px; border-radius: 12px; }
        .ar-skills { margin-top: 9px; background: #f1f5f9; border-radius: 7px; padding: 8px 11px; font-size: 12px; color: #475569; }
        .ar-actions { display: flex; gap: 8px; margin-top: 11px; flex-wrap: wrap; }
        .ar-allow-btn { background: #16a34a; color: white; border: none; padding: 7px 18px; border-radius: 7px; font-size: 12px; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 5px; transition: opacity 0.2s; }
        .ar-allow-btn:hover { opacity: 0.85; }
        .ar-allow-btn:disabled { opacity: 0.4; cursor: not-allowed; }
        .ar-deny-btn { background: #fff1f1; color: #dc2626; border: 1px solid #fecaca; padding: 7px 18px; border-radius: 7px; font-size: 12px; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 5px; transition: all 0.2s; }
        .ar-deny-btn:hover { background: #dc2626; color: white; }
        .ar-deny-btn:disabled { opacity: 0.4; cursor: not-allowed; }
        .ar-empty { text-align: center; color: #a0aec0; padding: 50px 20px; font-size: 13px; }
        .ar-empty i { font-size: 40px; display: block; margin-bottom: 14px; color: #cbd5e0; }

        /* New summary-stack classes (Name / Course / Company / Count) */
        .ar-summary-name {
            font-family: 'Segoe UI', sans-serif;
            font-weight: 700; font-size: 14px; color: #1e293b;
            margin-bottom: 3px;
        }
        .ar-summary-course {
            font-family: 'Segoe UI', sans-serif;
            font-size: 12px; color: #64748b;
            margin-bottom: 7px;
        }
        .ar-summary-company {
            display: flex; align-items: center; gap: 6px;
            font-family: 'Segoe UI', sans-serif;
            font-size: 12px; font-weight: 700; color: #7c3aed;
            margin-bottom: 4px;
        }
        .ar-summary-count {
            display: flex; align-items: center; gap: 6px;
            font-family: 'Segoe UI', sans-serif;
            font-size: 11.5px; color: #64748b;
            margin-bottom: 2px;
        }
        /* Full View button — third button in the same horizontal row as Accept/Reject */
        .ar-fullview-btn {
            background: #f8f7ff; color: var(--neust-maroon);
            border: 1px solid #c7d2fe;
            padding: 7px 14px; border-radius: 7px;
            font-size: 12px; font-weight: 700; cursor: pointer;
            display: flex; align-items: center; gap: 5px;
            transition: all 0.2s; white-space: nowrap;
        }
        .ar-fullview-btn:hover { background: #ece9ff; }
        .ar-fullview-btn:disabled { opacity: 0.4; cursor: not-allowed; }

        /* ── LIVE APPLICATION DETECTION — transient add/remove banner ──
           Mirrors administrator.php's .ar-live-notice: a small banner
           inserted at the top of the drawer whenever a poll detects a
           change (new submission or withdrawal/cancellation), so the
           update is obvious even if the company isn't looking directly
           at the list when it happens. Auto-dismisses after a few
           seconds. */
        .ar-live-notice {
            display: flex; align-items: center; gap: 8px;
            font-family: 'Segoe UI', sans-serif; font-size: 12px; font-weight: 700;
            padding: 9px 13px; border-radius: 9px; margin-bottom: 10px;
            transition: opacity 0.3s;
        }
        .ar-live-notice-added   { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
        .ar-live-notice-removed { background: #fff7ed; color: #9a3412; border: 1px solid #fed7aa; }

        /* ── REJECT MODAL ── */
        #rejectModal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 4000; justify-content: center; align-items: center; }
        #rejectModalBox { background: white; padding: 28px; border-radius: 14px; width: 440px; max-width: 92%; box-shadow: 0 20px 60px rgba(0,0,0,0.2); animation: popIn 0.3s cubic-bezier(0.34,1.56,0.64,1); }
        @keyframes popIn { from{transform:scale(0.88);opacity:0;} to{transform:scale(1);opacity:1;} }
        #rejectModalBox h3 { margin: 0 0 6px; color: #dc2626; font-size: 16px; }
        #rejectModalBox p  { margin: 0 0 14px; font-size: 13px; color: #64748b; }
        #rejectModalBox textarea { width: 100%; height: 90px; padding: 10px; border: 1px solid #ddd; border-radius: 8px; box-sizing: border-box; resize: vertical; font-family: inherit; font-size: 13px; }
        #rejectModalBox textarea:focus { outline: none; border-color: #dc2626; }
        .reject-char-hint { font-size: 11px; color: #a0aec0; margin-top: 4px; margin-bottom: 14px; }
        .reject-actions { display: flex; justify-content: flex-end; gap: 10px; }
        .reject-cancel { padding: 9px 20px; background: #f1f5f9; color: #475569; border: none; border-radius: 8px; font-weight: 700; cursor: pointer; font-size: 13px; }
        .reject-cancel:hover { background: #e2e8f0; }
        .reject-submit { padding: 9px 20px; background: #dc2626; color: white; border: none; border-radius: 8px; font-weight: 700; cursor: pointer; font-size: 13px; display: flex; align-items: center; gap: 6px; }
        .reject-submit:hover { opacity: 0.88; }
        .reject-submit:disabled { opacity: 0.4; cursor: not-allowed; }

        /* ── TOAST NOTIFICATION ── */
        #actionToast { position: fixed; bottom: 30px; left: 50%; transform: translateX(-50%) translateY(80px); background: #1e293b; color: white; padding: 14px 22px; border-radius: 12px; box-shadow: 0 8px 30px rgba(0,0,0,0.25); font-size: 14px; font-weight: 600; display: flex; align-items: center; gap: 10px; z-index: 9999; min-width: 280px; transition: transform 0.35s cubic-bezier(0.34,1.56,0.64,1), opacity 0.3s; opacity: 0; }
        #actionToast.show { transform: translateX(-50%) translateY(0); opacity: 1; }
        #actionToast.success { border-left: 4px solid #16a34a; }
        #actionToast.error   { border-left: 4px solid #dc2626; }
        #actionToast i { font-size: 16px; }

        /* ══════════════════════════════════════════════
           FULL VIEW APPLICATION MODAL — LETTERHEAD/DOCUMENT STYLE
           ------------------------------------------------------------
           Ported from administrator.php: a full-bleed, sticky-toolbar
           document viewer (dark backdrop, navy toolbar pinned to the
           top of the scrollable viewport, padded gray canvas centering
           a white letterhead "paper"), with a dynamic A4 pagination
           engine so an applicant's Skills/Experience content spans
           however many real pages it needs, and Submitted Documents
           always rendered as its own dedicated, vertically-centered
           final page.
           ══════════════════════════════════════════════ */
        :root {
            --navy: #07145f;
            --gold: #c8a800;
            --rule: #c8cfe8;
        }
        #appFullViewOverlay,
        #appFullViewOverlay *,
        #appFullViewOverlay *::before,
        #appFullViewOverlay *::after {
            box-sizing: border-box;
        }
        #appFullViewOverlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.72); z-index: 10010;
            overflow-y: auto; padding: 0;
        }
        #appFullViewOverlay.open { display: block; }
        .fv-doc-toolbar {
            background: var(--navy); padding: 0.55rem 1.5rem;
            display: flex; align-items: center; justify-content: space-between;
            gap: 12px; flex-wrap: wrap;
            position: sticky; top: 0; z-index: 200;
            box-shadow: 0 2px 10px rgba(0,0,0,0.35);
        }
        .fv-doc-toolbar-left { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .fv-doc-toolbar-title { font-family: 'Segoe UI', sans-serif; font-size: 0.85rem; font-weight: 700; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .fv-doc-toolbar-right { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
        .fv-tbtn-close { background: rgba(255,255,255,0.14); color: rgba(255,255,255,0.9); border: 1px solid rgba(255,255,255,0.28); padding: 7px 16px; border-radius: 6px; font-size: 12.5px; font-weight: 600; cursor: pointer; transition: background 0.15s; }
        .fv-tbtn-close:hover { background: rgba(255,255,255,0.26); color: #fff; }

        .fv-doc-canvas { background: #d8dde8; padding: 24px 16px 40px; min-height: calc(100vh - 54px); display: flex; flex-direction: column; align-items: center; }
        #appFullViewBox {
            background: transparent; width: 794px; max-width: 100%;
            margin: 0 auto; display: flex; flex-direction: column;
        }
        .fv-doc-paper {
            background: #fff; border: 1px solid #b0b8cc; box-shadow: 0 4px 32px rgba(0,0,0,.22);
            width: 794px; max-width: 100%;
            font-family: "Times New Roman","Crimson Pro",Times,serif; color: #1a1a1a;
            display: flex; flex-direction: column; box-sizing: border-box;
            height: 1123px;
            overflow: hidden;
        }
        .fv-letterhead { background: var(--navy); padding: 12px 24px; display: flex; align-items: center; gap: 14px; border-bottom: 3px solid var(--gold); flex-shrink: 0; }
        .fv-lh-seal { width: 54px; height: 54px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; overflow: hidden; border: 2px solid rgba(255,255,255,.25); }
        .fv-lh-seal img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; display: block; }
        .fv-lh-text { color: #fff; flex: 1; min-width: 0; }
        .fv-lh-line1 { font-size: 8.5px; letter-spacing: .18em; text-transform: uppercase; color: #aac4f0; margin-bottom: 2px; font-family: 'Courier New', monospace; }
        .fv-lh-line2 { font-size: 15px; font-weight: 700; line-height: 1.25; text-transform: uppercase; letter-spacing: .01em; }
        .fv-lh-line3 { font-size: 9.5px; color: #dbe6fb; margin-top: 2px; }
        .fv-lh-line4 { font-size: 9px; color: #aac4f0; margin-top: 1px; }
        .fv-title-band { background: #f4f5fb; border-bottom: 1.5px solid var(--rule); padding: 8px 24px 7px; text-align: center; flex-shrink: 0; }
        .fv-title-band h1 { font-family: 'Segoe UI', sans-serif; font-size: 16px; font-weight: 700; color: var(--navy); letter-spacing: .035em; text-transform: uppercase; }
        .fv-form-meta { margin-top: 3px; font-family: 'Courier New', monospace; font-size: 7.5px; color: #999; }
        .fv-form-body { padding: 18px 28px 20px; flex: 1; min-height: 0; overflow: hidden; }

        /* Pagination engine support (hidden measurement scaffold + wrap) */
        .fv-pages-wrap {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 22px;
            width: 794px;
            max-width: 100%;
            margin: 0 auto;
        }
        .fv-header-clone,
        .fv-footer-clone,
        .fv-raw-source {
            display: none !important;
            position: fixed !important;
            top: 0 !important; left: -9999px !important;
            width: 0 !important; height: 0 !important;
            overflow: hidden !important;
            pointer-events: none !important;
        }
        .fv-doc-page-body {
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .fv-doc-page-fallback {
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
        }
        .fv-doc-page-inner { width: 100%; }
        .fv-section-title { font-size: 13px; font-weight: 700; color: #1a1a1a; margin: 16px 0 8px; border-bottom: 1px solid #e5e7eb; padding-bottom: 4px; }
        .fv-section-title:first-child { margin-top: 0; }
        .fv-footer-band { background: #f0f2f8; border-top: 1.5px solid var(--navy); padding: 5px 24px; display: flex; justify-content: space-between; font-family: 'Courier New', monospace; font-size: 7.5px; color: #888; letter-spacing: .07em; flex-shrink: 0; }

        /* Applicant header strip inside the paper (photo + name + meta) */
        .fv-applicant-strip { display: flex; align-items: center; gap: 16px; margin-bottom: 4px; }
        .fv-avatar2 { width: 66px; height: 66px; border-radius: 50%; background: #dbeafe; display: flex; align-items: center; justify-content: center; font-size: 19px; font-weight: 700; color: #1d4ed8; overflow: hidden; flex-shrink: 0; border: 2.5px solid var(--gold); cursor: pointer; }
        .fv-avatar2 img { width: 100%; height: 100%; object-fit: cover; }
        .fv-applicant-badges { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 7px; }
        .fv-badge-pill { display: inline-flex; align-items: center; gap: 5px; font-family: 'Segoe UI', sans-serif; font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 20px; }
        .fv-badge-company { background: #f3e8ff; color: #7c3aed; }
        .fv-badge-date { background: #eef1fb; color: #374151; }
        .fv-badge-ojtcount { background: #ede9fe; color: #5b21b6; }
        .fv-badge-photo { border-radius: 10px; font-size: 10px; }

        /* Resume-mirror applicant info (stacked Full Name / Email / Course) */
        .fv-resume-mirror-info { margin-bottom: 2px; }
        .fv-resume-mirror-info p {
            font-family: 'Segoe UI', sans-serif;
            font-size: 12.5px; color: #4b5563;
            margin: 0 0 3px; line-height: 1.5;
        }
        .fv-resume-mirror-info p b {
            color: #1a1a2e; font-weight: 700; margin-right: 4px;
        }

        .fv-empty-note { font-family: 'Segoe UI', sans-serif; font-size: 12px; color: #a0aec0; font-style: italic; }

        /* Skill / Experience entry boxes — numbered "Skill N" / "Experience N" */
        .fv-entries-col { display: flex; flex-direction: column; gap: 10px; }
        .fv-entry-item { margin-bottom: 0; }
        .fv-field-label { font-family: 'Segoe UI', sans-serif; font-size: 10.5px; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 4px; }
        .fv-entry-box {
            font-family: 'Segoe UI', sans-serif;
            font-size: 12.5px;
            color: #374151;
            line-height: 1.6;
            background: #f4f5fb;
            border: 1.5px solid var(--rule);
            border-radius: 10px;
            padding: 10px 14px;
            white-space: pre-wrap;
            word-break: break-word;
        }

        /* Documents table */
        .fv-doc-table { width: 100%; border-collapse: collapse; border: 1px solid #000; font-size: 12px; }
        .fv-doc-table td { border: 1px solid #000; padding: 6px 10px; vertical-align: middle; font-family: 'Segoe UI', sans-serif; }
        .fv-doc-table .fv-doc-th td { font-weight: 700; background: #f4f5fb; text-align: center; }
        .fv-doc-thumb { width: 34px; height: 34px; border-radius: 6px; overflow: hidden; flex-shrink: 0; cursor: pointer; border: 1px solid #ddd; display: inline-flex; }
        .fv-doc-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .fv-doc-nothumb { width: 34px; height: 34px; border-radius: 6px; background: #f3f4f6; display: inline-flex; align-items: center; justify-content: center; }
        .fv-doc-row-name { display: flex; align-items: center; gap: 10px; }
        .fv-status-chip { font-size: 10px; font-weight: 700; padding: 3px 9px; border-radius: 8px; white-space: nowrap; display: inline-block; }
        .fv-status-chip.verified { background: #dcfce7; color: #166534; }
        .fv-status-chip.denied   { background: #fee2e2; color: #991b1b; }
        .fv-status-chip.pending  { background: #fef9c3; color: #854d0e; }

        /* Accept / Reject signature-style action bar */
        .fv-submit-bar {
            background: #f4f5fb; border-top: 1.5px solid var(--navy);
            padding: 14px 24px; display: flex; align-items: center;
            justify-content: space-between; gap: 10px; flex-shrink: 0;
            width: 794px; max-width: 100%; margin: 0 auto;
            border-radius: 0 0 12px 12px; box-shadow: 0 4px 20px rgba(0,0,0,.12);
        }
        .fv-submit-bar-note { font-family: 'Courier New', monospace; font-size: 7.5px; color: #9ca3af; }
        .fv-submit-bar-actions { display: flex; gap: 10px; flex-wrap: wrap; justify-content: flex-end; }
        .fv-allow-btn2 {
            background: #16a34a; color: white; border: none; padding: 10px 22px;
            border-radius: 8px; font-family: 'Segoe UI', sans-serif; font-size: 13px;
            font-weight: 700; cursor: pointer; display: flex; align-items: center;
            gap: 7px; transition: opacity 0.2s;
        }
        .fv-allow-btn2:hover:not(:disabled) { opacity: 0.88; }
        .fv-allow-btn2:disabled { opacity: 0.5; cursor: not-allowed; }
        .fv-deny-btn2 {
            background: #fff1f1; color: #dc2626; border: 1px solid #fecaca; padding: 10px 22px;
            border-radius: 8px; font-family: 'Segoe UI', sans-serif; font-size: 13px;
            font-weight: 700; cursor: pointer; display: flex; align-items: center;
            gap: 7px; transition: all 0.2s;
        }
        .fv-deny-btn2:hover:not(:disabled) { background: #dc2626; color: #fff; }
        .fv-deny-btn2:disabled { opacity: 0.5; cursor: not-allowed; }

        @media (max-width: 840px) {
            #appFullViewBox, .fv-doc-paper, .fv-submit-bar, .fv-pages-wrap { width: 100%; }
        }

        /* ══════════════════════════════════════════════
           NEW (endorsement flow): OJT APPLICANTS — ENDORSEMENT LETTER VALIDATION TABLE
           ══════════════════════════════════════════════ */
        .card h2 .apl-count { display: inline-flex; align-items: center; justify-content: center; min-width: 22px; height: 22px; padding: 0 7px; border-radius: 11px; background: var(--neust-gold); color: #07145f; font-size: 11px; font-weight: 800; margin-left: 8px; vertical-align: middle; }
        .apl-intro { font-size: 12.5px; color: #64748b; margin: -2px 0 6px; }
        .apl-table-wrap { overflow-x: auto; }
        #applicantTable td { vertical-align: top; }
        .apl-student { display: flex; align-items: center; gap: 12px; min-width: 200px; }
        .apl-photo { width: 42px; height: 42px; border-radius: 50%; object-fit: cover; border: 2px solid #eee; flex-shrink: 0; cursor: pointer; }
        .apl-photo-ph { background: #dbeafe; color: #1d4ed8; font-size: 13px; font-weight: 700; display: flex; align-items: center; justify-content: center; cursor: default; }
        .apl-name { font-weight: 700; color: #1e293b; }
        .apl-sub { font-size: 11.5px; color: #64748b; margin-top: 1px; }
        .apl-course { font-size: 13px; color: #334155; min-width: 120px; }
        .apl-resume-meta { font-size: 11.5px; color: #64748b; margin-bottom: 6px; white-space: nowrap; }
        .btn-resume-preview { background: #f8f7ff; color: var(--neust-maroon); border: 1px solid #c7d2fe; padding: 6px 12px; border-radius: 7px; font-size: 12px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; transition: background 0.2s; }
        .btn-resume-preview:hover { background: #ece9ff; }
        /* ADJUSTMENT: the Skills & Experience column keeps its width whether the side menu is open or
           closed — its header and the "View Skill & Experience" button stay on one line. When space is
           short the table scrolls sideways inside .apl-table-wrap instead of squeezing the column. */
        #applicantTable th:nth-child(4), #applicantTable td:nth-child(4) { min-width: 200px; white-space: nowrap; }
        #applicantTable .btn-resume-preview { white-space: nowrap; }
        /* FIX (sideways scroll): the Accept / Reject tooltips were centred on buttons at the table's
           right edge, so even while invisible they stuck out past the table and stretched it.
           They now open leftwards from the button's right edge (the arrow still points at it). */
        #applicantTable td:nth-child(6) .apl-tip::after { left: auto; right: 0; transform: translateY(4px); }
        #applicantTable td:nth-child(6) .apl-tip:hover::after,
        #applicantTable td:nth-child(6) .apl-tip:focus-within::after { transform: translateY(0); }
        .endo-chip { display: inline-flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 20px; white-space: nowrap; }
        .endo-chip.awaiting { background: #fef9c3; color: #854d0e; }
        .endo-chip.pending  { background: #dbeafe; color: #1e40af; }
        .endo-chip.verified { background: #dcfce7; color: #166534; }
        .endo-chip.rejected { background: #fee2e2; color: #991b1b; }
        .endo-chip.none     { background: #f1f5f9; color: #475569; }
        .endo-meta { font-size: 11px; color: #94a3b8; margin-top: 5px; }
        .endo-actions.is-first { margin-top: 0; }
        .endo-no-file { color: #94a3b8; font-size: 15px; } /* ADJUSTMENT: placeholder until a file is uploaded */

        /* ══ ADJUSTMENT: loading page — same look as administrator.php's #globalLoadingOverlay.
           Same look as company_list.php: visible by default (covers the first paint), then hidden once the page has loaded;
           shown again with an action label while something is being saved. ══ */
        #globalLoadingOverlay { position: fixed; inset: 0; z-index: 20000; display: flex; align-items: center; justify-content: center; background: rgba(238, 241, 246, 0.92); opacity: 1; visibility: visible; transition: opacity 0.35s ease, visibility 0.35s ease; }
        #globalLoadingOverlay.hidden { opacity: 0; visibility: hidden; pointer-events: none; }
        #globalLoadingOverlay.gl-instant { transition: none; }
        .global-loading-box { display: flex; flex-direction: column; align-items: center; gap: 16px; animation: globalLoadingPop 0.35s ease; }
        /* the 12-segment ticking ring used by company_list.php / AccomForm.php (same size, colour, mask and timing) */
        .global-loading-spinner {
            width: 64px; height: 64px; border: 0; border-radius: 50%; box-sizing: border-box;
            background: conic-gradient(from 0deg, rgba(27,42,74,0.12) 0deg, rgba(27,42,74,0.35) 120deg, rgba(27,42,74,0.7) 240deg, #1B2A4A 330deg, #1B2A4A 360deg);
            -webkit-mask: radial-gradient(farthest-side, transparent calc(100% - 9px), #000 calc(100% - 8px)),
                          repeating-conic-gradient(from 5deg, #000 0deg 20deg, transparent 20deg 30deg);
            -webkit-mask-composite: source-in;
                    mask: radial-gradient(farthest-side, transparent calc(100% - 9px), #000 calc(100% - 8px)),
                          repeating-conic-gradient(from 5deg, #000 0deg 20deg, transparent 20deg 30deg);
                    mask-composite: intersect;
            will-change: transform;
            animation: cvRingSpin 1s steps(12, end) infinite;
        }
        @keyframes cvRingSpin { to { transform: rotate(360deg); } }
        .global-loading-text { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size: 13px; font-weight: 700; color: #1B2A4A; text-transform: uppercase; letter-spacing: 0.6px; display: flex; align-items: center; gap: 8px; }
        .global-loading-dots span { animation: globalLoadingDots 1.2s infinite; opacity: 0; }
        .global-loading-dots span:nth-child(2) { animation-delay: 0.2s; }
        .global-loading-dots span:nth-child(3) { animation-delay: 0.4s; }
        @keyframes globalLoadingSpin { to { transform: rotate(360deg); } }
        @keyframes globalLoadingPop { from { transform: scale(0.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        @keyframes globalLoadingDots { 0%, 20% { opacity: 0; } 50% { opacity: 1; } 100% { opacity: 0; } }
        .endo-note.is-first { margin-top: 0; } /* ADJUSTMENT: waiting note sits at the top of the cell */
        .endo-remark.is-first { margin-top: 0; } /* ADJUSTMENT: rejection remark sits at the top of the cell */

        /* ══ ADJUSTMENT: pagination — administrator.php .pagination-controls (square navy look) ══ */
        .pagination-controls { display: flex; justify-content: center; align-items: center; gap: 8px; margin-top: 14px; margin-bottom: 4px; flex-wrap: wrap; min-height: 40px; }
        .pg-btn { padding: 7px 16px; border: 1px solid #A3AFC7; background: #ffffff; border-radius: 0; font-size: 13px; font-weight: 600; cursor: pointer; color: #3E4963; transition: all 0.15s; user-select: none; }
        .pg-btn:hover:not(:disabled) { background: #E4EAF4; border-color: #A3AFC7; }
        .pg-btn:disabled { opacity: 0.35; cursor: not-allowed; }
        .pg-number-btn { padding: 6px 12px; border-radius: 0; font-size: 13px; cursor: pointer; border: 1px solid #A3AFC7; background: #ffffff; color: #3E4963; font-weight: 500; transition: all 0.15s; user-select: none; }
        .pg-number-btn:hover { background: #E4EAF4; border-color: #A3AFC7; }
        .pg-number-btn.active { background: #1B2A4A; color: #C3CADA; border-color: #1B2A4A; font-weight: 700; cursor: default; }
        .pg-ellipsis { font-size: 13px; color: #a0aec0; padding: 0 4px; user-select: none; }
        .pg-info { margin-left: 6px; font-size: 12px; color: #718096; white-space: nowrap; }

        /* ══ ADJUSTMENT: validation dropdown + Save — the administrator's requirement-validation look ══ */
        .endo-val-form { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-top: 9px; }
        .endo-val-form select, .endo-val-form button { padding: 6px 10px; border-radius: 0; border: 1px solid #A3AFC7; font-size: 12px; font-family: inherit; }
        .endo-val-form select { background: #ffffff; color: #1B2A4A; cursor: pointer; }
        .endo-val-form select.endo-reason { max-width: 100%; }
        .endo-val-form select.needs-reason { border-color: #dc2626; background: #fff7f7; }
        .endo-val-form button { background: #1B2A4A; color: #ffffff; border: none; cursor: pointer; text-transform: uppercase; letter-spacing: 0.4px; font-weight: 700; }
        .endo-val-form button.saving { opacity: 0.6; cursor: not-allowed; }
        .endo-val-form button.saved  { background: #2C5A2C; }
        .verified-lock { display: flex; align-items: center; gap: 8px; background: #E4EAF4; border: 1px solid #A3AFC7; color: #1B2A4A; padding: 7px 12px; border-radius: 0; font-size: 12px; font-weight: 600; margin-top: 9px; }
        .verified-lock i { font-size: 13px; color: #2C5A2C; }
        /* ADJUSTMENT: Application column (Accept / Reject) */
        .apl-app-actions { display: flex; flex-wrap: wrap; gap: 6px; }

        /* ══ ADJUSTMENT (alignment): status dropdown + Save always share ONE line (the Reason
           dropdown gets its own line below), and View Uploaded / the verified lock use the same
           width — so the block stays aligned however narrow the column gets (e.g. sidebar open). ══ */
        .endo-val-form { display: grid; grid-template-columns: minmax(0, 1fr) auto; width: 100%; max-width: 240px; }
        .endo-val-form select[name="status"] { grid-column: 1; grid-row: 1; min-width: 0; }
        .endo-val-form button[type="submit"] { grid-column: 2; grid-row: 1; }
        .endo-val-form select.endo-reason { grid-column: 1 / -1; grid-row: 2; width: 100%; }
        .endo-val-form select, .endo-val-form button { height: 32px; box-sizing: border-box; }
        .endo-val-top .endo-btn.view { width: 100%; max-width: 240px; justify-content: center; box-sizing: border-box; }
        .endo-val-top + .verified-lock { max-width: 240px; box-sizing: border-box; }
        #applicantTable td:nth-child(5) { min-width: 170px; }
        /* ADJUSTMENT (alignment): Accept / Reject stay side by side however narrow the table gets
           (e.g. side menu expanded) — they no longer wrap onto two lines. */
        #applicantTable .apl-app-actions { flex-wrap: nowrap; }
        #applicantTable td:nth-child(6) { min-width: 86px; }
        /* NEW (schedule change): Schedule column + the "Set Up New Schedule" popup (same look as #rejectModal) */
        #applicantTable td.apl-sched { font-size: 12.5px; color: #334155; }
        .apl-sched-row { display: flex; align-items: center; justify-content: space-between; gap: 14px; }
        .apl-sched-lines { min-width: 0; }
        .apl-sched-line { display: flex; gap: 6px; line-height: 1.5; white-space: nowrap; }
        .apl-sched-k { font-weight: 700; color: #64748b; min-width: 52px; }
        .apl-sched-v { color: #1e293b; }
        .apl-sched-note { font-size: 11.5px; color: #94a3b8; font-style: italic; }
        .apl-sched-edit { flex-shrink: 0; margin: 0; display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 7px; border: 1px solid #c7d2fe; background: #f8f7ff; color: var(--neust-maroon); font-size: 11.5px; font-weight: 700; cursor: pointer; }
        .apl-sched-edit:hover { background: #ece9ff; }
        /* Column widths: every column keeps its own minimum width and the table has a minimum width, so a narrow
           window (or an open side menu) makes the table scroll inside .apl-table-wrap instead of squeezing the
           columns into each other. Later in the sheet than the older nth-child rules, so these win. */
        #applicantTable { min-width: 1000px; }
        #applicantTable th, #applicantTable td { padding-left: 10px; padding-right: 10px; }
        #applicantTable th:nth-child(1), #applicantTable td:nth-child(1) { min-width: 190px; }
        #applicantTable .apl-student { min-width: 0; }
        #applicantTable th:nth-child(2), #applicantTable td:nth-child(2) { min-width: 110px; }
        #applicantTable .apl-course { min-width: 0; }
        #applicantTable th:nth-child(3), #applicantTable td:nth-child(3) { min-width: 215px; }
        #applicantTable th:nth-child(4), #applicantTable td:nth-child(4) { min-width: 150px; white-space: nowrap; }
        #applicantTable th:nth-child(5), #applicantTable td:nth-child(5) { min-width: 130px; }
        #applicantTable th:nth-child(6), #applicantTable td:nth-child(6) { min-width: 80px; }
        /* "Set Up New Schedule" popup + its confirmation — the same design as company_list.php's popups:
           square bordered box, header with title + close button, sticky action bar (like #placementMismatchModal),
           and the centred icon dialog (like #cancelConfirmModal). */
        #schedModal, #schedConfirmModal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.55); backdrop-filter: blur(4px); justify-content: center; align-items: center; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        #schedModal { z-index: 4000; padding: 20px 0; box-sizing: border-box; }
        #schedConfirmModal { z-index: 4500; }
        #schedModal .sm-box { background: #fff; border: 1px solid #C3CADA; border-radius: 0; width: 620px; max-width: 94%; max-height: calc(100vh - 40px); max-height: calc(100dvh - 40px); display: flex; flex-direction: column; padding: 16px 24px 0 24px; box-sizing: border-box; text-align: left; animation: popIn 0.3s ease; }
        #schedModal .sm-header { display: flex; justify-content: space-between; align-items: center; flex-shrink: 0; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid #C3CADA; }
        #schedModal .sm-header h3 { margin: 0; color: #1B2A4A; font-size: 15px; font-family: inherit; text-transform: uppercase; letter-spacing: 0.4px; }
        #schedModal .sm-close { background: none; border: none; font-size: 24px; line-height: 1; padding: 0 4px; cursor: pointer; color: #5A6272; transition: color 0.2s; }
        #schedModal .sm-close:hover { color: #1B2A4A; }
        #schedModal .sm-body { overflow-y: auto; flex: 1 1 auto; min-height: 0; }
        #schedModal .sm-body p { font-size: 13px; color: #475569; line-height: 1.5; margin: 0 0 10px 0; }
        #schedModal .sm-body p strong { color: #1e293b; }
        #schedModal .sm-note { font-size: 11px !important; color: #5A6272 !important; margin: 4px 0 8px !important; }
        .sched-student { font-weight: 700; color: #1B2A4A; background: #F0F2F8; border: 1px solid #C3CADA; border-radius: 0; padding: 7px 10px; margin-bottom: 12px; font-size: 13px; }
        .sched-group { margin-bottom: 12px; }
        .sched-label { font-size: 11px; font-weight: 700; color: #1B2A4A; text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 6px; }
        .sched-current { font-weight: 500; color: #5A6272; text-transform: none; letter-spacing: 0; margin-left: 6px; }
        .sched-chips { display: flex; flex-wrap: wrap; gap: 6px; }
        .sched-chip { position: relative; }
        .sched-chip input { position: absolute; opacity: 0; pointer-events: none; }
        .sched-chip span { display: inline-block; padding: 6px 14px; border: 1px solid #C3CADA; border-radius: 0; font-size: 12px; font-weight: 600; color: #2d3748; background: #fff; cursor: pointer; user-select: none; transition: background .15s, color .15s, border-color .15s; }
        .sched-chip span:hover { background: #f3f4f7; }
        .sched-chip input:checked + span { background: #1B2A4A; border-color: #1B2A4A; color: #fff; }
        .sched-chip input:focus-visible + span { outline: 2px solid #1B2A4A; outline-offset: 2px; }
        #schedReason { width: 100%; height: 76px; padding: 8px 10px; border: 1px solid #C3CADA; border-radius: 0; box-sizing: border-box; resize: vertical; font-family: inherit; font-size: 13px; color: #2d3748; }
        #schedReason:focus { outline: none; border-color: #1B2A4A; box-shadow: 0 0 0 3px rgba(27,42,74,0.08); }
        #schedMsg { min-height: 16px; font-size: 11.5px; color: #A02A2A; margin: 4px 0 6px; }
        #schedModal .sm-actions { display: flex; gap: 12px; justify-content: flex-end; flex-shrink: 0; background: #fff; margin-top: 4px; padding: 10px 0 12px 0; border-top: 1px solid #C3CADA; }
        #schedModal .sm-actions button { padding: 10px 24px; border-radius: 0; font-weight: 600; cursor: pointer; font-size: 12px; text-transform: uppercase; letter-spacing: 0.3px; font-family: inherit; transition: opacity 0.2s; display: inline-flex; align-items: center; gap: 6px; }
        #schedModal .sm-btn-no { background: #fff; color: #1B2A4A; border: 1px solid #C3CADA; }
        #schedModal .sm-btn-no:hover { background: #f3f4f7; }
        #schedModal .sm-btn-yes { background: #1B2A4A; color: #fff; border: 1px solid #1B2A4A; }
        #schedModal .sm-btn-yes:hover { opacity: 0.88; }
        #schedModal .sm-btn-yes:disabled { opacity: 0.4; cursor: not-allowed; }
        @media (max-width: 600px) {
            #schedModal .sm-box { padding: 14px 14px 0 14px; }
            #schedModal .sm-actions { flex-direction: column-reverse; }
            #schedModal .sm-actions button { width: 100%; justify-content: center; }
        }
        /* Confirmation: a review screen (student, current -> new table, what happens, the reason) - no icons */
        #schedConfirmModal { padding: 20px 0; box-sizing: border-box; }
        #schedConfirmModal .sc-box { background: #fff; border: 1px solid #C3CADA; border-radius: 0; width: 560px; max-width: 94%; max-height: calc(100vh - 40px); max-height: calc(100dvh - 40px); display: flex; flex-direction: column; padding: 16px 24px 0 24px; box-sizing: border-box; text-align: left; animation: popIn 0.3s ease; }
        #schedConfirmModal .sc-header { flex-shrink: 0; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid #C3CADA; }
        #schedConfirmModal .sc-header h3 { margin: 0; color: #1B2A4A; font-size: 15px; font-family: inherit; text-transform: uppercase; letter-spacing: 0.4px; }
        #schedConfirmModal .sc-body { overflow-y: auto; flex: 1 1 auto; min-height: 0; }
        #schedConfirmModal .sc-lead { font-size: 13px; color: #475569; line-height: 1.5; margin: 0 0 10px; }
        #schedConfirmModal .sc-student { font-weight: 700; color: #1B2A4A; background: #F0F2F8; border: 1px solid #C3CADA; padding: 7px 10px; margin-bottom: 12px; font-size: 13px; }
        #schedConfirmModal .sc-diff { width: 100%; border-collapse: collapse; margin: 0 0 14px; font-size: 12.5px; text-align: left; border: 1px solid #C3CADA; }
        #schedConfirmModal .sc-diff th { background: #F0F2F8; color: #1B2A4A; padding: 6px 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; font-size: 11px; border-bottom: 1px solid #C3CADA; }
        #schedConfirmModal .sc-diff td { padding: 8px 10px; border-top: 1px solid #DCE1EC; color: #2d3748; vertical-align: top; }
        #schedConfirmModal .sc-diff td:first-child { font-weight: 700; color: #1e293b; white-space: nowrap; width: 1%; }
        #schedConfirmModal .sc-diff td.sc-new { font-weight: 700; color: #1B2A4A; background: #EAF3EA; }
        #schedConfirmModal .sc-diff td.sc-same { color: #5A6272; }
        #schedConfirmModal .sc-diff td.sc-same small { display: block; font-size: 10.5px; color: #8A93A6; }
        #schedConfirmModal .sc-h { font-size: 11px; font-weight: 700; color: #1B2A4A; text-transform: uppercase; letter-spacing: 0.3px; margin: 0 0 6px; }
        #schedConfirmModal .sc-steps { margin: 0 0 14px; padding: 0 0 0 20px; font-size: 13px; color: #475569; line-height: 1.55; }
        #schedConfirmModal .sc-steps li { margin-bottom: 4px; }
        #schedConfirmModal .sc-steps strong { color: #1e293b; }
        #schedConfirmModal .sc-reason { font-size: 12.5px; color: #2d3748; background: #FAF3DC; border: 1px solid #E6D9A8; padding: 8px 10px; margin: 0 0 12px; line-height: 1.5; word-break: break-word; white-space: pre-line; }
        #schedConfirmModal .sc-actions { display: flex; gap: 12px; justify-content: flex-end; flex-shrink: 0; background: #fff; margin-top: 4px; padding: 10px 0 12px; border-top: 1px solid #C3CADA; }
        #schedConfirmModal .sc-actions button { padding: 10px 24px; border-radius: 0; font-weight: 600; cursor: pointer; font-size: 12px; text-transform: uppercase; letter-spacing: 0.3px; font-family: inherit; transition: opacity 0.2s; }
        #schedConfirmModal .sc-actions button:hover { opacity: 0.88; }
        #schedConfirmModal .sc-btn-keep { background: #fff; color: #1B2A4A; border: 1px solid #C3CADA; }
        #schedConfirmModal .sc-btn-confirm { background: #1B2A4A; color: #fff; border: 1px solid #1B2A4A; }
        @media (max-width: 600px) {
            #schedConfirmModal .sc-box { padding: 14px 14px 0 14px; }
            #schedConfirmModal .sc-actions { flex-direction: column-reverse; }
            #schedConfirmModal .sc-actions button { width: 100%; }
            #schedConfirmModal .sc-diff td:first-child { white-space: normal; }
        }
        .cv-top-toast.is-error i { color: #f87171; } /* an error notice: same popup, red icon (as company_list.php) */

        /* ══ ADJUSTMENT: icon buttons + tooltips (Application column) ══ */
        /* ══ ADJUSTMENT: batches — inbox batch card, batch tag, shared cells ══ */
        .ar-batch-head { font-weight: 700; color: #1e293b; font-size: 14px; display: flex; align-items: center; gap: 7px; margin-bottom: 4px; }
        .ar-batch-head i { color: #16a34a; }
        .ar-batch-members { display: flex; flex-direction: column; gap: 6px; margin: 10px 0 8px; }
        .ar-batch-course { font-weight: 700; color: #1e293b; font-size: 14.5px; } /* ADJUSTMENT: the course heads the card */
        .ar-member { display: flex; align-items: center; justify-content: space-between; gap: 10px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 7px 10px; }
        .ar-member.removing { opacity: 0; transition: opacity .3s; }
        .ar-member-name { font-weight: 600; color: #1e293b; font-size: 13.5px; }
        .ar-member-fv { padding: 5px 10px !important; font-size: 12px !important; }
        .apl-batch-tag { display: inline-flex; align-items: center; gap: 5px; margin-top: 4px; font-size: 11px; font-weight: 700; color: #1e40af; background: #dbeafe; border-radius: 10px; padding: 2px 8px; }
        #applicantTable td.apl-batch-shared { vertical-align: middle; background: #f8faff; border-left: 3px solid #93c5fd; }
        /* ══ ADJUSTMENT: application-request popup (administrator.php / company_list.php .cv-top-toast):
           square navy bar at the top, green icon, name in bold white; fades out by itself, stacks,
           never blocks the page. ══ */
        .cv-top-toast { position: fixed; top: 30px; left: 50%; transform: translateX(-50%); background: #1B2A4A; color: #E3E8F1; border: 1px solid #55668C; border-radius: 0; padding: 14px 20px; box-shadow: 0 8px 24px rgba(27,42,74,0.30); display: flex; align-items: center; gap: 12px; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size: 12.5px; line-height: 1.45; z-index: 10020; max-width: 440px; opacity: 0; transition: opacity 0.35s, top 0.3s ease; pointer-events: none; }
        .cv-top-toast.show { opacity: 1; }
        .cv-top-toast i { color: #8FD18F; font-size: 18px; flex-shrink: 0; }
        .cv-top-toast strong { color: #ffffff; font-weight: 700; }
        .apl-icon-btn { width: 34px; height: 34px; padding: 0; justify-content: center; font-size: 14px; }
        .apl-icon-btn:disabled { pointer-events: none; }  /* lets the wrapper's tooltip still show */
        .apl-tip { position: relative; display: inline-flex; }
        .apl-tip::after {
            content: attr(data-tip);
            position: absolute; bottom: calc(100% + 8px); left: 50%;
            transform: translateX(-50%) translateY(4px);
            background: #1B2A4A; color: #ffffff; font-size: 11.5px; font-weight: 600; line-height: 1.3;
            padding: 6px 10px; white-space: nowrap; border-radius: 4px;
            box-shadow: 0 4px 14px rgba(27,42,74,0.25);
            opacity: 0; pointer-events: none; transition: opacity 0.15s, transform 0.15s; z-index: 30;
        }
        .apl-tip::before {
            content: ''; position: absolute; bottom: calc(100% + 3px); left: 50%;
            transform: translateX(-50%) translateY(4px);
            border: 5px solid transparent; border-top-color: #1B2A4A; border-bottom: 0;
            opacity: 0; pointer-events: none; transition: opacity 0.15s, transform 0.15s; z-index: 30;
        }
        .apl-tip:hover::after, .apl-tip:hover::before,
        .apl-tip:focus-within::after, .apl-tip:focus-within::before { opacity: 1; transform: translateX(-50%) translateY(0); }

        /* ══ ADJUSTMENT: UNDO TOAST — same as administrator.php (navy bar, countdown ring, top) ══ */
        #undoToast {
            position: fixed; top: 80px; left: 50%;
            transform: translateX(-50%) translateY(-120px);
            background: #1B2A4A; color: #ffffff; padding: 16px 22px;
            border: 1px solid #55668C; border-radius: 0; box-shadow: 0 8px 24px rgba(27,42,74,0.30);
            display: flex; align-items: center; gap: 16px;
            font-size: 14px; z-index: 9999; min-width: 360px; max-width: 520px;
            transition: transform 0.4s cubic-bezier(0.34,1.56,0.64,1), opacity 0.3s;
            opacity: 0;
        }
        #undoToast.show { transform: translateX(-50%) translateY(0); opacity: 1; }
        #undoToast.denied-pending { border: 1px solid #ef4444; }
        #undoToast .toast-label { flex: 1; line-height: 1.4; }
        #undoToast .toast-label strong { display: block; font-size: 10.5px; font-weight: 600; letter-spacing: 0.6px; text-transform: uppercase; color: #A3AFC7; }
        #undoToast .toast-label strong.denied-mode { color: #fca5a5; }
        #undoToast .toast-label span { font-size: 13px; font-weight: 600; color: #ffffff; }
        #undoToast .undo-btn { background: #ffffff; color: #1B2A4A; border: none; padding: 8px 18px; border-radius: 0; font-weight: 700; font-size: 12px; letter-spacing: 0.4px; text-transform: uppercase; cursor: pointer; white-space: nowrap; flex-shrink: 0; }
        #undoToast .undo-btn:hover { opacity: 0.85; }
        #undoToast .undo-btn:disabled { opacity: 0.4; cursor: not-allowed; }
        #undoToast .dismiss-btn { background: none; border: none; color: #A3AFC7; cursor: pointer; font-size: 18px; padding: 0 4px; flex-shrink: 0; }
        #undoToast .dismiss-btn:hover { color: #ffffff; }
        .countdown-ring { position: relative; width: 36px; height: 36px; flex-shrink: 0; }
        .countdown-ring svg { transform: rotate(-90deg); }
        .countdown-ring circle { fill: none; stroke: #3A4A6B; stroke-width: 3; }
        .countdown-ring .progress { stroke: #C3CADA; stroke-dasharray: 88; stroke-dashoffset: 0; transition: stroke-dashoffset 1s linear; stroke-linecap: round; }
        .countdown-ring .progress.denied-ring { stroke: #ef4444; }
        .countdown-ring .num { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; color: #C3CADA; }
        .countdown-ring .num.denied-num { color: #ef4444; } /* ADJUSTMENT: buttons start at the top of the cell, level with Preview Resume */
        .endo-note { font-size: 11.5px; color: #64748b; margin-top: 5px; font-style: italic; }
        .endo-remark { margin-top: 7px; background: #fef2f2; border: 1px solid #fecaca; color: #7f1d1d; border-radius: 8px; padding: 7px 10px; font-size: 12px; line-height: 1.45; max-width: 320px; }
        .endo-remark i { color: #dc2626; margin-right: 3px; }
        .endo-actions { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 9px; }
        .endo-btn { border-radius: 7px; font-size: 12px; font-weight: 700; padding: 6px 12px; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; white-space: nowrap; transition: all 0.2s; border: 1px solid transparent; }
        .endo-btn:disabled { opacity: 0.45; cursor: not-allowed; }
        .endo-btn.verify { background: #16a34a; color: #fff; }
        .endo-btn.verify:hover:not(:disabled) { opacity: 0.88; }
        .endo-btn.reject { background: #fff1f1; color: #dc2626; border-color: #fecaca; }
        .endo-btn.reject:hover:not(:disabled) { background: #dc2626; color: #fff; }
        .endo-btn.view   { background: #eff6ff; color: #1d4ed8; border-color: #bfdbfe; }
        .endo-btn.view:hover { background: #dbeafe; }
        .endo-btn.ghost  { background: #fff; color: #475569; border-color: #e2e8f0; }
        .endo-btn.ghost:hover { background: #f8fafc; }
        tr.applicant-row.row-updated { animation: row-flash-in 1.6s ease; }

        /* Endorsement letter / upload viewer (full screen) */
        #endoViewerOverlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.72); z-index: 10015; flex-direction: column; }
        #endoViewerOverlay.open { display: flex; }
        #endoViewerOverlay .fv-doc-toolbar { position: relative; }
        .endo-viewer-body { flex: 1; min-height: 0; background: #d8dde8; }
        #endoViewerFrame { width: 100%; height: 100%; border: none; display: block; background: #d8dde8; }
        .endo-tbtn { background: rgba(255,255,255,0.14); color: rgba(255,255,255,0.92); border: 1px solid rgba(255,255,255,0.28); padding: 7px 14px; border-radius: 6px; font-size: 12.5px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; }
        .endo-tbtn:hover { background: rgba(255,255,255,0.26); color: #fff; }
        .endo-tbtn.verify { background: #16a34a; border-color: #16a34a; color: #fff; }
        /* ADJUSTMENT: Remark dropdown + Save on the uploaded-letter preview toolbar */
        .endo-tb-remark { display: inline-flex; align-items: center; gap: 6px; }
        .endo-tb-remark select { height: 32px; padding: 0 10px; border-radius: 0; border: 1px solid #A3AFC7; background: #ffffff; color: #1B2A4A; font-size: 12.5px; font-family: inherit; max-width: 280px; cursor: pointer; }
        .endo-tb-remark select.needs-reason { border-color: #f87171; box-shadow: 0 0 0 2px rgba(248,113,113,0.45); }
        .endo-tb-save { height: 32px; padding: 0 16px; border-radius: 0; border: none; background: #ffffff; color: #1B2A4A; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; cursor: pointer; }
        .endo-tb-save:hover:not(:disabled) { opacity: 0.85; }
        .endo-tb-save:disabled { opacity: 0.5; cursor: not-allowed; }
        .endo-tbtn.reject { background: #dc2626; border-color: #dc2626; color: #fff; }

        /* Reject-with-remarks modal (same look as #rejectModal) */
        #endoRejectModal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 10030; justify-content: center; align-items: center; }
        #endoRejectModalBox { background: white; padding: 28px; border-radius: 14px; width: 460px; max-width: 92%; box-shadow: 0 20px 60px rgba(0,0,0,0.2); animation: popIn 0.3s cubic-bezier(0.34,1.56,0.64,1); }
        #endoRejectModalBox h3 { margin: 0 0 6px; color: #dc2626; font-size: 16px; }
        #endoRejectModalBox p  { margin: 0 0 12px; font-size: 13px; color: #64748b; }
        #endoRejectModalBox textarea { width: 100%; height: 90px; padding: 10px; border: 1px solid #ddd; border-radius: 8px; box-sizing: border-box; resize: vertical; font-family: inherit; font-size: 13px; }
        #endoRejectModalBox textarea:focus { outline: none; border-color: #dc2626; }
        .endo-quick-remarks { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 10px; }
        .endo-quick-remarks button { background: #f8fafc; border: 1px solid #e2e8f0; color: #475569; font-size: 11.5px; font-weight: 600; padding: 4px 10px; border-radius: 14px; cursor: pointer; }
        .endo-quick-remarks button:hover { background: #fee2e2; border-color: #fecaca; color: #991b1b; }
    </style>
</head>
<body>
<!-- ══════════════════════════════════════════════════════════
     ADJUSTMENT: page-load / processing loading page — same markup and behaviour as company_list.php's
     #globalLoadingOverlay. Visible by default so it covers the page while it is still loading, then it
     fades out; it is shown again (with a label) while an action is being processed.
     ══════════════════════════════════════════════════════════ -->
<div id="globalLoadingOverlay">
    <div class="global-loading-box">
        <div class="global-loading-spinner"></div>
        <div class="global-loading-text">
            <span id="globalLoadingLabel">Loading</span>
            <span class="global-loading-dots"><span>.</span><span>.</span><span>.</span></span>
        </div>
    </div>
</div>
<!-- Without JavaScript nothing could ever close the overlay — never leave the page covered. -->
<noscript><style>#globalLoadingOverlay { display: none !important; }</style></noscript>
<script>
    /* Ported from company_list.php (same counter pattern, same timings):
       showGlobalLoading()/hideGlobalLoading() for in-page work, the first page load as its own token,
       and an instant overlay on reload / leave. */
    var globalLoadingActiveCount = 1;          // 1 = the initial page-load token
    var globalLoadingOverlay = document.getElementById('globalLoadingOverlay');
    var globalLoadingLabel   = document.getElementById('globalLoadingLabel');
    var GLOBAL_LOADING_MIN_MS       = 350;
    var GLOBAL_LOADING_SAFETY_MS    = 4000;
    var GLOBAL_LOADING_NAV_STUCK_MS = 15000;
    var globalLoadingStartedAt   = (window.performance && performance.now) ? performance.now() : 0;
    var globalLoadingInitialDone = false;
    var globalLoadingNavigating  = false;
    var globalLoadingNavTimer    = null;

    /* No second loading page. An action that reloads / re-opens THIS page (registering a student, accepting or
       rejecting a whole batch) already shows its own loading page; the page that is leaving leaves a short-lived
       flag (sessionStorage) and the page that opens reads it once and starts with the overlay already hidden.
       Every storage access is wrapped because storage can be blocked (private mode) - the page then simply
       behaves as before. */
    var GLOBAL_LOADING_SKIP_KEY = 'aos_skip_initial_loading';
    var GLOBAL_LOADING_SKIP_TTL = 15000;
    function globalLoadingMarkReturn() {
        try { sessionStorage.setItem(GLOBAL_LOADING_SKIP_KEY, String(Date.now())); } catch (e) {}
    }
    function globalLoadingClearReturn() {
        try { sessionStorage.removeItem(GLOBAL_LOADING_SKIP_KEY); } catch (e) {}
    }
    (function () {
        var fresh = false;
        try {
            var t = parseInt(sessionStorage.getItem(GLOBAL_LOADING_SKIP_KEY) || '', 10);
            fresh = !isNaN(t) && (Date.now() - t) >= 0 && (Date.now() - t) < GLOBAL_LOADING_SKIP_TTL;
            sessionStorage.removeItem(GLOBAL_LOADING_SKIP_KEY);
        } catch (e) { fresh = false; }
        if (fresh && globalLoadingOverlay) {
            globalLoadingActiveCount = 0;
            globalLoadingInitialDone = true;
            globalLoadingOverlay.classList.add('gl-instant', 'hidden');
        }
    })();

    function globalLoadingPaint() {
        if (!globalLoadingOverlay) return;
        if (globalLoadingActiveCount > 0 || globalLoadingNavigating) {
            globalLoadingOverlay.classList.remove('hidden');
        } else {
            globalLoadingOverlay.classList.remove('gl-instant');
            globalLoadingOverlay.classList.add('hidden');
        }
    }
    function showGlobalLoading(label) {
        globalLoadingActiveCount++;
        if (globalLoadingLabel) globalLoadingLabel.textContent = label || 'Loading';
        globalLoadingPaint();
    }
    function hideGlobalLoading() {
        globalLoadingActiveCount = Math.max(0, globalLoadingActiveCount - 1);
        if (globalLoadingLabel && globalLoadingActiveCount === 0 && !globalLoadingNavigating) globalLoadingLabel.textContent = 'Loading';
        globalLoadingPaint();
    }
    function finishInitialGlobalLoading() {
        if (globalLoadingInitialDone) return;
        var now = (window.performance && performance.now) ? performance.now() : GLOBAL_LOADING_MIN_MS;
        var wait = Math.max(0, GLOBAL_LOADING_MIN_MS - (now - globalLoadingStartedAt));
        globalLoadingInitialDone = true;
        setTimeout(function () {
            globalLoadingActiveCount = Math.max(0, globalLoadingActiveCount - 1);
            if (globalLoadingLabel && globalLoadingActiveCount === 0) globalLoadingLabel.textContent = 'Loading';
            globalLoadingPaint();
        }, wait);
    }
    function startNavigationGlobalLoading(label) {
        globalLoadingNavigating = true;
        if (globalLoadingLabel) globalLoadingLabel.textContent = label || 'Loading';
        if (globalLoadingOverlay) globalLoadingOverlay.classList.add('gl-instant');
        globalLoadingPaint();
        clearTimeout(globalLoadingNavTimer);
        globalLoadingNavTimer = setTimeout(stopNavigationGlobalLoading, GLOBAL_LOADING_NAV_STUCK_MS);
    }
    function stopNavigationGlobalLoading() {
        clearTimeout(globalLoadingNavTimer);
        globalLoadingClearReturn();
        globalLoadingNavigating = false;
        if (globalLoadingLabel && globalLoadingActiveCount === 0) globalLoadingLabel.textContent = 'Loading';
        globalLoadingPaint();
    }
    if (document.readyState === 'complete') { finishInitialGlobalLoading(); }
    else { window.addEventListener('load', finishInitialGlobalLoading); }
    setTimeout(finishInitialGlobalLoading, GLOBAL_LOADING_SAFETY_MS);

    // Reload / leave / form submit — keeps a more specific label already set (e.g. "Registering student").
    window.addEventListener('beforeunload', function () {
        if (!globalLoadingNavigating) startNavigationGlobalLoading('Loading');
    });
    // Same-tab links: show the overlay on click, before beforeunload even fires.
    document.addEventListener('click', function (e) {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
        if (!a) return;
        var href = a.getAttribute('href') || '';
        if (!href || href.charAt(0) === '#' || /^(javascript|mailto|tel|blob|data):/i.test(href)) return;
        if (a.hasAttribute('download')) return;
        if (a.target && a.target.toLowerCase() !== '_self') return;
        if (a.origin && a.origin !== window.location.origin) return;
        startNavigationGlobalLoading('Loading');
    });
    // "Register Student" is a native form post that comes back to this very page.
    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (e.defaultPrevented || !f || !f.querySelector || !f.querySelector('input[name="student_search"]')) return;
        globalLoadingMarkReturn();
        startNavigationGlobalLoading('Registering student');
    });
    // Back/Forward cache restore: the page did not reload, so re-sync the overlay.
    window.addEventListener('pageshow', function (e) {
        if (e.persisted) {
            globalLoadingInitialDone = true;
            globalLoadingActiveCount = 0;
            stopNavigationGlobalLoading();
        }
    });
</script>

<!-- ── CONFIRM POPUP ── -->
<div id="confirmPopup" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:5000; justify-content:center; align-items:center;">
    <div id="confirmPopupBox" style="background:white; padding:28px; border-radius:14px; width:400px; max-width:92%; box-shadow:0 20px 60px rgba(0,0,0,0.2); animation:popIn 0.3s cubic-bezier(0.34,1.56,0.64,1);">
        <div style="display:flex; align-items:center; gap:12px; margin-bottom:10px;">
            <div id="confirmPopupIcon" style="width:38px; height:38px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:16px; flex-shrink:0;"></div>
            <h3 id="confirmPopupTitle" style="margin:0; font-size:16px; color:#1e293b;"></h3>
        </div>
        <p id="confirmPopupMsg" style="margin:0 0 20px; font-size:14px; color:#64748b; line-height:1.5; padding-left:50px;"></p>
        <div style="display:flex; justify-content:flex-end; gap:10px;">
            <button id="confirmPopupCancel" onclick="closeConfirmPopup()" style="padding:9px 20px; background:#f1f5f9; color:#475569; border:none; border-radius:8px; font-weight:700; cursor:pointer; font-size:13px;">Cancel</button>
            <button id="confirmPopupOk" style="padding:9px 22px; border:none; border-radius:8px; font-weight:700; cursor:pointer; font-size:13px; display:flex; align-items:center; gap:6px;"></button>
        </div>
    </div>
</div>

<!-- ── REJECT MODAL ── -->
<div id="rejectModal">
    <div id="rejectModalBox">
        <h3><i class="fas fa-times-circle"></i> Reject Application</h3>
        <p>Please provide a reason. It will be sent to the student via email.</p>
        <form method="POST" id="rejectForm">
            <input type="hidden" name="reject_app" value="1">
            <input type="hidden" name="reject_app_id" id="rejectAppId">
            <textarea name="reject_reason" id="rejectReasonText" required
                placeholder="e.g. We have reached our maximum OJT quota for this semester..."
                oninput="updateCharHint(this)"></textarea>
            <div class="reject-char-hint" id="rejectCharHint">Minimum 10 characters required</div>
            <div class="reject-actions">
                <button type="button" class="reject-cancel" onclick="closeRejectModal()">Cancel</button>
                <button type="submit" class="reject-submit" id="rejectSubmitBtn" disabled>
                    <i class="fas fa-paper-plane"></i> Send &amp; Reject
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ── NEW (schedule change): SET UP A NEW SCHEDULE FOR THE STUDENT (company_list.php popup design) ── -->
<div id="schedModal" role="dialog" aria-modal="true" aria-labelledby="schedModalTitle">
    <div class="sm-box">
        <div class="sm-header">
            <h3 id="schedModalTitle">Set Up New Schedule</h3>
            <button type="button" class="sm-close" aria-label="Close" onclick="closeSchedModal()">&times;</button>
        </div>
        <div class="sm-body">
            <p>Choose the new Day and Evening Schedule (Monday to Friday) for this student and tell them why it is changing. The reason is sent to the student and the administrator by email.</p>
            <div class="sched-student" id="schedStudentName">—</div>
            <div class="sched-group">
                <div class="sched-label">Day Schedule <span class="sched-current" id="schedCurDay"></span></div>
                <div class="sched-chips" id="schedDayChips"></div>
            </div>
            <div class="sched-group">
                <div class="sched-label">Evening Schedule <span class="sched-current" id="schedCurEve"></span></div>
                <div class="sched-chips" id="schedEveChips"></div>
            </div>
            <div class="sched-label">Reason for the change <span style="color:#A02A2A">*</span></div>
            <textarea id="schedReason" maxlength="500" placeholder="e.g. Our department needs interns on different days starting next week..." oninput="schedValidate()"></textarea>
            <div id="schedMsg" role="status"></div>
            <p class="sm-note">Saving also removes the student's current <strong>Student/University Contract</strong> &mdash; the student will need to upload a new one that reflects the new schedule.</p>
        </div>
        <div class="sm-actions">
            <button type="button" class="sm-btn-no" onclick="closeSchedModal()">Cancel</button>
            <button type="button" class="sm-btn-yes" id="schedContinueBtn" onclick="schedContinue()" disabled>Continue</button>
        </div>
    </div>
</div>

<div id="schedConfirmModal" role="alertdialog" aria-modal="true" aria-labelledby="schedConfirmTitle">
    <div class="sc-box">
        <div class="sc-header"><h3 id="schedConfirmTitle">Confirm New Schedule</h3></div>
        <div class="sc-body">
            <p class="sc-lead">Please review the change below before saving it.</p>
            <div class="sc-student" id="schedConfirmStudent">—</div>
            <table class="sc-diff">
                <thead><tr><th>Schedule</th><th>Current</th><th>New</th></tr></thead>
                <tbody id="schedConfirmRows"></tbody>
            </table>
            <div class="sc-h">When you confirm</div>
            <ol class="sc-steps">
                <li>The student's schedule is updated.</li>
                <li>The student's <strong>Student/University Contract</strong> is removed (the record and the uploaded file), if one is on file. The student has to submit a new one and have it validated again.</li>
                <li>The student and the administrator are notified by email, together with your reason.</li>
            </ol>
            <div class="sc-h">Your reason</div>
            <div class="sc-reason" id="schedConfirmReason"></div>
        </div>
        <div class="sc-actions">
            <button type="button" class="sc-btn-keep" onclick="closeSchedConfirm()">No, Go Back</button>
            <button type="button" class="sc-btn-confirm" id="schedConfirmYes" onclick="schedConfirmYes()">Yes, Set Schedule</button>
        </div>
    </div>
</div>

<!-- ── TOAST ── -->
<div id="actionToast"><i id="actionToastIcon" class="fas fa-check-circle"></i> <span id="actionToastMsg"></span></div>

<!-- ── ADJUSTMENT: UNDO TOAST (top) — same markup as administrator.php ── -->
<div id="undoToast">
    <div class="countdown-ring">
        <svg width="36" height="36" viewBox="0 0 36 36">
            <circle cx="18" cy="18" r="14"/>
            <circle class="progress" id="undoRingProgress" cx="18" cy="18" r="14"/>
        </svg>
        <div class="num" id="undoCountNum">5:00</div>
    </div>
    <div class="toast-label">
        <strong id="undoToastStatus">Status updated</strong>
        <span id="undoToastLabel">—</span>
    </div>
    <button class="undo-btn" id="undoBtnMain" onclick="triggerEndoUndo()">↩ Undo</button>
    <button class="dismiss-btn" onclick="dismissEndoUndo(true)" title="Dismiss">×</button>
</div>

<!-- ── NEW (endorsement flow): ENDORSEMENT LETTER / UPLOAD VIEWER (full screen) ── -->
<div id="endoViewerOverlay">
    <div class="fv-doc-toolbar">
        <div class="fv-doc-toolbar-left">
            <i class="fas fa-file-signature" style="color:rgba(255,255,255,0.7);"></i>
            <span class="fv-doc-toolbar-title" id="endoViewerTitle">Endorsement Letter</span>
        </div>
        <div class="fv-doc-toolbar-right">
            <!-- ADJUSTMENT: Remark dropdown + Save on the uploaded-letter preview (replaces Verify / Reject).
                 Saving a remark deletes the student's uploaded letter entirely (after Proceed / Cancel). -->
            <span class="endo-tb-remark" id="endoViewerRemarkWrap" style="display:none;">
                <select id="endoViewerRemark" aria-label="Remark">
                    <option value="">Remark</option>
                    <?php foreach ($ENDO_REJECT_REASONS as $r): ?>
                        <option value="<?= htmlspecialchars($r, ENT_QUOTES) ?>"><?= htmlspecialchars($r) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="endo-tb-save" id="endoViewerRemarkSave">Save</button>
            </span>
            <a class="endo-tbtn" id="endoViewerNewTab" href="#" target="_blank" rel="noopener"><i class="fas fa-up-right-from-square"></i> Open in New Tab</a>
            <button type="button" class="fv-tbtn-close" onclick="closeEndoViewer()"><i class="fas fa-times"></i> Close</button>
        </div>
    </div>
    <div class="endo-viewer-body">
        <iframe id="endoViewerFrame" title="Endorsement letter"></iframe>
    </div>
</div>

<!-- ── NEW (endorsement flow): REJECT ENDORSEMENT LETTER — REMARKS ── -->
<div id="endoRejectModal">
    <div id="endoRejectModalBox">
        <h3><i class="fas fa-times-circle"></i> Reject Endorsement Letter</h3>
        <p>Tell <strong id="endoRejectName">the student</strong> what needs to be corrected. Your remarks are shown in their Inbox and sent via email, and they can upload a corrected letter.</p>
        <div class="endo-quick-remarks">
            <button type="button" data-remark="The letter is not signed by the required signatories.">Not signed</button>
            <button type="button" data-remark="The uploaded image is blurry or unreadable. Please upload a clear copy.">Blurry / unreadable</button>
            <button type="button" data-remark="Some pages of the letter are missing.">Incomplete pages</button>
            <button type="button" data-remark="The uploaded file is not the endorsement letter issued for this company.">Wrong document</button>
        </div>
        <textarea id="endoRejectRemark" placeholder="e.g. The letter is missing the Dean's signature..."></textarea>
        <div class="reject-char-hint" id="endoRejectHint">Minimum 5 characters required</div>
        <div class="reject-actions">
            <button type="button" class="reject-cancel" onclick="closeEndoRejectModal()">Cancel</button>
            <button type="button" class="reject-submit" id="endoRejectSubmit" disabled onclick="submitEndoReject()">
                <i class="fas fa-paper-plane"></i> Send &amp; Reject
            </button>
        </div>
    </div>
</div>

<!-- ── FULL VIEW APPLICATION MODAL — LETTERHEAD/DOCUMENT STYLE, PAGINATED ──
     Ported from administrator.php: instead of a single ever-growing panel,
     the document is built from however many fixed-size A4 pages the
     applicant's Skills/Experience content needs (fvRenderPages()), plus
     one always-last, dedicated Submitted Documents page. The Accept/Reject
     signature bar (.fv-submit-bar) still sits below the paginated pages,
     wired to this file's existing acceptApp() / openRejectModal() flows. -->
<div id="appFullViewOverlay">
    <div class="fv-doc-toolbar">
        <div class="fv-doc-toolbar-left">
            <i class="fas fa-file-user" style="color:rgba(255,255,255,0.7);"></i>
            <span class="fv-doc-toolbar-title" id="fvToolbarTitle">Application Review — Full View</span>
        </div>
        <div class="fv-doc-toolbar-right">
            <button class="fv-tbtn-close" onclick="closeAppFullView()"><i class="fas fa-times"></i> Close</button>
        </div>
    </div>
    <div class="fv-doc-canvas">
        <div id="appFullViewBox">

            <!-- Hidden header/footer templates cloned onto every generated page -->
            <div class="fv-header-clone" id="fvHeaderClone">
                <div class="fv-letterhead">
                    <div class="fv-lh-seal"><img src="logo.webp" alt="NEUST Seal"></div>
                    <div class="fv-lh-text">
                        <div class="fv-lh-line1">Republic of the Philippines</div>
                        <div class="fv-lh-line2">Nueva Ecija University of Science and Technology</div>
                        <div class="fv-lh-line3">On&ndash;the&ndash;Job Training and Career Development Center</div>
                        <div class="fv-lh-line4">Atate Campus</div>
                    </div>
                </div>
                <div class="fv-title-band">
                    <h1>OJT Application Review</h1>
                    <div class="fv-form-meta">Student Application Request &mdash; Full View</div>
                </div>
            </div>
            <div class="fv-footer-clone" id="fvFooterClone">
                <div class="fv-footer-band">
                    <span>NEUST&ndash;OJT&ndash;APPREV</span>
                    <span>Application Review</span>
                </div>
            </div>

            <!-- JS-built paginated pages land here -->
            <div class="fv-pages-wrap" id="fvPagesWrap"></div>

            <!-- Hidden raw source — populated by openAppFullView(), measured/cloned by fvRenderPages() -->
            <div class="fv-raw-source" id="fvRawSource">

                <div class="fv-applicant-strip">
                    <div class="fv-avatar2" id="fv-photo-thumb"></div>
                    <div style="flex:1; min-width:0;">
                        <div class="fv-resume-mirror-info">
                            <p><b>Full Name:</b> <span id="fv-name-val">&nbsp;</span></p>
                            <p><b>Email:</b> <span id="fv-email-val">&nbsp;</span></p>
                            <p><b>Course:</b> <span id="fv-course-val">&nbsp;</span></p>
                        </div>
                        <div class="fv-applicant-badges">
                            <span class="fv-badge-pill fv-badge-company" id="fv-company"></span>
                            <span class="fv-badge-pill fv-badge-date" id="fv-date-pill"></span>
                            <span class="fv-badge-pill fv-badge-ojtcount" id="fv-ojt-count-pill" style="display:none;"></span>
                            <span class="fv-status-chip fv-badge-photo" id="fv-photo-status"></span>
                        </div>
                    </div>
                </div>

                <div class="fv-section-title fv-skills-title">Skills</div>
                <div class="fv-entries-col fv-skills-col" id="fv-skills"></div>

                <div class="fv-section-title fv-exp-title">Experience</div>
                <div class="fv-entries-col fv-exp-col" id="fv-exp"></div>

                <div class="fv-section-title fv-doc-title">Submitted Documents (click a thumbnail to view)</div>
                <table class="fv-doc-table">
                    <tbody id="fv-docs-grid">
                        <tr class="fv-doc-th"><td>Document</td><td style="width:110px; text-align:center;">Status</td></tr>
                    </tbody>
                </table>

            </div><!-- end #fvRawSource -->

            <div id="fv-date" style="display:none;"></div>

            <!-- ADJUSTMENT: Accept / Reject removed from the digital resume — the application
                 is accepted or rejected from the Application column of the applicants table. -->
        </div>
    </div>
</div>

<!-- ── SIDEBAR ── -->
<div id="sidebar" class="sidebar">
    <div class="sidebar-header">
        <h2>OJT Student List</h2>
        <button id="toggleBtn" style="background:none;border:none;color:white;cursor:pointer;font-size:20px;"><i class="fas fa-bars"></i></button>
    </div>
    <div class="sidebar-links">
        <a href="Profile.php"><i class="fas fa-user-circle"></i><span class="link-text">My Profile</span></a>
        <a href="add_ojt_student.php" class="active">
            <i class="fas fa-user-graduate"></i>
            <span class="link-text">OJT Student List</span>
            <?php if ($inbox_count > 0): ?>
            <span class="sidebar-badge"><?= $inbox_count ?></span>
            <?php endif; ?>
        </a>
        <a href="CompanyForm.php"><i class="fas fa-file-contract"></i><span class="link-text">Requirements</span></a>
        <a href="attendance_management.php"><i class="fas fa-building"></i>
            <span class="link-text">Attendance Management</span>
            <?php if ($pending_lr_count > 0): ?>
                <span class="sidebar-badge-late"><?= $pending_lr_count ?></span>
            <?php endif; ?>
        </a>
        <a href="company_reports.php">
            <i class="fas fa-chart-bar"></i>
            <span class="link-text">Company Reports</span>
            <?php if ($ungraded_count > 0): ?>
                <span class="sidebar-badge-ungraded"><?= $ungraded_count ?></span>
            <?php endif; ?>
        </a>
    </div>
    <div class="logout-link">
        <a href="logout.php"><i class="fas fa-sign-out-alt"></i><span class="link-text" style="margin-left:10px;">Logout</span></a>
    </div>
</div>

<!-- ── MAIN CONTENT ── -->
<div class="main-content">
    <nav class="navbar">
        <img src="logo.webp" style="height:40px;margin-right:15px;">
        <div>
            <div style="font-weight:bold; font-size:16px;">NEUST Atate Campus</div>
            <div style="font-size:11px; color:var(--neust-gold);">Web-Based Smart OJT Monitoring and Supervision Analytics System</div>
        </div>
        <div class="navbar-right">
            <button id="inboxFab" onclick="openInbox()" title="Students Endorsed by the Admin">
                <i class="fas fa-inbox"></i>
                <span id="inboxFabBadge"><?= $inbox_count > 0 ? $inbox_count : '' ?></span>
            </button>
        </div>
    </nav>

    <?php if ($inbox_count > 0): ?>
    <script>document.getElementById('inboxFabBadge').style.display='flex';</script>
    <?php endif; ?>

    <!-- ── INBOX DRAWER ── -->
    <div id="inboxOverlay" onclick="if(event.target===this)closeInbox()">
        <div id="inboxDrawer">
            <div id="inboxHeader">
                <h3>
                    <i class="fas fa-inbox"></i> Students Endorsed by the Admin
                    <?php if ($inbox_count > 0): ?>
                    <span style="background:#dc2626;color:white;font-size:0.7rem;padding:2px 9px;border-radius:20px;font-weight:700;">
                        <?= $inbox_count ?> New
                    </span>
                    <?php endif; ?>
                </h3>
                <button id="inboxClose" onclick="closeInbox()">&#x2715;</button>
            </div>
            <div id="inboxBody">
            <?php if (empty($inbox_rows)): ?>
                <div class="ar-empty">
                    <i class="fas fa-inbox"></i>
                    No pending applications.
                </div>
            <?php else: ?>
                <?php
                $cntQ = $conn->prepare("SELECT COUNT(*) AS cnt FROM ojt_assignments WHERE company_id = ?");
                $cntQ->bind_param("i", $company_id);
                $cntQ->execute();
                $cntRow = $cntQ->get_result()->fetch_assoc();
                $cntQ->close();
                $currentCount = (int)($cntRow['cnt'] ?? 0);
                ?>
                <?php /* ADJUSTMENT: grouped by the admin's batch (renderInboxGroupsHtml) */ ?>
                <?= renderInboxGroupsHtml($conn, $company_id, $company_name, $inbox_rows, $currentCount) ?>
            <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="container">
        <?php if ($message): ?>
        <div style="background:<?= (str_contains(strtolower($message),'accepted') || str_contains(strtolower($message),'success') || str_contains(strtolower($message),'added')) ? '#f0fdf4' : '#fef2f2' ?>;
                    border:1px solid <?= (str_contains(strtolower($message),'accepted') || str_contains(strtolower($message),'success') || str_contains(strtolower($message),'added')) ? '#bbf7d0' : '#fecaca' ?>;
                    color:<?= (str_contains(strtolower($message),'accepted') || str_contains(strtolower($message),'success') || str_contains(strtolower($message),'added')) ? '#16a34a' : '#dc2626' ?>;
                    padding:12px 18px;border-radius:10px;font-size:14px;font-weight:600;margin-bottom:20px;">
            <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>

        <!-- ══ ADD STUDENT CARD (UNTOUCHED) ══ -->
        <div class="card">
            <h2>Add OJT Student</h2>
            <form method="POST" class="search-box">
                <input type="text" name="student_search" placeholder="Enter Student Email or Full Name..." required>
                <button type="submit" name="add_student" class="btn-reg">Register Student</button>
            </form>
        </div>

        <!-- ══ NEW (endorsement flow): OJT APPLICANTS — ENDORSEMENT LETTER VALIDATION ══
             Applications approved by the administrator appear here directly.
             Verifying the student's uploaded endorsement letter registers the
             student to this company (moves them to Registered OJT Students). -->
        <div class="card">
            <h2>OJT Applicants &mdash; Endorsement Letter Validation <span class="apl-count" id="applicantCount"<?= count($applicant_rows) ? '' : ' style="display:none;"' ?>><?= count($applicant_rows) ?></span></h2>
            <p class="apl-intro">Preview each applicant's digital resume and the endorsement letter they uploaded. <strong>Accept</strong> the application once the letter is in order, or open the letter and save a <strong>remark</strong> — the uploaded letter is then deleted and the student uploads a corrected one.</p>
            <div class="apl-table-wrap">
                <table id="applicantTable" data-sig="<?= htmlspecialchars($applicant_sig) ?>">
                    <thead>
                        <tr>
                            <th>Student Name</th><th>Course</th><th>Schedule</th><th>Skills &amp; Experience</th><th>Endorsement Letter</th><th>Application</th>
                        </tr>
                    </thead>
                    <tbody id="applicantTableBody">
                        <?= renderApplicantRowsHtml($conn, $company_id, $company_name, $applicant_rows) ?>
                    </tbody>
                </table>
            </div>
            <!-- ADJUSTMENT: pagination — same controls as administrator.php (5 applicants per page) -->
            <div class="pagination-controls" id="aplPaginationControls" style="display:none;">
                <button type="button" class="pg-btn" id="aplPgPrev" onclick="aplChangePage(aplCurrentPage - 1)">← Prev</button>
                <span id="aplPgNumbers" style="display:flex; gap:5px; align-items:center;"></span>
                <button type="button" class="pg-btn" id="aplPgNext" onclick="aplChangePage(aplCurrentPage + 1)">Next →</button>
                <span class="pg-info" id="aplPgInfo"></span>
            </div>
        </div>

        <!-- ══ REGISTERED STUDENTS ══ -->
        <div class="card">
            <h2>Registered OJT Students</h2>
            <table id="studentTable">
                <thead>
                    <tr>
                        <th>Photo</th><th>Name</th><th>Email</th><th>Status</th><th>Action</th>
                    </tr>
                </thead>
                <tbody id="studentTableBody">
                    <?php while ($row = $result->fetch_assoc()): ?>
                    <tr id="studentRow_<?= $row['ojt_id'] ?>">
                        <td>
                            <?php if ($row['student_photo']): ?>
                                <img src="data:image/jpeg;base64,<?= base64_encode($row['student_photo']) ?>" class="student-photo">
                            <?php else: ?>
                                <div style="width:45px;height:45px;background:#eee;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:10px;">N/A</div>
                            <?php endif; ?>
                        </td>
                        <td style="font-weight:600;"><?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?></td>
                        <td><?= htmlspecialchars($row['email']) ?></td>
                        <td><span class="status-badge"><?= htmlspecialchars($row['deploy_status']) ?></span></td>
                        <td>
                            <button type="button"
                                    class="btn-remove"
                                    data-ojt-id="<?= $row['ojt_id'] ?>"
                                    data-student-name="<?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?>">
                                Remove
                            </button>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="imagePreviewModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.9);justify-content:center;align-items:center;z-index:10020;">
    <span onclick="this.parentElement.style.display='none'" style="position:absolute;top:20px;right:40px;font-size:40px;color:white;cursor:pointer;">&times;</span>
    <img id="previewImage" style="max-width:90%; max-height:90%; border-radius:4px;">
</div>

<script>
// ── CONFIRM POPUP ─────────────────────────────────────────────────────────────
let _confirmCallback = null;

function showConfirmPopup(title, msg, type, callback) {
    const iconEl  = document.getElementById('confirmPopupIcon');
    const titleEl = document.getElementById('confirmPopupTitle');
    const msgEl   = document.getElementById('confirmPopupMsg');
    const okBtn   = document.getElementById('confirmPopupOk');

    const cfg = {
        success: { bg:'#dcfce7', color:'#16a34a', icon:'&#10003;', btnBg:'#16a34a', btnText:'Confirm' },
        danger:  { bg:'#fee2e2', color:'#dc2626', icon:'&#10005;', btnBg:'#dc2626', btnText:'Remove'  },
        proceed: { bg:'#fef3c7', color:'#d97706', icon:'!',        btnBg:'#dc2626', btnText:'Proceed' }, // ADJUSTMENT
    };
    const c = cfg[type] || cfg.success;

    iconEl.style.background = c.bg;
    iconEl.style.color      = c.color;
    iconEl.innerHTML        = c.icon;
    titleEl.textContent     = title;
    msgEl.textContent       = msg;
    okBtn.style.background  = c.btnBg;
    okBtn.style.color       = 'white';
    okBtn.innerHTML         = c.btnText;

    _confirmCallback = callback;
    document.getElementById('confirmPopup').style.display = 'flex';
}

function closeConfirmPopup() {
    document.getElementById('confirmPopup').style.display = 'none';
    document.getElementById('confirmPopup').style.zIndex = ''; // ADJUSTMENT: back to its own layer (raised only over the letter preview)
    _confirmCallback = null;
}

document.getElementById('confirmPopupCancel').addEventListener('click', function() {
    closeConfirmPopup();
});

document.getElementById('confirmPopup').addEventListener('click', function(e) {
    if (e.target === this) closeConfirmPopup();
});

document.getElementById('confirmPopupOk').addEventListener('click', function() {
    var cb = _confirmCallback;
    document.getElementById('confirmPopup').style.display = 'none';
    _confirmCallback = null;
    if (typeof cb === 'function') cb();
});

// ── SIDEBAR TOGGLE ────────────────────────────────────────────────────────────
const sb = document.getElementById('sidebar');
document.getElementById('toggleBtn').addEventListener('click', () => sb.classList.toggle('collapsed'));

// ── SHARED UTILITIES ──────────────────────────────────────────────────────────
function escHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function openPreview(src){ document.getElementById("previewImage").src = src; document.getElementById("imagePreviewModal").style.display="flex"; }

// ── INBOX DRAWER ─────────────────────────────────────────────────────────────
function openInbox()  { document.getElementById('inboxOverlay').style.display = 'flex'; }
function closeInbox() { document.getElementById('inboxOverlay').style.display = 'none'; }

// ── TOAST ─────────────────────────────────────────────────────────────────────
let _toastTimer = null;
function showToast(msg, type) {
    const toast  = document.getElementById('actionToast');
    const iconEl = document.getElementById('actionToastIcon');
    const msgEl  = document.getElementById('actionToastMsg');
    toast.className = '';
    iconEl.className = type === 'success' ? 'fas fa-check-circle' : 'fas fa-times-circle';
    iconEl.style.color = type === 'success' ? '#4ade80' : '#f87171';
    msgEl.textContent  = msg;
    toast.classList.add('show', type);
    if (_toastTimer) clearTimeout(_toastTimer);
    _toastTimer = setTimeout(() => toast.classList.remove('show'), 4000);
}

// ── ACCEPT APPLICATION ────────────────────────────────────────────────────────
// NEW (endorsement flow): optional `opts` lets the applicants table reuse this exact
// flow for "Verify" (verifying the endorsement letter == accepting the application),
// with its own wording. The button's original label is restored on failure.
function acceptApp(appId, btn, opts) {
    opts = opts || {};
    const _origBtnHtml = btn ? btn.innerHTML : '';
    showConfirmPopup(
        opts.title || 'Accept Application',
        opts.msg   || 'Accept this application and register the student?',
        'success',
        function() {
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>' + (btn.classList.contains('apl-icon-btn') ? '' : ' ' + (opts.busy || 'Accepting…')); // ADJUSTMENT: icon buttons stay icon-only
            }

            const fd = new FormData();
            fd.append('accept_app', '1');
            fd.append('app_id', String(appId));

            const _loadStarted = endoShowLoading(opts.loading || 'Accepting application'); // loading page
            fetch('add_ojt_student.php', { method: 'POST', body: fd })
                .then(function(response) { return response.text(); })
                .then(function(rawText) {
                    var res = {};
                    try { res = JSON.parse(rawText); } catch (parseErr) {
                        showToast('Server error. Please try again.', 'error');
                        if (btn) { btn.disabled = false; btn.innerHTML = _origBtnHtml; }
                        return;
                    }
                    if (res.success) {
                        var card = document.getElementById('appCard' + appId);
                        if (card) {
                            card.classList.add('removing');
                            setTimeout(function() {
                                card.remove();
                                updateInboxBadge(-1);
                                checkEmptyInbox();
                            }, 300);
                        }
                        // Keep the live-poll diff in sync: this id was resolved
                        // through the Accept flow, not by an external change,
                        // so it must not be reported as a "withdrawal" the
                        // next time pollNewApplications() runs.
                        _knownAppIds.delete(String(appId));

                        // NEW (endorsement flow): drop the row from the applicants table too.
                        endoRemoveApplicantRow(appId);

                        // ── "Adding Student" UI directly activated ──
                        // Insert the newly registered student straight into the
                        // Registered OJT Students table — no page reload needed.
                        if (res.new_student) {
                            addStudentRow(res.new_student);
                        }

                        // ADJUSTMENT: no success popup after registering — the student appears in
                        // "Registered OJT Students" (errors still show their popup).
                    } else {
                        if (btn) { btn.disabled = false; btn.innerHTML = _origBtnHtml; }
                        showToast(res.message || 'Failed to accept application.', 'error');
                    }
                })
                .catch(function() {
                    if (btn) { btn.disabled = false; btn.innerHTML = _origBtnHtml; }
                    showToast('Network error. Please try again.', 'error');
                })
                .finally(function() { endoHideLoading(_loadStarted); });
        }
    );
}

// ── REJECT MODAL ──────────────────────────────────────────────────────────────
function openRejectModal(appId) {
    document.getElementById('rejectAppId').value      = appId;
    document.getElementById('rejectReasonText').value = '';
    document.getElementById('rejectCharHint').textContent = 'Minimum 10 characters required';
    document.getElementById('rejectSubmitBtn').disabled   = true;
    document.getElementById('rejectModal').style.display  = 'flex';
    setTimeout(() => document.getElementById('rejectReasonText').focus(), 100);
}
function closeRejectModal() {
    document.getElementById('rejectModal').style.display = 'none';
}
document.getElementById('rejectModal').addEventListener('click', function(e) {
    if (e.target === this) closeRejectModal();
});

function updateCharHint(ta) {
    const len  = ta.value.trim().length;
    const hint = document.getElementById('rejectCharHint');
    const btn  = document.getElementById('rejectSubmitBtn');
    if (len < 10) {
        hint.textContent = (10 - len) + ' more character' + (10 - len === 1 ? '' : 's') + ' required';
        hint.style.color = '#e53e3e';
        btn.disabled = true;
    } else {
        hint.textContent = len + ' characters ✓';
        hint.style.color = '#16a34a';
        btn.disabled = false;
    }
}

document.getElementById('rejectForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const appId  = document.getElementById('rejectAppId').value;
    const reason = document.getElementById('rejectReasonText').value.trim();
    const btn    = document.getElementById('rejectSubmitBtn');

    if (reason.length < 10) return;

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending…';

    const fd = new FormData();
    fd.append('reject_app',    '1');
    fd.append('reject_app_id', appId);
    fd.append('reject_reason', reason);

    const _rejLoad = endoShowLoading('Rejecting application'); // loading page
    fetch('add_ojt_student.php', { method: 'POST', body: fd })
        .then(r => r.text())
        .then((rawText) => {
            var rj = null; try { rj = JSON.parse(rawText); } catch (e) {}
            if (rj && rj.blocked) { // NEW (contract gate): the server refused — nothing was rejected
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send &amp; Reject';
                showToast(rj.message || 'This application cannot be rejected yet.', 'error');
                refreshApplicantTable();
                return;
            }
            closeRejectModal();
            const card = document.getElementById('appCard' + appId);
            if (card) {
                card.classList.add('removing');
                setTimeout(() => { card.remove(); updateInboxBadge(-1); checkEmptyInbox(); }, 300);
            }
            // Keep the live-poll diff in sync: this id was resolved through
            // the Reject flow, so the next pollNewApplications() run must
            // not treat its disappearance as a withdrawal.
            _knownAppIds.delete(String(appId));
            endoRemoveApplicantRow(appId); // NEW (endorsement flow)
            showToast('Application rejected. Student has been notified.', 'error');
        })
        .catch(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send &amp; Reject';
            showToast('Network error. Please try again.', 'error');
        })
        .finally(() => endoHideLoading(_rejLoad));
});

// ── INBOX BADGE UPDATER ───────────────────────────────────────────────────────
function updateInboxBadge(delta) {
    const fabBadge     = document.getElementById('inboxFabBadge');
    const sidebarBadge = document.querySelector('.sidebar-badge');
    const headerBadge  = document.querySelector('#inboxHeader h3 span[style*="dc2626"]');
    [fabBadge, sidebarBadge, headerBadge].forEach(el => {
        if (!el) return;
        let current = parseInt(el.textContent) || 0;
        current += delta;
        if (current <= 0) {
            el.style.display = 'none';
            el.textContent = '';
        } else {
            el.textContent = el === headerBadge ? current + ' New' : current;
            el.style.display = el === fabBadge ? 'flex' : 'inline-flex';
        }
    });
}

function checkEmptyInbox() {
    const body = document.getElementById('inboxBody');
    if (body && !body.querySelector('.ar-card')) {
        body.innerHTML = '<div class="ar-empty"><i class="fas fa-inbox"></i>No pending applications.</div>';
    }
}

// ══════════════════════════════════════════════════════════════════════
// LIVE APPLICATION DETECTION — AJAX POLLING (no manual page reload)
// ------------------------------------------------------------
// Keeps track of every application id already rendered/known on this
// page, then periodically asks the server (check_new_applications) for
// anything newer. Any newly-arrived application is built into a real
// ar-card (matching the server-rendered markup/data exactly, via the
// same buildAppCardData() PHP helper) and injected straight into the
// inbox drawer + badges — so the inbox UI is "directly activated" the
// moment a new application comes in, without the user refreshing.
//
// ── FIX: also detect cancellations/withdrawals live ──
// The server now also returns `current_ids` — every application id
// that is STILL pending right now. Diffing that against _knownAppIds
// lets this same poll detect the OTHER direction of change too: an
// application the drawer is currently showing that has stopped being
// pending (withdrawn/cancelled by the student, or otherwise resolved
// outside this session). That card is faded out and removed, the
// badge is decremented, and — if the company happens to have that
// exact application open in the Full View modal — the modal is
// closed automatically so no one can act on a stale application.
// ══════════════════════════════════════════════════════════════════════
const _knownAppIds = new Set();
document.querySelectorAll('#inboxBody .ar-card[data-app-id], #inboxBody .ar-member[data-app-id]').forEach(function(el) { // ADJUSTMENT: batch members
    _knownAppIds.add(String(el.dataset.appId));
});

function buildArCardElement(app) {
    const card = document.createElement('div');
    card.className = 'ar-card just-arrived';
    card.id = 'appCard' + app.id;
    card.dataset.appId      = app.id;
    card.dataset.name       = app.name || '';
    card.dataset.email      = app.email || '';
    card.dataset.course     = app.course || '';
    card.dataset.company    = app.company || '';
    card.dataset.skills     = JSON.stringify(app.skills || []);
    card.dataset.exps       = JSON.stringify(app.exps || []);
    card.dataset.photo      = app.photo || '';
    card.dataset.submitted  = app.submitted || '';
    card.dataset.ojtCount   = app.ojtCount || 0;
    card.dataset.reqDocs    = JSON.stringify(app.reqDocs || []);
    card.dataset.photoStatus = app.photoStatus || 'Pending';

    const nameDiv = document.createElement('div');
    nameDiv.className = 'ar-summary-name';
    nameDiv.textContent = app.name || '';
    card.appendChild(nameDiv);

    const courseDiv = document.createElement('div');
    courseDiv.className = 'ar-summary-course';
    courseDiv.textContent = app.course && app.course.trim() !== '' ? app.course : '—';
    card.appendChild(courseDiv);

    const companyDiv = document.createElement('div');
    companyDiv.className = 'ar-summary-company';
    companyDiv.innerHTML = '<i class="fas fa-building" style="font-size:10px;"></i> ';
    companyDiv.appendChild(document.createTextNode(app.company || ''));
    card.appendChild(companyDiv);

    const countDiv = document.createElement('div');
    countDiv.className = 'ar-summary-count';
    const countNum = parseInt(app.ojtCount) || 0;
    countDiv.innerHTML = '<i class="fas fa-users" style="font-size:10px;"></i> ';
    countDiv.appendChild(document.createTextNode(
        countNum > 0
            ? countNum + ' student' + (countNum !== 1 ? 's' : '') + ' currently registered'
            : 'No students registered yet'
    ));
    card.appendChild(countDiv);

    const actionsDiv = document.createElement('div');
    actionsDiv.className = 'ar-actions';

    // ADJUSTMENT: no Accept / Reject in the inbox — "Move to Table" instead.
    const moveBtn = document.createElement('button');
    moveBtn.className = 'ar-allow-btn';
    moveBtn.innerHTML = '<i class="fas fa-table-list"></i> Move to Table';
    moveBtn.addEventListener('click', function() { moveAppToTable(app.id, moveBtn); });
    actionsDiv.appendChild(moveBtn);

    const fullViewBtn = document.createElement('button');
    fullViewBtn.className = 'ar-fullview-btn';
    fullViewBtn.innerHTML = 'View Skill &amp; Experience'; // ADJUSTMENT: no icon // ADJUSTMENT: renamed from "Full View"
    fullViewBtn.addEventListener('click', function() { openAppFullView(app.id); });
    actionsDiv.appendChild(fullViewBtn);

    card.appendChild(actionsDiv);

    return card;
}

// Transient banner announcing what the live poll just detected — mirrors
// administrator.php's showAppLiveNotice(), reusing the .ar-live-notice
// styles defined above so both add/remove events are visible at a glance
// even if the company isn't looking directly at the list when they land.
function showAppLiveNotice(addedCount, removedCount) {
    const body = document.getElementById('inboxBody');
    if (!body) return;

    const existing = body.querySelector('.ar-live-notice');
    if (existing) existing.remove();

    const parts = [];
    if (addedCount > 0) {
        parts.push({
            cls:  'ar-live-notice-added',
            icon: 'fa-inbox',
            text: addedCount + ' new student application' + (addedCount !== 1 ? 's' : '') + ' received'
        });
    }
    if (removedCount > 0) {
        parts.push({
            cls:  'ar-live-notice-removed',
            icon: 'fa-rotate-left',
            text: removedCount + ' application' + (removedCount !== 1 ? 's' : '') + ' no longer pending'
        });
    }

    parts.forEach(function(p) {
        const notice = document.createElement('div');
        notice.className = 'ar-live-notice ' + p.cls;
        notice.innerHTML = '<i class="fas ' + p.icon + '"></i> ' + p.text;
        body.insertBefore(notice, body.firstChild);
        setTimeout(function() {
            notice.style.opacity = '0';
            setTimeout(function() { notice.remove(); }, 300);
        }, 4000);
    });
}

function pollNewApplications() {
    const fd = new FormData();
    fd.append('check_new_applications', '1');
    fd.append('known_ids', JSON.stringify(Array.from(_knownAppIds)));

    fetch('add_ojt_student.php', { method: 'POST', body: fd })
        .then(function(r) { return r.text(); })
        .then(function(rawText) {
            var res;
            try { res = JSON.parse(rawText); } catch (e) { return; }
            if (!res || !res.success) return;

            const body = document.getElementById('inboxBody');
            if (!body) return;

            const newApps    = Array.isArray(res.new_applications) ? res.new_applications : [];
            const currentIds = Array.isArray(res.current_ids) ? res.current_ids.map(String) : null;

            let addedCount   = 0;
            let removedCount = 0;

            // ── ADDED: brand-new applications since the last poll ──
            if (newApps.length > 0) {
                const emptyState = body.querySelector('.ar-empty');
                if (emptyState) emptyState.remove();

                newApps.forEach(function(app) {
                    const idStr = String(app.id);
                    if (_knownAppIds.has(idStr)) return;
                    _knownAppIds.add(idStr);
                    const card = buildArCardElement(app);
                    body.insertBefore(card, body.firstChild);
                    addedCount++;
                });
            }

            // ── REMOVED: applications the drawer still shows but that are
            //    no longer pending server-side (withdrawn/cancelled by the
            //    student, or resolved through some other session/tab) ──
            if (currentIds) {
                const currentSet = new Set(currentIds);
                Array.from(_knownAppIds).forEach(function(idStr) {
                    if (currentSet.has(idStr)) return;

                    _knownAppIds.delete(idStr);
                    removedCount++;

                    const card = document.getElementById('appCard' + idStr);
                    if (card) {
                        card.classList.add('removing');
                        setTimeout(function() { card.remove(); checkEmptyInbox(); }, 300);
                    }

                    // If the Full View modal is currently open on the
                    // application that just disappeared, close it so the
                    // company can't act on a withdrawn/stale application.
                    if (String(_fvCurrentAppId) === idStr) {
                        closeAppFullView();
                    }
                });
            }

            if (addedCount > 0) {
                updateInboxBadge(addedCount);

                // Draw attention to the inbox icon so the company knows a new
                // application just arrived, without forcing the drawer open.
                const fab = document.getElementById('inboxFab');
                if (fab) {
                    fab.classList.remove('fab-pulse');
                    void fab.offsetWidth; // restart animation if already applied
                    fab.classList.add('fab-pulse');
                }
            }

            if (removedCount > 0) {
                updateInboxBadge(-removedCount);
            }

            if (addedCount > 0 || removedCount > 0) {
                showAppLiveNotice(addedCount, removedCount);
                refreshApplicantTable(); // NEW (endorsement flow): keep the applicants table in step
                refreshInboxGrouped();   // ADJUSTMENT: keep the inbox grouped by batch
            }

            if (addedCount > 0 && removedCount === 0) {
                showToast(
                    addedCount + ' new student application' + (addedCount > 1 ? 's' : '') + ' received.',
                    'success'
                );
            } else if (removedCount > 0 && addedCount === 0) {
                showToast(
                    removedCount + ' application' + (removedCount > 1 ? 's' : '') + ' no longer pending.',
                    'error'
                );
            } else if (addedCount > 0 && removedCount > 0) {
                showToast(
                    addedCount + ' new application' + (addedCount > 1 ? 's' : '') + ', ' +
                    removedCount + ' removed.',
                    'success'
                );
            }
        })
        .catch(function() { /* silent — retried on next interval */ });
}

// Poll every 12 seconds for newly submitted / withdrawn applications.
setInterval(pollNewApplications, 12000);

// ══════════════════════════════════════════════════════════════════════
// FULL VIEW APPLICATION MODAL — LETTERHEAD/DOCUMENT STYLE, PAGINATED
// ------------------------------------------------------------
// Reads applicant data straight from the clicked card's dataset (this
// file renders each application card server-side, unlike administrator.php
// which fetches its cards via AJAX), then builds the paginated letterhead
// document and wires Accept/Reject to this file's existing acceptApp() /
// openRejectModal() flows.
// ══════════════════════════════════════════════════════════════════════
let _fvCurrentAppId = null;

function openAppFullView(appId) {
    // NEW (endorsement flow): the applicants-table row carries the same data-* set as the inbox card.
    const card = document.getElementById('appCard' + appId) || document.getElementById('appRow' + appId);
    if (!card) return;
    _fvCurrentAppId = appId;

    const name        = card.dataset.name        || '';
    const email       = card.dataset.email        || '';
    const course      = card.dataset.course       || '';
    const company     = card.dataset.company      || '';
    const photo       = card.dataset.photo        || '';
    const submitted   = card.dataset.submitted    || '';
    const ojtCount    = parseInt(card.dataset.ojtCount) || 0;
    const photoStatus = card.dataset.photoStatus  || 'Pending';

    let skills = [], exps = [], reqDocs = [];
    try { skills  = JSON.parse(card.dataset.skills  || '[]'); } catch(e){}
    try { exps    = JSON.parse(card.dataset.exps    || '[]'); } catch(e){}
    try { reqDocs = JSON.parse(card.dataset.reqDocs || '[]'); } catch(e){}
    skills = skills.filter(s => s && s.trim());
    exps   = exps.filter(e => e && e.trim());

    const initials = name.split(' ').map(n => n[0]).join('').substring(0,2).toUpperCase();

    document.getElementById('fvToolbarTitle').textContent = name + ' — Application Review';

    const avatarEl = document.getElementById('fv-photo-thumb');
    if (photo) {
        avatarEl.innerHTML = `<img src="data:image/jpeg;base64,${photo}" alt="Photo">`;
        avatarEl.onclick = function () { openPreview(`data:image/jpeg;base64,${photo}`); };
    } else {
        avatarEl.innerHTML = '';
        avatarEl.textContent = initials;
        avatarEl.onclick = null;
    }

    document.getElementById('fv-name-val').textContent   = name;
    document.getElementById('fv-email-val').textContent  = email;
    document.getElementById('fv-course-val').textContent = course;

    document.getElementById('fv-company').innerHTML = `<i class="fas fa-building" style="font-size:10px;"></i> ${escHtml(company)}`;

    document.getElementById('fv-date').textContent = submitted;
    const datePill = document.getElementById('fv-date-pill');
    if (datePill) datePill.innerHTML = submitted ? `<i class="fas fa-calendar-alt" style="font-size:10px;"></i> Applied ${submitted}` : '';

    const ojtPill = document.getElementById('fv-ojt-count-pill');
    if (ojtPill) {
        if (ojtCount > 0) {
            ojtPill.innerHTML = `<i class="fas fa-users" style="font-size:10px;"></i> ${ojtCount} student${ojtCount !== 1 ? 's' : ''} currently registered`;
            ojtPill.style.display = 'inline-flex';
        } else {
            ojtPill.style.display = 'none';
        }
    }

    const photoStatusEl = document.getElementById('fv-photo-status');
    fvSetStatusPill(photoStatusEl, photoStatus);

    document.getElementById('fv-skills').innerHTML = skills.length
        ? skills.map(function (s, idx) {
            return '<div class="fv-entry-item">' +
                       '<div class="fv-field-label">Skill ' + (idx + 1) + '</div>' +
                       '<div class="fv-entry-box">' + escHtml(s) + '</div>' +
                   '</div>';
          }).join('')
        : '<span class="fv-empty-note">No skills listed</span>';

    document.getElementById('fv-exp').innerHTML = exps.length
        ? exps.map(function (e, idx) {
            return '<div class="fv-entry-item">' +
                       '<div class="fv-field-label">Experience ' + (idx + 1) + '</div>' +
                       '<div class="fv-entry-box">' + escHtml(e) + '</div>' +
                   '</div>';
          }).join('')
        : '<span class="fv-empty-note">No experience listed</span>';

    const grid = document.getElementById('fv-docs-grid');
    grid.innerHTML = '<tr class="fv-doc-th"><td>Document</td><td style="width:110px; text-align:center;">Status</td></tr>';
    reqDocs.forEach(function (doc) {
        const statusClass = (doc.status === 'Verified') ? 'verified' : (doc.status === 'Denied') ? 'denied' : 'pending';
        const statusText  = (doc.status === 'Verified') ? '✓ Verified' : (doc.status === 'Denied') ? '✗ Denied' : 'Pending';
        const thumbHtml = doc.file
            ? '<span class="fv-doc-thumb" onclick="openPreview(\'data:image/jpeg;base64,' + doc.file + '\')"><img src="data:image/jpeg;base64,' + doc.file + '"></span>'
            : '<span class="fv-doc-nothumb"><i class="fas fa-file" style="font-size:12px;color:#d1d5db;"></i></span>';

        const row = document.createElement('tr');
        row.innerHTML =
            '<td><div class="fv-doc-row-name">' + thumbHtml + '<span>' + escHtml(doc.label) + '</span></div></td>' +
            '<td style="text-align:center;"><span class="fv-status-chip ' + statusClass + '">' + statusText + '</span></td>';
        grid.appendChild(row);
    });

    // ADJUSTMENT: the Full View no longer has Accept / Reject buttons (removed with its action bar).

    document.getElementById('appFullViewOverlay').classList.add('open');

    // Build/rebuild the paginated A4 pages from the raw source just populated above.
    fvRenderPages();
}

function closeAppFullView() {
    document.getElementById('appFullViewOverlay').classList.remove('open');
    _fvCurrentAppId = null;

    // Clear built pages so the next open always renders fresh.
    const wrap = document.getElementById('fvPagesWrap');
    if (wrap) wrap.innerHTML = '';
}

function fvSetStatusPill(el, status) {
    if (!el) return;
    const cls  = (status === 'Verified') ? 'verified' : (status === 'Denied') ? 'denied' : 'pending';
    const text = (status === 'Verified') ? '✓ Verified' : (status === 'Denied') ? '✗ Denied' : status;
    el.className = 'fv-status-chip fv-badge-photo ' + cls;
    el.textContent = text;
}

document.getElementById('appFullViewOverlay').addEventListener('click', function (e) {
    if (e.target === this) closeAppFullView();
});

// ══════════════════════════════════════════════════════════════
// DIGITAL RESUME / DOCUMENTS — DYNAMIC A4 PAGINATION ENGINE
// (Full View application document)
// ------------------------------------------------------------
// Ported from administrator.php's own resume pagination engine so an
// applicant with a lot of Skills/Experience entries spans however many
// real A4-sized pages that content needs, instead of one endlessly-tall
// document. The "Submitted Documents" table is always rendered as its
// own dedicated, vertically-centered final page.
// ══════════════════════════════════════════════════════════════
const FV_PAGE_W = 794;
const FV_PAGE_H = 1123;
const FV_BODY_TOP_BOTTOM_PAD = 18 + 20; // matches .fv-form-body's top+bottom padding
const FV_SAFETY_BUFFER = 32;            // extra headroom so nothing ever grazes the footer

function fvMeasureContentHeight(el) {
    var sandbox = document.createElement('div');
    sandbox.style.cssText = 'position:fixed;top:0;left:-9999px;visibility:hidden;width:738px;overflow:hidden;';
    sandbox.appendChild(el.cloneNode(true));
    document.body.appendChild(sandbox);
    var h = sandbox.scrollHeight;
    document.body.removeChild(sandbox);
    return h;
}

function fvMeasureFullWidthHeight(el) {
    var sandbox = document.createElement('div');
    sandbox.style.cssText = 'position:fixed;top:0;left:-9999px;visibility:hidden;width:' + FV_PAGE_W + 'px;overflow:hidden;';
    sandbox.appendChild(el.cloneNode(true));
    document.body.appendChild(sandbox);
    var h = sandbox.scrollHeight;
    document.body.removeChild(sandbox);
    return h;
}

function fvAppendSectionBlocks(rawRoot, titleSelector, colSelector, blocks) {
    // Glues a section title together with whichever comes right after it
    // (its first entry, or its "No skills/experience listed" empty note)
    // into a single unit the paginator can never split across a page
    // break, so the title is never stranded alone at the bottom of a page.
    var title = rawRoot.querySelector(titleSelector);
    if (!title) return;

    var col = rawRoot.querySelector(colSelector);
    var children = col ? Array.prototype.slice.call(col.children) : [];

    if (children.length > 0) {
        blocks.push({ type: 'glued', els: [title, children[0]] });
        for (var i = 1; i < children.length; i++) {
            blocks.push({ type: 'atomic', el: children[i] });
        }
    } else {
        blocks.push({ type: 'atomic', el: title });
    }
}

function fvBuildResumeBlocks(rawRoot) {
    var blocks = [];

    var applicantStrip = rawRoot.querySelector('.fv-applicant-strip');
    if (applicantStrip) blocks.push({ type: 'atomic', el: applicantStrip });

    fvAppendSectionBlocks(rawRoot, '.fv-skills-title', '.fv-skills-col', blocks);
    fvAppendSectionBlocks(rawRoot, '.fv-exp-title', '.fv-exp-col', blocks);

    return blocks;
}

function fvPartitionResume(rawRoot, usableH) {
    var blocks  = fvBuildResumeBlocks(rawRoot);
    var pages   = [[]];
    var pageIdx = 0;
    var curH    = 0;

    function breakPageIfNeeded(h) {
        if (curH + h > usableH && pages[pageIdx].length > 0) {
            pageIdx++;
            pages[pageIdx] = [];
            curH = 0;
        }
    }

    blocks.forEach(function(block) {
        if (block.type === 'atomic') {
            var h = fvMeasureContentHeight(block.el);
            breakPageIfNeeded(h);
            pages[pageIdx].push(block.el.cloneNode(true));
            curH += h;
            return;
        }

        if (block.type === 'glued') {
            var combinedWrap = document.createElement('div');
            block.els.forEach(function(e) { combinedWrap.appendChild(e.cloneNode(true)); });
            var gh = fvMeasureContentHeight(combinedWrap);
            breakPageIfNeeded(gh);
            block.els.forEach(function(e) { pages[pageIdx].push(e.cloneNode(true)); });
            curH += gh;
        }
    });

    pages = pages.filter(function(p) { return p.length > 0; });
    if (pages.length === 0) pages = [[]];
    return pages;
}

function fvBuildDocPageBody(rawRoot) {
    var wrap = document.createElement('div');
    wrap.className = 'fv-doc-page-inner';

    var docTitle = rawRoot.querySelector('.fv-doc-title');
    if (docTitle) wrap.appendChild(docTitle.cloneNode(true));

    var docTable = rawRoot.querySelector('.fv-doc-table');
    if (docTable) wrap.appendChild(docTable.cloneNode(true));

    return wrap;
}

function fvRenderPages() {
    var wrap = document.getElementById('fvPagesWrap');
    var raw  = document.getElementById('fvRawSource');
    if (!wrap || !raw) return;

    var headerClone = document.getElementById('fvHeaderClone');
    var footerClone = document.getElementById('fvFooterClone');

    // Wait for web fonts to finish loading before measuring anything.
    document.fonts.ready.then(function() {
        var HDR_H    = fvMeasureFullWidthHeight(headerClone) || 120;
        var FTR_H    = fvMeasureFullWidthHeight(footerClone) || 24;
        var USABLE_H = FV_PAGE_H - HDR_H - FTR_H - FV_BODY_TOP_BOTTOM_PAD - FV_SAFETY_BUFFER;

        var resumePages = fvPartitionResume(raw, USABLE_H);

        var docBody       = fvBuildDocPageBody(raw);
        var docBodyHeight = fvMeasureContentHeight(docBody);
        var docFits       = docBodyHeight <= USABLE_H;

        var totalPages = resumePages.length + 1;

        wrap.innerHTML = '';

        function buildPaper(pageNum, isDocPage, content) {
            var paper = document.createElement('div');
            paper.className = 'fv-doc-paper';

            var hdr = document.createElement('div');
            hdr.innerHTML = headerClone.innerHTML;
            var metaEl = hdr.querySelector('.fv-form-meta');
            if (metaEl) metaEl.textContent = 'Student Application Request \u2014 Full View \u2014 Page ' + pageNum + ' of ' + totalPages;
            paper.appendChild(hdr);

            var body = document.createElement('div');
            body.className = 'fv-form-body' + (isDocPage ? (docFits ? ' fv-doc-page-body' : ' fv-doc-page-fallback') : '');
            if (isDocPage) {
                body.appendChild(content);
            } else {
                content.forEach(function(node) { body.appendChild(node); });
            }
            paper.appendChild(body);

            var ftr = document.createElement('div');
            ftr.innerHTML = footerClone.innerHTML;
            paper.appendChild(ftr);

            wrap.appendChild(paper);
        }

        resumePages.forEach(function(nodes, i) {
            buildPaper(i + 1, false, nodes);
        });
        buildPaper(totalPages, true, docBody);
    });
}

// ── REMOVE STUDENT — AJAX (no page reload) ───────────────────────────────────
function checkEmptyStudentTable() {
    const tbody = document.getElementById('studentTableBody');
    if (!tbody) return;
    const rows = tbody.querySelectorAll('tr:not(.removing)');
    if (rows.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="5" class="table-empty-state">
                    <i class="fas fa-user-graduate"></i>
                    No registered OJT students yet.
                </td>
            </tr>`;
    }
}

// ── ADD STUDENT ROW — used when an application is Accepted ─────────────────
// Builds and inserts a new row into the Registered OJT Students table using
// the data the accept_app AJAX handler returns, so the "Add Student" UI is
// directly activated the moment an application is accepted — no reload.
function addStudentRow(student) {
    const tbody = document.getElementById('studentTableBody');
    if (!tbody) return;

    // Remove the "No registered OJT students yet" placeholder row, if present.
    const emptyCell = tbody.querySelector('.table-empty-state');
    if (emptyCell) {
        const emptyRow = emptyCell.closest('tr');
        if (emptyRow) emptyRow.remove();
    }

    // Avoid duplicating a row if this student is somehow already listed.
    const existing = document.getElementById('studentRow_' + student.ojt_id);
    if (existing) existing.remove();

    const fullName = (student.first_name + ' ' + student.last_name).trim();

    const tr = document.createElement('tr');
    tr.id = 'studentRow_' + student.ojt_id;
    tr.classList.add('just-added');

    const tdPhoto = document.createElement('td');
    if (student.photo) {
        const img = document.createElement('img');
        img.className = 'student-photo';
        img.src = 'data:image/jpeg;base64,' + student.photo;
        tdPhoto.appendChild(img);
    } else {
        const placeholder = document.createElement('div');
        placeholder.style.cssText = 'width:45px;height:45px;background:#eee;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:10px;';
        placeholder.textContent = 'N/A';
        tdPhoto.appendChild(placeholder);
    }
    tr.appendChild(tdPhoto);

    const tdName = document.createElement('td');
    tdName.style.fontWeight = '600';
    tdName.textContent = fullName;
    tr.appendChild(tdName);

    const tdEmail = document.createElement('td');
    tdEmail.textContent = student.email || '';
    tr.appendChild(tdEmail);

    const tdStatus = document.createElement('td');
    const statusBadge = document.createElement('span');
    statusBadge.className = 'status-badge';
    statusBadge.textContent = student.deploy_status || 'Deployed';
    tdStatus.appendChild(statusBadge);
    tr.appendChild(tdStatus);

    const tdAction = document.createElement('td');
    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'btn-remove';
    removeBtn.dataset.ojtId = student.ojt_id;
    removeBtn.dataset.studentName = fullName;
    removeBtn.textContent = 'Remove';
    tdAction.appendChild(removeBtn);
    tr.appendChild(tdAction);

    // Newest registered student appears at the top of the table.
    tbody.insertBefore(tr, tbody.firstChild);
}

document.getElementById('studentTableBody').addEventListener('click', function(e) {
    const btn = e.target.closest('button.btn-remove');
    if (!btn) return;

    const ojtId      = btn.dataset.ojtId;
    const studentName = btn.dataset.studentName || 'this student';

    showConfirmPopup(
        'Remove Student',
        'Are you sure you want to remove ' + studentName + ' from your OJT program?',
        'danger',
        function() {
            // Optimistically disable the button while the request is in-flight
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

            const fd = new FormData();
            fd.append('remove_student', '1');
            fd.append('ojt_id', ojtId);

            const _rmLoad = endoShowLoading('Removing student'); // loading page
            fetch('add_ojt_student.php', { method: 'POST', body: fd })
                .then(function(response) { return response.text(); })
                .then(function(rawText) {
                    var res = {};
                    try { res = JSON.parse(rawText); } catch(e) {
                        showToast('Server error. Please try again.', 'error');
                        btn.disabled = false;
                        btn.innerHTML = 'Remove';
                        return;
                    }
                    if (res.success) {
                        // Fade out and remove the entire table row
                        const row = document.getElementById('studentRow_' + ojtId);
                        if (row) {
                            row.classList.add('removing');
                            setTimeout(function() {
                                row.remove();
                                checkEmptyStudentTable();
                            }, 350);
                        }
                        // ADJUSTMENT: shown as the application-request popup (navy top bar, .cv-top-toast).
                        aplShowTopToast(studentName, 'has been removed from your OJT program.', 'fa-user-minus');
                    } else {
                        btn.disabled = false;
                        btn.innerHTML = 'Remove';
                        showToast(res.message || 'Failed to remove student. Please try again.', 'error');
                    }
                })
                .catch(function() {
                    btn.disabled = false;
                    btn.innerHTML = 'Remove';
                    showToast('Network error. Please try again.', 'error');
                })
                .finally(function() { endoHideLoading(_rmLoad); });
        }
    );
});

// ══════════════════════════════════════════════════════════════════════
// NEW (endorsement flow): OJT APPLICANTS — ENDORSEMENT LETTER VALIDATION
// ------------------------------------------------------------
// • Preview Resume  → the existing Full View (Digital Resume) modal
// • View Uploaded   → full-screen viewer of the student's signed letter
// • Verify          → acceptApp() (verifies the letter + registers the student)
// • Reject          → remarks are required; the student can re-upload
// The table re-renders server-side whenever something changes (student
// uploads, another tab validates, admin approves a new application).
// ══════════════════════════════════════════════════════════════════════
function verifyEndorsement(appId, btn, name) {
    acceptApp(appId, btn, {
        title: 'Verify Endorsement Letter',
        msg:   'Mark ' + (name || 'this student') + '\u2019s endorsement letter as Verified and register the student to your company?',
        busy:  'Verifying…',
        loading: 'Verifying endorsement letter'
    });
}

function endoRemoveApplicantRow(appId) {
    const row = document.getElementById('appRow' + appId);
    if (row) {
        row.classList.add('removing');
        setTimeout(function() { row.remove(); endoCheckEmptyApplicants(); }, 350);
    }
    setTimeout(function() { refreshApplicantTable(); }, 600);
}

function endoCheckEmptyApplicants() {
    const tbody = document.getElementById('applicantTableBody');
    if (!tbody) return;
    const count = tbody.querySelectorAll('tr.applicant-row:not(.removing)').length;
    const badge = document.getElementById('applicantCount');
    if (badge) { badge.textContent = count; badge.style.display = count > 0 ? 'inline-flex' : 'none'; }
    if (count === 0 && !tbody.querySelector('.apl-empty-row')) {
        tbody.innerHTML = '<tr class="apl-empty-row"><td colspan="6" class="table-empty-state"><i class="fas fa-envelope-open-text"></i>No applicants yet. Applications approved by the administrator appear here.</td></tr>';
    }
}

let _endoRefreshBusy = false;
function refreshApplicantTable() {
    const table = document.getElementById('applicantTable');
    const tbody = document.getElementById('applicantTableBody');
    if (!table || !tbody || _endoRefreshBusy) return;
    // ADJUSTMENT: don't re-draw over a validation dropdown the company has changed but not saved yet.
    if (tbody.querySelector('form.endo-val-form.dirty')) return;
    _endoRefreshBusy = true;
    const fd = new FormData();
    fd.append('check_applicants_table', '1');
    fd.append('sig', table.dataset.sig || '');
    return fetch('add_ojt_student.php', { method: 'POST', body: fd }) // ADJUSTMENT: returned so callers can wait for it
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (!res || !res.success || !res.changed) return;
            // Remember which rows changed status so they can be highlighted.
            const before = {};
            tbody.querySelectorAll('tr.applicant-row').forEach(function(tr) { before[tr.id] = tr.dataset.endoStatus || ''; });
            tbody.innerHTML = res.html || '';
            table.dataset.sig = res.sig || '';
            tbody.querySelectorAll('tr.applicant-row').forEach(function(tr) {
                if (!(tr.id in before) || before[tr.id] !== (tr.dataset.endoStatus || '')) tr.classList.add('row-updated');
            });
            const badge = document.getElementById('applicantCount');
            if (badge) { badge.textContent = res.count; badge.style.display = res.count > 0 ? 'inline-flex' : 'none'; }
            // If the Full View is open on an applicant who is no longer listed, close it.
            if (_fvCurrentAppId !== null && !document.getElementById('appRow' + _fvCurrentAppId) && !document.getElementById('appCard' + _fvCurrentAppId)) {
                closeAppFullView();
            }
        })
        .catch(function() { /* silent — retried on next interval */ })
        .finally(function() { _endoRefreshBusy = false; });
}
setInterval(refreshApplicantTable, 5000); // FIX: 5 s (was 10 s) so newly approved applications appear promptly

// ── Full-screen viewer: the issued letter or the student's uploaded copy ──
function openEndoViewer(endoId, mode, appId, name, status) {
    const src = mode === 'upload'
        ? 'add_ojt_student.php?view_endorsement_upload=' + encodeURIComponent(endoId) + '&t=' + Date.now()
        : 'add_ojt_student.php?view_endorsement_letter=' + encodeURIComponent(endoId);
    document.getElementById('endoViewerTitle').textContent =
        (mode === 'upload' ? 'Uploaded Endorsement Letter' : 'Issued Endorsement Letter') + (name ? ' — ' + name : '');
    document.getElementById('endoViewerFrame').src = src;
    document.getElementById('endoViewerNewTab').href = src;

    // ADJUSTMENT: the uploaded-letter preview carries the Remark dropdown + Save (no Verify / Reject).
    const canRemark = (mode === 'upload' && (status === 'Pending' || status === 'Verified'));
    const wrap = document.getElementById('endoViewerRemarkWrap');
    const sel  = document.getElementById('endoViewerRemark');
    wrap.style.display = canRemark ? 'inline-flex' : 'none';
    sel.value = '';
    sel.classList.remove('needs-reason');
    document.getElementById('endoViewerRemarkSave').onclick = function () { endoSaveRemarkFromViewer(appId, name); };

    document.getElementById('endoViewerOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeEndoViewer() {
    document.getElementById('endoViewerOverlay').classList.remove('open');
    document.getElementById('endoViewerFrame').src = 'about:blank';
    document.body.style.overflow = '';
}

// ── Reject with remarks ──
let _endoRejectAppId = null;
function openEndoRejectModal(appId, name) {
    _endoRejectAppId = appId;
    document.getElementById('endoRejectName').textContent = name || 'the student';
    const ta = document.getElementById('endoRejectRemark');
    ta.value = '';
    endoUpdateRejectHint();
    const btn = document.getElementById('endoRejectSubmit');
    btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send &amp; Reject';
    document.getElementById('endoRejectModal').style.display = 'flex';
    setTimeout(function() { ta.focus(); }, 100);
}
function closeEndoRejectModal() {
    document.getElementById('endoRejectModal').style.display = 'none';
    _endoRejectAppId = null;
}
function endoUpdateRejectHint() {
    const len  = document.getElementById('endoRejectRemark').value.trim().length;
    const hint = document.getElementById('endoRejectHint');
    const btn  = document.getElementById('endoRejectSubmit');
    if (len < 5) {
        hint.textContent = (5 - len) + ' more character' + (5 - len === 1 ? '' : 's') + ' required';
        hint.style.color = len === 0 ? '#a0aec0' : '#e53e3e';
        btn.disabled = true;
    } else {
        hint.textContent = len + ' characters ✓';
        hint.style.color = '#16a34a';
        btn.disabled = false;
    }
}
document.getElementById('endoRejectRemark').addEventListener('input', endoUpdateRejectHint);
document.querySelectorAll('#endoRejectModal .endo-quick-remarks button').forEach(function(b) {
    b.addEventListener('click', function() {
        const ta = document.getElementById('endoRejectRemark');
        ta.value = ta.value.trim() ? ta.value.trim() + ' ' + b.dataset.remark : b.dataset.remark;
        endoUpdateRejectHint();
        ta.focus();
    });
});
document.getElementById('endoRejectModal').addEventListener('click', function(e) {
    if (e.target === this) closeEndoRejectModal();
});
function submitEndoReject() {
    if (_endoRejectAppId === null) return;
    const appId  = _endoRejectAppId;
    const remark = document.getElementById('endoRejectRemark').value.trim();
    if (remark.length < 5) return;
    const btn = document.getElementById('endoRejectSubmit');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending…';

    const fd = new FormData();
    fd.append('reject_endorsement', '1');
    fd.append('app_id', String(appId));
    fd.append('remark', remark);
    const _erLoad = endoShowLoading('Rejecting endorsement letter'); // loading page
    fetch('add_ojt_student.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res && res.success) {
                closeEndoRejectModal();
                showToast(res.message || 'Endorsement letter rejected.', 'error');
                refreshApplicantTable();
            } else {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send &amp; Reject';
                showToast((res && res.message) || 'Failed to reject the letter.', 'error');
            }
        })
        .catch(function() {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send &amp; Reject';
            showToast('Network error. Please try again.', 'error');
        })
        .finally(function() { endoHideLoading(_erLoad); });
}

document.addEventListener('keydown', function(e) {
    if (e.key !== 'Escape') return;
    if (document.getElementById('endoRejectModal').style.display === 'flex') { closeEndoRejectModal(); return; }
    if (document.getElementById('endoViewerOverlay').classList.contains('open')) closeEndoViewer();
});

// ══════════════════════════════════════════════════════════════════════
// ADJUSTMENT: ENDORSEMENT LETTER VALIDATION — dropdown + Save + undo toast
// (same pattern as the administrator's requirement validation)
// ══════════════════════════════════════════════════════════════════════
function endoToggleReason(sel) {
    const form = sel.closest('form');
    const reason = form.querySelector('.endo-reason');
    reason.style.display = sel.value === 'Rejected' ? 'inline-block' : 'none';
    reason.classList.remove('needs-reason');
    form.classList.add('dirty');
}

(function () {
    const tbody = document.getElementById('applicantTableBody');
    if (!tbody) return;
    tbody.addEventListener('change', function (e) {
        const form = e.target.closest('form.endo-val-form');
        if (form) form.classList.add('dirty');
    });
    tbody.addEventListener('submit', function (e) {
        const form = e.target.closest('form.endo-val-form');
        if (!form) return;
        e.preventDefault();
        endoSaveValidation(form);
    });
})();

function endoSaveValidation(form) {
    const appId  = form.dataset.appId;
    const status = form.querySelector('select[name="status"]').value;
    const reason = form.querySelector('select[name="remark"]');
    const btn    = form.querySelector('button[type="submit"]');
    if (status === 'Rejected' && !reason.value) {
        reason.classList.add('needs-reason');
        reason.focus();
        showToast('Please choose a reason for rejecting the letter.', 'error');
        return;
    }
    btn.disabled = true;
    btn.classList.add('saving');
    btn.textContent = 'Saving…';

    const fd = new FormData();
    fd.append('endo_validate', '1');
    fd.append('app_id', appId);
    fd.append('status', status);
    fd.append('remark', status === 'Rejected' ? reason.value : '');
    const _evLoad = endoShowLoading('Saving validation'); // loading page
    fetch('add_ojt_student.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            btn.classList.remove('saving');
            if (res && res.success) {
                btn.classList.add('saved');
                btn.textContent = 'Saved ✓';
                form.classList.remove('dirty');
                startEndoUndoToast(res.undo_token, res.undo_label, res.status === 'Rejected', res.undo_secs);
                setTimeout(refreshApplicantTable, 500);
            } else {
                btn.disabled = false;
                btn.textContent = 'Save';
                showToast((res && res.message) || 'Failed to save. Please try again.', 'error');
            }
        })
        .catch(function () {
            btn.disabled = false;
            btn.classList.remove('saving');
            btn.textContent = 'Save';
            showToast('Network error. Please try again.', 'error');
        })
        .finally(function () { endoHideLoading(_evLoad); });
}

// Used by the letter viewer's Verify / Reject buttons.
function endoQuickSet(appId, status) {
    const form = document.querySelector('#appRow' + appId + ' form.endo-val-form');
    if (!form) return;
    const sel = form.querySelector('select[name="status"]');
    sel.value = status;
    endoToggleReason(sel);
    form.closest('tr').scrollIntoView({ behavior: 'smooth', block: 'center' });
    if (status === 'Rejected') { form.querySelector('.endo-reason').focus(); return; } // a reason is needed
    endoSaveValidation(form);
}

// ── UNDO TOAST (mirrors administrator.php's startUndoToast / dismissUndo / triggerUndo) ──
const ENDO_UNDO_DURATION = 300;
const endoRing = document.getElementById('undoRingProgress');
const ENDO_RING_CIRC = 88;
let endoUndoToken = null, endoUndoCountdown = 0, endoUndoTotal = ENDO_UNDO_DURATION, endoUndoTimer = null;

function endoCommit(token, beacon) {
    if (!token) return;
    const fd = new FormData();
    fd.append('endo_commit', '1');
    fd.append('undo_token', token);
    if (beacon && navigator.sendBeacon) { navigator.sendBeacon('add_ojt_student.php', fd); return; }
    fetch('add_ojt_student.php', { method: 'POST', body: fd }).catch(function () {});
}

function startEndoUndoToast(token, label, isRejected, secs) {
    if (endoUndoToken && endoUndoToken !== token) endoCommit(endoUndoToken); // previous window closes
    endoUndoToken = token;
    endoUndoTotal = secs || ENDO_UNDO_DURATION;
    endoUndoCountdown = endoUndoTotal;
    document.getElementById('undoToastLabel').textContent = label;
    const btn = document.getElementById('undoBtnMain');
    btn.disabled = false; btn.textContent = '↩ Undo';
    const statusEl = document.getElementById('undoToastStatus');
    const numEl = document.getElementById('undoCountNum');
    const toast = document.getElementById('undoToast');
    statusEl.textContent = isRejected ? ' Rejected pending — undo to cancel' : 'Status updated';
    statusEl.classList.toggle('denied-mode', !!isRejected);
    endoRing.classList.toggle('denied-ring', !!isRejected);
    numEl.classList.toggle('denied-num', !!isRejected);
    toast.classList.toggle('denied-pending', !!isRejected);
    toast.classList.add('show');
    clearInterval(endoUndoTimer);
    endoUpdateRing();
    endoUndoTimer = setInterval(function () {
        endoUndoCountdown--;
        endoUpdateRing();
        if (endoUndoCountdown <= 0) dismissEndoUndo(true);
    }, 1000);
}

function endoUpdateRing() {
    endoRing.style.strokeDashoffset = ENDO_RING_CIRC * (1 - endoUndoCountdown / endoUndoTotal);
    const m = Math.floor(endoUndoCountdown / 60), s = endoUndoCountdown % 60;
    document.getElementById('undoCountNum').textContent = m + ':' + String(s).padStart(2, '0');
}

function dismissEndoUndo(commit) {
    clearInterval(endoUndoTimer);
    const toast = document.getElementById('undoToast');
    toast.classList.remove('show', 'denied-pending');
    endoRing.classList.remove('denied-ring');
    document.getElementById('undoCountNum').classList.remove('denied-num');
    document.getElementById('undoToastStatus').classList.remove('denied-mode');
    if (commit && endoUndoToken) endoCommit(endoUndoToken);
    endoUndoToken = null;
}

function triggerEndoUndo() {
    if (!endoUndoToken) return;
    const btn = document.getElementById('undoBtnMain');
    btn.disabled = true; btn.textContent = '…';
    const started = endoShowLoading('Undoing change'); // ADJUSTMENT: loading page
    const fd = new FormData();
    fd.append('endo_undo', '1');
    fd.append('undo_token', endoUndoToken);
    fetch('add_ojt_student.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            dismissEndoUndo(false);
            if (res && res.success) {
                // ADJUSTMENT: no "Change undone." notification — the loading page covers the undo
                // until the table has been re-drawn with the restored letter.
                return Promise.resolve(refreshApplicantTable()).then(function () { endoHideLoading(started); });
            }
            endoHideLoading(started);
            showToast((res && res.message) || 'Undo failed.', 'error');
            refreshApplicantTable();
        })
        .catch(function () {
            endoHideLoading(started);
            btn.disabled = false; btn.textContent = '↩ Undo';
            showToast('Network error during undo.', 'error');
        });
}

/* ADJUSTMENT: loading page helpers (administrator.php look). Shown at least ENDO_LOADING_MIN_MS
   so it never just flashes. */
var ENDO_LOADING_MIN_MS = 600;
function endoShowLoading(label) {
    showGlobalLoading(label);   // the one shared loading page (counter based — overlapping actions never stack a second one)
    return Date.now();
}
function endoHideLoading(startedAt) {
    var wait = Math.max(0, ENDO_LOADING_MIN_MS - (Date.now() - (startedAt || 0)));
    setTimeout(hideGlobalLoading, wait);
}

// Leaving the page closes the undo window (a held rejection email is sent).
window.addEventListener('pagehide', function () { if (endoUndoToken) endoCommit(endoUndoToken, true); });

// Commit any expired undo windows (e.g. the page was closed earlier) — like the admin's flush.
(function () {
    const fd = new FormData();
    fd.append('endo_flush', '1');
    fetch('add_ojt_student.php', { method: 'POST', body: fd }).catch(function () {});
})();

// ══════════════════════════════════════════════════════════════════════
// ADJUSTMENT: PAGINATION — OJT Applicants table (same controls/logic as
// administrator.php's renderPage / changePage / renderPaginationUI).
// 5 applicants per page. A MutationObserver re-paginates whenever rows are
// added/removed (accepted, rejected, live refresh), so the page fills up
// again automatically and a page that becomes empty steps back.
// ══════════════════════════════════════════════════════════════════════
var APL_ROWS_PER_PAGE = 5;
var aplCurrentPage = 1;

function aplRows() {
    var tb = document.getElementById('applicantTableBody');
    return tb ? Array.prototype.slice.call(tb.querySelectorAll('tr.applicant-row:not(.removing)')) : [];
}

function aplRenderPage() {
    var rows = aplRows();
    var totalPages = Math.max(1, Math.ceil(rows.length / APL_ROWS_PER_PAGE));
    aplCurrentPage = Math.min(Math.max(aplCurrentPage, 1), totalPages);
    var start = (aplCurrentPage - 1) * APL_ROWS_PER_PAGE, end = start + APL_ROWS_PER_PAGE;
    rows.forEach(function (row, i) { row.style.display = (i >= start && i < end) ? '' : 'none'; });
    aplRenderPaginationUI(totalPages, rows.length);
    if (typeof aplMergeBatchCells === 'function') aplMergeBatchCells(); // ADJUSTMENT: shared cells per batch on this page
}

function aplChangePage(page) {
    var totalPages = Math.max(1, Math.ceil(aplRows().length / APL_ROWS_PER_PAGE));
    if (page < 1 || page > totalPages) return;
    aplCurrentPage = page;
    aplRenderPage();
    var table = document.getElementById('applicantTable');
    var card = table ? table.closest('.card') : null;
    if (card) card.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function aplRenderPaginationUI(totalPages, totalCount) {
    var ctrl = document.getElementById('aplPaginationControls');
    if (!ctrl) return;
    ctrl.style.display = (totalPages <= 1 && totalCount <= APL_ROWS_PER_PAGE) ? 'none' : 'flex';
    document.getElementById('aplPgPrev').disabled = (aplCurrentPage === 1);
    document.getElementById('aplPgNext').disabled = (aplCurrentPage === totalPages);

    var pages = [];
    if (totalPages <= 7) {
        for (var i = 1; i <= totalPages; i++) pages.push(i);
    } else {
        pages.push(1);
        if (aplCurrentPage > 3) pages.push('…');
        for (var j = Math.max(2, aplCurrentPage - 1); j <= Math.min(totalPages - 1, aplCurrentPage + 1); j++) pages.push(j);
        if (aplCurrentPage < totalPages - 2) pages.push('…');
        pages.push(totalPages);
    }
    var numbers = document.getElementById('aplPgNumbers');
    numbers.innerHTML = '';
    pages.forEach(function (p) {
        if (p === '…') {
            var dot = document.createElement('span'); dot.textContent = '…'; dot.className = 'pg-ellipsis'; numbers.appendChild(dot);
        } else {
            var btn = document.createElement('button');
            btn.type = 'button'; btn.textContent = p;
            btn.className = 'pg-number-btn' + (p === aplCurrentPage ? ' active' : '');
            if (p !== aplCurrentPage) btn.addEventListener('click', function () { aplChangePage(p); });
            numbers.appendChild(btn);
        }
    });
    var s = totalCount === 0 ? 0 : (aplCurrentPage - 1) * APL_ROWS_PER_PAGE + 1;
    var e = Math.min(aplCurrentPage * APL_ROWS_PER_PAGE, totalCount);
    document.getElementById('aplPgInfo').textContent = totalCount > 0
        ? 'Showing ' + s + '–' + e + ' of ' + totalCount + ' applicant' + (totalCount === 1 ? '' : 's')
        : 'No applicants';
}

(function () {
    var tb = document.getElementById('applicantTableBody');
    if (!tb) return;
    aplRenderPage();
    // Re-paginate whenever rows change (accept / reject / live refresh re-render).
    if (window.MutationObserver) new MutationObserver(function () { aplRenderPage(); }).observe(tb, { childList: true });
    // A row that starts its removal animation frees its slot right away.
    tb.addEventListener('transitionstart', function (e) {
        if (e.target && e.target.classList && e.target.classList.contains('removing')) aplRenderPage();
    });
})();

// ══════════════════════════════════════════════════════════════════════
// ADJUSTMENT: REMARK FROM THE UPLOADED-LETTER PREVIEW
// Remark dropdown + Save → confirmation popup (Proceed / Cancel) →
// the remark is saved and the student's uploaded letter is deleted
// entirely (existing reject-and-delete path, with the 5-minute undo).
// ══════════════════════════════════════════════════════════════════════
function endoSaveRemarkFromViewer(appId, name) {
    const sel = document.getElementById('endoViewerRemark');
    const remark = sel.value;
    if (!remark) {
        sel.classList.add('needs-reason');
        sel.focus();
        showToast('Please choose a remark first.', 'error');
        return;
    }
    sel.classList.remove('needs-reason');
    document.getElementById('confirmPopup').style.zIndex = '10040'; // above the full-screen preview
    showConfirmPopup(
        'Send remark and delete the uploaded letter?',
        (name || 'The student') + '\u2019s uploaded endorsement letter will be deleted entirely, and the remark \u201c' + remark +
        '\u201d will be sent so they can upload a corrected letter. You can still undo this for 5 minutes.',
        'proceed',
        function () {
            const btn = document.getElementById('endoViewerRemarkSave');
            btn.disabled = true; btn.textContent = 'Saving…';
            const fd = new FormData();
            fd.append('endo_validate', '1');
            fd.append('app_id', String(appId));
            fd.append('status', 'Rejected');
            fd.append('remark', remark);
            const _rkLoad = endoShowLoading('Sending remark'); // loading page
            fetch('add_ojt_student.php', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    btn.disabled = false; btn.textContent = 'Save';
                    if (res && res.success) {
                        closeEndoViewer();
                        startEndoUndoToast(res.undo_token, res.undo_label, true, res.undo_secs);
                        document.getElementById('undoToastStatus').textContent = 'Remark sent — undo to cancel';
                        setTimeout(refreshApplicantTable, 300);
                    } else {
                        showToast((res && res.message) || 'Failed to save the remark. Please try again.', 'error');
                    }
                })
                .catch(function () {
                    btn.disabled = false; btn.textContent = 'Save';
                    showToast('Network error. Please try again.', 'error');
                })
                .finally(function () { endoHideLoading(_rkLoad); });
        }
    );
}
document.getElementById('endoViewerRemark').addEventListener('change', function () { this.classList.remove('needs-reason'); });
// Escape closes the confirmation first when it sits over the preview.
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && document.getElementById('confirmPopup').style.display === 'flex') {
        e.stopImmediatePropagation();
        closeConfirmPopup();
    }
}, true);

// ══════════════════════════════════════════════════════════════════════
// ADJUSTMENT: MOVE AN INBOX APPLICATION INTO THE APPLICANTS TABLE
// (applications from admin_monitoring_dashboard.php wait in the inbox)
// ══════════════════════════════════════════════════════════════════════
function moveAppToTable(appId, btn) {
    if (btn) btn.disabled = true;
    const started = endoShowLoading('Moving to table');
    const fd = new FormData();
    fd.append('move_app_to_table', '1');
    fd.append('app_id', String(appId));
    fetch('add_ojt_student.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res && res.success) {
                const card = document.getElementById('appCard' + appId);
                _knownAppIds.delete(String(appId));
                if (card) { card.classList.add('removing'); setTimeout(function () { card.remove(); checkEmptyInbox(); }, 300); }
                return Promise.resolve(refreshApplicantTable()).then(function () { endoHideLoading(started); });
            }
            if (btn) btn.disabled = false;
            endoHideLoading(started);
            showToast((res && res.message) || 'Could not move the application.', 'error');
        })
        .catch(function () {
            if (btn) btn.disabled = false;
            endoHideLoading(started);
            showToast('Network error. Please try again.', 'error');
        });
}

// ══════════════════════════════════════════════════════════════════════
// NEW (schedule change): the supervisor sets a new Day / Evening Schedule for an applicant.
//   Edit → "Set Up New Schedule" popup (days + reason) → Continue → confirmation popup →
//   server updates the schedule, removes the Student/University Contract and e-mails the
//   student and the administrator (update_student_schedule handler).
// ══════════════════════════════════════════════════════════════════════
var SCHED_DAYS = [['M','Mon'],['T','Tue'],['W','Wed'],['Th','Thu'],['F','Fri']];
var _schedApp = null; // {id, name, day, eve}
var _schedBusy = false;

function schedParse(v) { // "MWTh" -> ['M','W','Th'], "None" -> ['None'], anything else -> []
    v = String(v || '').trim();
    if (v.toLowerCase() === 'none') return ['None'];
    var m = v.match(/Th|M|T|W|F/gi) || [];
    if (m.join('').length !== v.length) return []; // legacy free text
    return m.map(function (x) { return x.charAt(0).toUpperCase() + x.slice(1).toLowerCase(); });
}
function schedLabel(v) {
    var p = schedParse(v);
    if (!p.length) return v ? String(v) : 'Not set';
    if (p[0] === 'None') return 'None';
    var names = {}; SCHED_DAYS.forEach(function (d) { names[d[0]] = d[1]; });
    return p.map(function (a) { return names[a] || a; }).join(', ');
}
function schedBuildChips(boxId, group, checked) {
    var box = document.getElementById(boxId);
    var html = SCHED_DAYS.concat([['None','None']]).map(function (d) {
        return '<label class="sched-chip"><input type="checkbox" data-group="' + group + '" value="' + d[0] + '"' + (checked.indexOf(d[0]) !== -1 ? ' checked' : '') + '><span>' + d[1] + '</span></label>';
    }).join('');
    box.innerHTML = html;
    box.querySelectorAll('input').forEach(function (cb) {
        cb.addEventListener('change', function () {
            if (cb.value === 'None' && cb.checked) box.querySelectorAll('input').forEach(function (o) { if (o !== cb) o.checked = false; });
            else if (cb.value !== 'None' && cb.checked) { var n = box.querySelector('input[value="None"]'); if (n) n.checked = false; }
            schedValidate();
        });
    });
}
function schedCollect(boxId) { // "MWF" / "None" / ""
    var box = document.getElementById(boxId), none = box.querySelector('input[value="None"]');
    if (none && none.checked) return 'None';
    return Array.prototype.map.call(box.querySelectorAll('input:checked'), function (c) { return c.value; })
        .sort(function (a, b) { return SCHED_DAYS.map(function (d) { return d[0]; }).indexOf(a) - SCHED_DAYS.map(function (d) { return d[0]; }).indexOf(b); }).join('');
}
function schedNorm(v) { var p = schedParse(v); return p.length ? (p[0] === 'None' ? 'None' : p.join('')) : String(v || '').trim(); }

function openSchedModal(appId) {
    var row = document.getElementById('appRow' + appId);
    if (!row) { showToast('This applicant is no longer in the table.', 'error'); return; }
    _schedApp = { id: appId, name: row.dataset.name || 'Student', day: row.dataset.daySched || '', eve: row.dataset.eveningSched || '' };
    document.getElementById('schedStudentName').textContent = _schedApp.name;
    document.getElementById('schedCurDay').textContent = '(current: ' + schedLabel(_schedApp.day) + ')';
    document.getElementById('schedCurEve').textContent = '(current: ' + schedLabel(_schedApp.eve) + ')';
    schedBuildChips('schedDayChips', 'day', schedParse(_schedApp.day));
    schedBuildChips('schedEveChips', 'eve', schedParse(_schedApp.eve));
    document.getElementById('schedReason').value = '';
    document.getElementById('schedMsg').textContent = '';
    document.getElementById('schedContinueBtn').disabled = true;
    document.getElementById('schedContinueBtn').innerHTML = 'Continue';
    document.getElementById('schedModal').style.display = 'flex';
    setTimeout(function () { document.getElementById('schedReason').focus(); }, 60);
}
function closeSchedModal() {
    if (_schedBusy) return;
    document.getElementById('schedModal').style.display = 'none';
    _schedApp = null;
}
function schedValidate() { // returns true when the form can be continued
    if (!_schedApp) return false;
    var day = schedCollect('schedDayChips'), eve = schedCollect('schedEveChips');
    var reason = document.getElementById('schedReason').value.trim();
    var msg = '';
    if (!day || !eve) msg = 'Pick the days for both the Day and the Evening Schedule (or "None").';
    else if (day === 'None' && eve === 'None') msg = 'Day Schedule and Evening Schedule cannot both be "None".';
    else if (day === schedNorm(_schedApp.day) && eve === schedNorm(_schedApp.eve)) msg = 'This is the same as the current schedule.';
    else if (reason.length < 10) msg = 'Please give a reason (at least 10 characters).';
    document.getElementById('schedMsg').textContent = msg;
    var ok = (msg === '');
    document.getElementById('schedContinueBtn').disabled = !ok || _schedBusy;
    return ok;
}
var _schedPending = null; // {day, eve} waiting for the confirmation popup
function schedContinue() {
    if (!_schedApp || !schedValidate()) return;
    var day = schedCollect('schedDayChips'), eve = schedCollect('schedEveChips');
    _schedPending = { day: day, eve: eve };
    document.getElementById('schedConfirmStudent').textContent = _schedApp.name;
    var row = function (label, oldV, newV) {
        var same = (schedNorm(oldV) === schedNorm(newV));
        return '<tr><td>' + label + '</td><td>' + escHtml(schedLabel(oldV)) + '</td>' +
            (same ? '<td class="sc-same">' + escHtml(schedLabel(newV)) + '<small>No change</small></td>'
                  : '<td class="sc-new">' + escHtml(schedLabel(newV)) + '</td>') + '</tr>';
    };
    document.getElementById('schedConfirmRows').innerHTML = row('Day', _schedApp.day, day) + row('Evening', _schedApp.eve, eve);
    document.getElementById('schedConfirmReason').textContent = document.getElementById('schedReason').value.trim();
    document.getElementById('schedConfirmModal').style.display = 'flex';
    setTimeout(function () { var y = document.getElementById('schedConfirmYes'); if (y) y.focus(); }, 60);
}
function closeSchedConfirm() {
    document.getElementById('schedConfirmModal').style.display = 'none';
    _schedPending = null;
}
function schedConfirmYes() {
    var p = _schedPending;
    document.getElementById('schedConfirmModal').style.display = 'none';
    _schedPending = null;
    if (p) schedSubmit(p.day, p.eve);
}
function schedSubmit(day, eve) {
    if (!_schedApp || _schedBusy) return;
    var app = _schedApp, btn = document.getElementById('schedContinueBtn');
    _schedBusy = true;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
    var fd = new FormData();
    fd.append('update_student_schedule', '1');
    fd.append('app_id', app.id);
    fd.append('day_sched', day);
    fd.append('evening_sched', eve);
    fd.append('reason', document.getElementById('schedReason').value.trim());
    var schedLoad = endoShowLoading('Updating schedule'); // loading page (the e-mails are sent during this request)
    fetch('add_ojt_student.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            _schedBusy = false;
            btn.innerHTML = 'Continue';
            if (res && res.success) {
                document.getElementById('schedModal').style.display = 'none';
                _schedApp = null;
                aplShowTopToast('', res.message || 'Schedule updated.', 'fa-calendar-check');
                refreshApplicantTable();
            } else {
                schedValidate();
                aplShowTopToast('', (res && res.message) || 'The schedule could not be updated.', 'fa-circle-exclamation', true);
            }
        })
        .catch(function () {
            _schedBusy = false;
            btn.innerHTML = 'Continue';
            schedValidate();
            aplShowTopToast('', 'Network error. Please try again.', 'fa-circle-exclamation', true);
        })
        .finally(function () { endoHideLoading(schedLoad); });
}
document.getElementById('schedModal').addEventListener('click', function (e) { if (e.target === this) closeSchedModal(); });
document.getElementById('schedConfirmModal').addEventListener('click', function (e) { if (e.target === this) closeSchedConfirm(); });
document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (document.getElementById('schedConfirmModal').style.display === 'flex') closeSchedConfirm();
    else if (document.getElementById('schedModal').style.display === 'flex') closeSchedModal();
});

// ══════════════════════════════════════════════════════════════════════
// ADJUSTMENT: ADMIN BATCHES
//  • table: batch members on the same page share ONE Endorsement Letter cell
//    and ONE Application cell (rowspan); still 5 applicants per page
//  • Accept / Reject on a batch acts on every member (also on other pages)
//  • inbox: grouped by batch, "Move Batch to Table"
// ══════════════════════════════════════════════════════════════════════
function aplMergeBatchCells() {
    var rows = aplRows();
    rows.forEach(function (r) {                                   // reset
        [4, 5].forEach(function (i) { var c = r.cells[i]; if (!c) return; c.rowSpan = 1; c.style.display = ''; c.classList.remove('apl-batch-shared'); });
    });
    var visible = rows.filter(function (r) { return r.style.display !== 'none'; });
    for (var i = 0; i < visible.length; ) {
        var bid = visible[i].dataset.batch || '';
        var j = i + 1;
        while (bid && j < visible.length && visible[j].dataset.batch === bid) j++;
        if (bid && j - i > 1) {
            [4, 5].forEach(function (ci) {
                visible[i].cells[ci].rowSpan = j - i;
                visible[i].cells[ci].classList.add('apl-batch-shared');
                for (var k = i + 1; k < j; k++) visible[k].cells[ci].style.display = 'none';
            });
        }
        i = j;
    }
}

function aplBatchApps(bid) {
    var fd = new FormData(); fd.append('batch_app_ids', '1'); fd.append('batch_id', bid);
    return fetch('add_ojt_student.php', { method: 'POST', body: fd }).then(function (r) { return r.json(); })
        .then(function (res) { return (res && res.apps) || []; });
}

// Runs the existing single-application request for every member, one after another.
function aplForEachApp(apps, buildFd) {
    var results = [];
    return apps.reduce(function (p, app) {
        return p.then(function () {
            return fetch('add_ojt_student.php', { method: 'POST', body: buildFd(app) })
                .then(function (r) { return r.text(); })
                .then(function (t) { var j = null; try { j = JSON.parse(t); } catch (e) {} results.push({ app: app, res: j }); })
                .catch(function () { results.push({ app: app, res: null }); });
        });
    }, Promise.resolve()).then(function () { return results; });
}

// The batch actions already show their loading page; reloading must not bring up a second one.
function aplReloadPage(label) {
    globalLoadingMarkReturn();
    startNavigationGlobalLoading(label || 'Loading');
    window.location.reload();
}
function aplReloadWithToast(msg, type) {
    try { sessionStorage.setItem('apl_after_reload_toast', JSON.stringify({ msg: msg, type: type })); } catch (e) {}
    aplReloadPage(globalLoadingLabel ? globalLoadingLabel.textContent : 'Loading'); // keep the label that is already showing
}
(function () {
    try {
        var t = JSON.parse(sessionStorage.getItem('apl_after_reload_toast') || 'null');
        sessionStorage.removeItem('apl_after_reload_toast');
        if (t && t.msg) setTimeout(function () { showToast(t.msg, t.type || 'success'); }, 400);
    } catch (e) {}
})();

function acceptBatch(bid, btn) {
    aplBatchApps(bid).then(function (apps) {
        if (!apps.length) { showToast('This batch has no pending applications.', 'error'); return; }
        showConfirmPopup(
            'Accept the whole batch?',
            'All ' + apps.length + ' students in this batch will be registered to your company: ' + apps.map(function (a) { return a.name; }).join(', ') + '.',
            'success',
            function () {
                if (btn) btn.disabled = true;
                endoShowLoading('Registering the batch');
                aplForEachApp(apps, function (app) {
                    var fd = new FormData(); fd.append('accept_app', '1'); fd.append('app_id', String(app.id)); return fd;
                }).then(function (results) {
                    var ok = results.filter(function (r) { return r.res && r.res.success; });
                    var bad = results.filter(function (r) { return !(r.res && r.res.success); });
                    var msg = ok.length + ' of ' + results.length + ' students in the batch were registered.' +
                        (bad.length ? ' Not registered: ' + bad.map(function (r) { return r.app.name + (r.res && r.res.message ? ' (' + r.res.message + ')' : ''); }).join('; ') : '');
                    // ADJUSTMENT: no success popup after registering the batch — only when a member
                    // could not be registered is the message kept (shown after the reload).
                    if (bad.length) aplReloadWithToast(msg, 'error');
                    else aplReloadPage('Registering the batch');
                });
            }
        );
    });
}

var _rejectBatchApps = null;
function rejectBatch(bid) {
    aplBatchApps(bid).then(function (apps) {
        if (!apps.length) { showToast('This batch has no pending applications.', 'error'); return; }
        _rejectBatchApps = apps;
        openRejectModal(apps[0].id);
        var box = document.getElementById('rejectModalBox') || document.querySelector('#rejectModal > div');
        var note = document.getElementById('rejectBatchNote');
        if (!note && box) {
            note = document.createElement('div'); note.id = 'rejectBatchNote';
            note.style.cssText = 'background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:8px;padding:8px 10px;font-size:12.5px;margin:0 0 10px;';
            var form = document.getElementById('rejectForm'); form.parentNode.insertBefore(note, form);
        }
        if (note) { note.style.display = 'block'; note.textContent = 'This rejects all ' + apps.length + ' students in the batch: ' + apps.map(function (a) { return a.name; }).join(', ') + '.'; }
    });
}
// Batch mode for the existing Reject form (single rejections keep their own handler).
document.addEventListener('submit', function (e) {
    if (!e.target || e.target.id !== 'rejectForm' || !_rejectBatchApps) return;
    e.preventDefault(); e.stopPropagation();
    var reason = document.getElementById('rejectReasonText').value.trim();
    if (reason.length < 10) return;
    var apps = _rejectBatchApps;
    closeRejectModal();
    endoShowLoading('Rejecting the batch');
    aplForEachApp(apps, function (app) {
        var fd = new FormData(); fd.append('reject_app', '1'); fd.append('reject_app_id', String(app.id)); fd.append('reject_reason', reason); return fd;
    }).then(function (results) {
        var blocked = results.filter(function (r) { return r.res && r.res.blocked; });
        if (blocked.length) { // NEW (contract gate): the server refused some members
            aplReloadWithToast((apps.length - blocked.length) + ' of ' + apps.length + ' students in the batch were rejected and notified. Not rejected: ' +
                blocked.map(function (r) { return r.app.name; }).join(', ') + " (the Student/University Contract is not verified yet).", 'error');
        } else {
            aplReloadWithToast('All ' + apps.length + ' students in the batch were rejected and notified.', 'error');
        }
    });
}, true);
(function () {                                   // leaving batch mode when the modal closes
    var origClose = closeRejectModal;
    closeRejectModal = function () {
        origClose();
        _rejectBatchApps = null;
        var note = document.getElementById('rejectBatchNote'); if (note) note.style.display = 'none';
    };
})();

// ── inbox: grouped by batch ──
function refreshInboxGrouped() {
    var fd = new FormData(); fd.append('inbox_groups_html', '1');
    return fetch('add_ojt_student.php', { method: 'POST', body: fd }).then(function (r) { return r.json(); }).then(function (res) {
        var body = document.getElementById('inboxBody');
        if (!body || !res || !res.success) return;
        var notice = body.querySelector('.ar-live-notice');
        body.innerHTML = res.html;
        if (notice) body.insertBefore(notice, body.firstChild);
        _knownAppIds.clear();
        (res.ids || []).forEach(function (id) { _knownAppIds.add(String(id)); });
    }).catch(function () {});
}

function moveBatchToTable(bid, btn) {
    if (btn) btn.disabled = true;
    var started = endoShowLoading('Moving batch to table');
    var fd = new FormData(); fd.append('move_batch_to_table', '1'); fd.append('batch_id', bid);
    fetch('add_ojt_student.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res && res.success) {
                var card = btn ? btn.closest('.ar-batch-card') : null;
                if (card) card.querySelectorAll('.ar-member').forEach(function (m) { _knownAppIds.delete(m.id.replace('appCard', '')); updateInboxBadge(-1); });
                if (card) { card.classList.add('removing'); setTimeout(function () { card.remove(); checkEmptyInbox(); }, 300); }
                return Promise.resolve(refreshApplicantTable()).then(function () { endoHideLoading(started); });
            }
            if (btn) btn.disabled = false;
            endoHideLoading(started);
            showToast((res && res.message) || 'Could not move the batch.', 'error');
        })
        .catch(function () { if (btn) btn.disabled = false; endoHideLoading(started); showToast('Network error. Please try again.', 'error'); });
}

// ADJUSTMENT: the application-request popup (same as administrator.php's cvShowTopToast).
function aplShowTopToast(name, messageText, iconClass, isError) {
    var esc = function (s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };
    var div = document.createElement('div');
    div.className = 'cv-top-toast' + (isError ? ' is-error' : '');
    div.setAttribute('role', 'status');
    div.innerHTML = '<i class="fas ' + esc(iconClass || 'fa-circle-info') + '"></i><span>' + (name ? '<strong>' + esc(name) + '</strong> ' : '') + esc(messageText) + '</span>';
    document.body.appendChild(div);
    var layout = function () { var top = 30; document.querySelectorAll('.cv-top-toast').forEach(function (el) { el.style.top = top + 'px'; top += el.offsetHeight + 12; }); };
    layout();
    requestAnimationFrame(function () { div.classList.add('show'); });
    setTimeout(function () { div.classList.remove('show'); setTimeout(function () { div.remove(); layout(); }, 400); }, 7000);
}
</script>
</body>
</html>