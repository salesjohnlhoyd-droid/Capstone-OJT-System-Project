<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/phpmailer/src/Exception.php';
require_once __DIR__ . '/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/phpmailer/src/SMTP.php';

function sendStatusEmail($toEmail, $fullName, $requirementName, $status, $remark = null)
{
    // "Rejected" means the same as "Denied" — both are shown to the student as "Declined".
    if ($status === 'Rejected') $status = 'Denied';

    $mail = new PHPMailer(true);

    try {

        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'salesjohnlhoyd@gmail.com';
        $mail->Password   = 'qwufanprpmezotly';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('example@gmail.com', 'Atate Campus On The Job Training System');
        $mail->addAddress($toEmail, $fullName);

        $mail->isHTML(true);
        $mail->Subject = 'Document Submission Update — ' . $requirementName;

        // ================= STATUS MESSAGING =================
        $safeReq = htmlspecialchars($requirementName);
        if ($status === 'Verified') {
            $statusLabel    = 'Accepted';
            $statusMessage  = "Great news! Your <strong>{$safeReq}</strong> has been reviewed and <strong style='color:#16a34a;'>accepted</strong>. Thank you for submitting it — you're one step closer to starting your OJT!";
            $subMessage     = "There's nothing more you need to do for this document. You can log in to the portal anytime to see how the rest of your requirements are coming along.";
        } elseif ($status === 'Denied') {
            $statusLabel    = 'Declined';
            $statusMessage  = "Thank you for submitting your <strong>{$safeReq}</strong>. After review, it has been <strong style='color:#b45309;'>declined</strong> and needs a small correction before it can be accepted.";
            $subMessage     = "Please take a look at the reason above, then log in to the portal and re-upload the corrected document. You've got this — and we're here to help if you need anything!";
        } else {
            $statusLabel    = 'Being Processed';
            $statusMessage  = "Thank you for your submission! Your <strong>{$safeReq}</strong> has been received and is now in line to be reviewed by the administrator.";
            $subMessage     = "We'll send you another update as soon as the review is done. In the meantime, you can check the status of all your documents in the portal anytime.";
        }

        // ================= REASON BLOCK (only for a declined requirement) =================
        $remarkBlock = '';
        if ($status === 'Denied' && trim((string)$remark) !== '') {
            $remarkBlock = "
                <table width='100%' cellpadding='0' cellspacing='0' style='background:#fff7ed; border:1px solid #fed7aa; border-radius:8px; margin:18px 0 6px;'>
                    <tr>
                        <td style='padding:14px 18px;'>
                            <div style='font-size:11px; color:#92400e; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:6px;'>
                                Reason for Declining
                            </div>
                            <div style='font-size:14px; color:#78350f; line-height:1.7;'>
                                " . nl2br(htmlspecialchars(trim((string)$remark))) . "
                            </div>
                        </td>
                    </tr>
                </table>
            ";
        }

        // ================= EMAIL BODY =================
        $mail->Body = "
        <div style='font-family:Arial,sans-serif; background:#f4f4f4; padding:30px 0; margin:0;'>
          <table width='100%' cellpadding='0' cellspacing='0'>
            <tr><td align='center'>
              <table width='560' cellpadding='0' cellspacing='0' style='background:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 4px 20px rgba(0,0,0,0.08);'>

                <!-- Header -->
                <tr>
                  <td style='background:#07145f; padding:26px 36px; text-align:center;'>
                    <div style='font-size:20px; font-weight:700; color:#FFD700; letter-spacing:0.5px;'>NEUST OJT Portal</div>
                    <div style='font-size:12px; color:rgba(255,255,255,0.6); margin-top:4px;'>Atate Campus — On the Job Training System</div>
                  </td>
                </tr>

                <!-- Body -->
                <tr>
                  <td style='padding:28px 36px 10px;'>
                    <p style='margin:0 0 14px; font-size:15px; color:#374151;'>Hi <strong>" . htmlspecialchars($fullName) . "</strong>,</p>
                    <p style='margin:0 0 10px; font-size:14px; color:#4b5563; line-height:1.7;'>{$statusMessage}</p>

                    {$remarkBlock}

                    <p style='margin:16px 0 0; font-size:14px; color:#4b5563; line-height:1.7;'>{$subMessage}</p>
                  </td>
                </tr>

                <!-- CTA -->
                <tr>
                  <td style='padding:8px 36px 28px; text-align:center;'>
                    <a href='#' style='display:inline-block; background:#07145f; color:#FFD700; text-decoration:none; font-weight:700; font-size:13px; padding:12px 30px; border-radius:8px;'>Log In to OJT Portal</a>
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

        $mail->AltBody = $status === 'Verified'
            ? "Hi $fullName, great news! Your $requirementName has been accepted. Log in to the OJT portal anytime to see your overall progress."
            : ($status === 'Denied'
            ? "Hi $fullName, your $requirementName has been declined and needs a small correction." . (trim((string)$remark) !== '' ? " Reason: " . rtrim(trim((string)$remark), '.') . "." : '') . " Please log in to the OJT portal and re-upload the corrected document."
            : "Hi $fullName, thank you for your submission! Your $requirementName has been received and will be reviewed soon. Log in to the OJT portal anytime to check its status.");

        $mail->send();
        return true;

    } catch (Exception $e) {
        echo "Mailer Error: " . $mail->ErrorInfo;
        return false;
    }
}

function sendApplicationResultEmail($toEmail, $fullName, $companyName, $result)
{
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'salesjohnlhoyd@gmail.com';
        $mail->Password   = 'qwufanprpmezotly';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('example@gmail.com', 'Atate Campus On The Job Training System');
        $mail->addAddress($toEmail, $fullName);
        $mail->isHTML(true);

        if ($result === 'approved') {
            $mail->Subject = 'OJT Application Approved — ' . $companyName;
            $statusColor   = '#16a34a';
            $statusBg      = '#f0fdf4';
            $statusBorder  = '#bbf7d0';
            $statusIcon    = '';
            $statusHeading = 'Your Application Has Been Approved!';
            $bodyMessage   = "Congratulations! Your OJT application to <strong>" . htmlspecialchars($companyName) . "</strong> has been <strong style='color:#16a34a;'>approved</strong> by the administrator.";
            $subMessage    = "You're all set to move on to the next steps of your OJT. Please log in to the portal for your further instructions.";
            $footerNote    = "We're excited for you to begin this journey — best of luck! 🎉";
        } else {
            $mail->Subject = 'OJT Application Declined — ' . $companyName;   // ADJUSTMENT: was "Status Update"

            $statusColor   = '#dc2626';
            $statusBg      = '#fff1f1';
            $statusBorder  = '#fecaca';
            $statusIcon    = '';
            $statusHeading = 'Your Application Has Been Declined';
            $bodyMessage   = "Thank you for your interest in <strong>" . htmlspecialchars($companyName) . "</strong> — after careful review, your application has been <strong style='color:#dc2626;'>declined</strong> by the administrator at this time.";
            $subMessage    = "This isn't the end of the road — you're welcome to apply to another company through the portal, and your OJT coordinator is happy to help if you have any questions.";
            $footerNote    = "Keep going — the right opportunity is out there for you.";
        }

        $mail->Body = "
        <div style='font-family:Arial,sans-serif; background:#f4f4f4; padding:30px 0; margin:0;'>
          <table width='100%' cellpadding='0' cellspacing='0'>
            <tr><td align='center'>
              <table width='560' cellpadding='0' cellspacing='0' style='background:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 4px 20px rgba(0,0,0,0.08);'>

                <!-- Header -->
                <tr>
                  <td style='background:#07145f; padding:26px 36px; text-align:center;'>
                    <div style='font-size:20px; font-weight:700; color:#FFD700; letter-spacing:0.5px;'>NEUST OJT Portal</div>
                    <div style='font-size:12px; color:rgba(255,255,255,0.6); margin-top:4px;'>Atate Campus — On the Job Training System</div>
                  </td>
                </tr>

                <!-- Body -->
                <tr>
                  <td style='padding:30px 36px;'>
                    <p style='margin:0 0 14px; font-size:15px; color:#374151;'>Hi <strong>" . htmlspecialchars($fullName) . "</strong>,</p>
                    <p style='margin:0 0 10px; font-size:14px; color:#4b5563; line-height:1.7;'>{$bodyMessage}</p>
                    <p style='margin:0 0 20px; font-size:14px; color:#4b5563; line-height:1.7;'>{$subMessage}</p>

                    <p style='margin:0; font-size:13px; color:#6b7280; font-style:italic;'>{$footerNote}</p>
                  </td>
                </tr>

                <!-- CTA -->
                <tr>
                  <td style='padding:0 36px 28px; text-align:center;'>
                    <a href='#' style='display:inline-block; background:#07145f; color:#FFD700; text-decoration:none; font-weight:700; font-size:13px; padding:12px 30px; border-radius:8px;'>Log In to OJT Portal</a>
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

        $mail->AltBody = "Hi $fullName, your OJT application to $companyName has been " . ($result === 'approved' ? 'approved. Congratulations!' : 'declined at this time. You are welcome to apply to another company — your OJT coordinator can help.') . " Log in to the portal for details.";

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log('sendApplicationResultEmail error: ' . $mail->ErrorInfo);
        return false;
    }
}

/**
 * ================= MOA WORKFLOW STAGE EMAIL =================
 * Sends an automatic email to the company representative every time the
 * admin advances the MOA document's in-table workflow stage
 * (pending -> reviewing -> approved -> scheduled) inside company_validation.php.
 *
 * The admin's optional comment for that stage and, when the final
 * "Schedule for Signing" stage is reached, the signing date/time are
 * included in the email body.
 *
 * This function is called from company_validation.php via sendMoaStageEmail().
 *
 * @param string      $toEmail           Recipient email address
 * @param string      $fullName          Recipient's full name
 * @param string      $companyName       Company name
 * @param string      $stage             One of: pending, reviewing, approved, scheduled
 * @param string|null $comment           Optional admin comment for this stage
 * @param string|null $scheduleDateTime  'Y-m-d H:i:s' formatted signing date/time (only for 'scheduled' stage)
 * @return bool
 */
function sendMoaWorkflowEmail($toEmail, $fullName, $companyName, $stage, $comment = null, $scheduleDateTime = null)
{
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'salesjohnlhoyd@gmail.com';
        $mail->Password   = 'qwufanprpmezotly';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('example@gmail.com', 'Atate Campus On The Job Training System');
        $mail->addAddress($toEmail, $fullName);
        $mail->isHTML(true);

        // ================= STAGE STYLING & MESSAGING =================
        switch ($stage) {
            case 'reviewing':
                $statusColor   = '#1e40af';
                $statusBg      = '#dbeafe';
                $statusBorder  = '#bfdbfe';
                $statusLabel   = 'Under Review';
                $statusHeading = 'Your MOA Document is now being reviewed.';
                $bodyMessage   = "Thank you for submitting the Memorandum of Agreement (MOA) for <strong>" . htmlspecialchars($companyName) . "</strong> — it is now <strong style='color:#1e40af;'>under review</strong> by the administrator.";
                $subMessage    = "There's nothing you need to do right now — we'll let you know as soon as the review is complete.";
                break;

            case 'approved':
                $statusColor   = '#065f46';
                $statusBg      = '#d1fae5';
                $statusBorder  = '#a7f3d0';
                $statusLabel   = 'Approved';
                $statusHeading = 'Great news! Your MOA Document has been approved.';
                $bodyMessage   = "Great news! The Memorandum of Agreement (MOA) for <strong>" . htmlspecialchars($companyName) . "</strong> has been <strong style='color:#065f46;'>approved</strong> by the administrator. Thank you for your partnership!";
                $subMessage    = "Next up is the official signing. You'll receive another email shortly with the proposed date and time.";
                break;

            case 'scheduled':
                $statusColor   = '#5b21b6';
                $statusBg      = '#ede9fe';
                $statusBorder  = '#ddd6fe';
                $statusLabel   = 'Scheduled for Signing';
                $statusHeading = 'Your MOA signing has been scheduled!';
                $bodyMessage   = "The Memorandum of Agreement (MOA) for <strong>" . htmlspecialchars($companyName) . "</strong> has been verified and is now <strong style='color:#5b21b6;'>scheduled for signing</strong>. We're looking forward to it!";
                $subMessage    = "Please take a look at the signing schedule above. If you have questions or need to adjust anything, feel free to reach out to your OJT coordinator.";
                break;

            case 'pending':
            default:
                $statusColor   = '#854d0e';
                $statusBg      = '#fef9c3';
                $statusBorder  = '#fde68a';
                $statusLabel   = 'Pending';
                $statusHeading = 'Your MOA Document has been received.';
                $bodyMessage   = "Thank you! We've received the Memorandum of Agreement (MOA) for <strong>" . htmlspecialchars($companyName) . "</strong> — it is <strong style='color:#854d0e;'>pending</strong> and will be reviewed by the administrator shortly.";
                $subMessage    = "We'll notify you as soon as the review begins. No action is needed from you for now.";
                break;
        }

        $mail->Subject = 'MOA Document Update — ' . $statusLabel . ' — ' . $companyName;

        // ================= ADMIN COMMENT BLOCK =================
        $commentBlock = '';
        if (!empty($comment)) {
            $commentBlock = "
                <table width='100%' cellpadding='0' cellspacing='0' style='background:#f5f3ff; border:1px solid #ddd6fe; border-radius:8px; margin:18px 0 6px;'>
                    <tr>
                        <td style='padding:14px 18px;'>
                            <div style='font-size:11px; color:#5b21b6; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:6px;'>
                                Note from the Administrator
                            </div>
                            <div style='font-size:14px; color:#3b0764; line-height:1.7;'>
                                " . nl2br(htmlspecialchars($comment)) . "
                            </div>
                        </td>
                    </tr>
                </table>
            ";
        }

        // ================= SIGNING SCHEDULE BLOCK (only for 'scheduled' stage) =================
        $scheduleBlock = '';
        $scheduleDateLabel = '';
        $scheduleTimeLabel = '';
        if ($stage === 'scheduled' && !empty($scheduleDateTime)) {
            $ts = strtotime($scheduleDateTime);
            if ($ts !== false) {
                $scheduleDateLabel = date('F j, Y (l)', $ts);
                $scheduleTimeLabel = date('g:i A', $ts);
                $scheduleBlock = "
                    <table width='100%' cellpadding='0' cellspacing='0' style='background:#f8f7ff; border:1px solid #e0d9ff; border-radius:8px; margin:16px 0 6px;'>
                        <tr>
                            <td style='padding:16px 18px;'>
                                <div style='font-size:11px; color:#6b7280; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:10px;'>Signing Schedule</div>
                                <table width='100%' cellpadding='0' cellspacing='0'>
                                    <tr>
                                        <td style='padding-right:10px; width:50%;'>
                                            <div style='font-size:10px; color:#9ca3af; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:3px;'>Date</div>
                                            <div style='font-size:15px; font-weight:700; color:#07145f;'>" . htmlspecialchars($scheduleDateLabel) . "</div>
                                        </td>
                                        <td style='padding-left:10px; width:50%; border-left:1px solid #e0d9ff;'>
                                            <div style='font-size:10px; color:#9ca3af; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:3px;'>Time</div>
                                            <div style='font-size:15px; font-weight:700; color:#07145f;'>" . htmlspecialchars($scheduleTimeLabel) . "</div>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    </table>
                ";
            }
        }

        // ================= EMAIL BODY =================
        $mail->Body = "
        <div style='font-family:Arial,sans-serif; background:#f4f4f4; padding:30px 0; margin:0;'>
          <table width='100%' cellpadding='0' cellspacing='0'>
            <tr><td align='center'>
              <table width='560' cellpadding='0' cellspacing='0' style='background:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 4px 20px rgba(0,0,0,0.08);'>

                <!-- Header -->
                <tr>
                  <td style='background:#07145f; padding:26px 36px; text-align:center;'>
                    <div style='font-size:20px; font-weight:700; color:#FFD700; letter-spacing:0.5px;'>NEUST OJT Portal</div>
                    <div style='font-size:12px; color:rgba(255,255,255,0.6); margin-top:4px;'>Atate Campus — On the Job Training System</div>
                  </td>
                </tr>

                <!-- Body -->
                <tr>
                  <td style='padding:28px 36px 10px;'>
                    <p style='margin:0 0 14px; font-size:15px; color:#374151;'>Hi <strong>" . htmlspecialchars($fullName) . "</strong>,</p>
                    <p style='margin:0 0 10px; font-size:14px; color:#4b5563; line-height:1.7;'>{$bodyMessage}</p>

                    {$scheduleBlock}
                    {$commentBlock}

                    <p style='margin:16px 0 0; font-size:14px; color:#4b5563; line-height:1.7;'>{$subMessage}</p>
                  </td>
                </tr>

                <!-- CTA -->
                <tr>
                  <td style='padding:8px 36px 28px; text-align:center;'>
                    <a href='#' style='display:inline-block; background:#07145f; color:#FFD700; text-decoration:none; font-weight:700; font-size:13px; padding:12px 30px; border-radius:8px;'>Log In to OJT Portal</a>
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

        $altBody = "Hi $fullName, thank you for your partnership! The MOA for $companyName is now \"$statusLabel\".";
        if (!empty($comment)) {
            $altBody .= " Admin note: " . $comment;
        }
        if ($stage === 'scheduled' && !empty($scheduleDateLabel)) {
            $altBody .= " Signing schedule: $scheduleDateLabel at $scheduleTimeLabel.";
        }
        $altBody .= " Please log in to the OJT portal for details.";
        $mail->AltBody = $altBody;

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log('sendMoaWorkflowEmail error: ' . $mail->ErrorInfo);
        return false;
    }
}
?>