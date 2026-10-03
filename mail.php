<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/phpmailer/src/Exception.php';
require_once __DIR__ . '/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/phpmailer/src/SMTP.php';

function sendStatusEmail($toEmail, $fullName, $requirementName, $status, $remark = null)
{
    // ADJUSTMENT: "Rejected" means the same as "Denied" (both are shown to the student as "Declined"); the status values the callers send are unchanged.
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

        // ================= STATUS STYLING & MESSAGING =================
        if ($status === 'Verified') {
            $statusIcon     = '';
            $statusColor    = '#16a34a';
            $statusBg       = '#f0fdf4';
            $statusBorder   = '#bbf7d0';
            $statusLabel    = 'Accepted';
            $statusHeading  = 'Great news! Your document has been accepted.';
            $statusMessage  = "Your requirement <strong>" . htmlspecialchars($requirementName) . "</strong> has been accepted and you're one step closer to your OJT. Keep up the great work!";
            $subMessage     = "No further action is needed for this document. Please log in to the portal to check your overall submission progress.";
        } elseif ($status === 'Denied') {
            $statusIcon     = '';
            $statusColor    = '#b45309';
            $statusBg       = '#fffbeb';
            $statusBorder   = '#fde68a';
            $statusLabel    = 'Declined';   // ADJUSTMENT: was 'Needs Attention' — "Declined" everywhere
            $statusHeading  = "Your document has been declined — don't worry, it needs only a small correction.";
            $statusMessage  = "Your requirement <strong>" . htmlspecialchars($requirementName) . "</strong> has been <strong style='color:#b45309;'>DECLINED</strong> and needs a correction before it can be accepted. Please review the remark below and resubmit the updated document.";
            $subMessage     = "Once you have made the necessary corrections, simply log in to the portal and re-upload the document. We're here to help you get it right!";
        } else {
            $statusIcon     = '';
            $statusColor    = '#0369a1';
            $statusBg       = '#f0f9ff';
            $statusBorder   = '#bae6fd';
            $statusLabel    = 'Being Processed';
            $statusHeading  = 'Hang tight! Your document is being processed.';
            $statusMessage  = "Your requirement <strong>" . htmlspecialchars($requirementName) . "</strong> is in the queue and will be reviewed by the administrator shortly.";
            $subMessage     = "You will receive another notification once your document has been reviewed. In the meantime, feel free to log in to the portal to check your submission status.";
        }

        // ================= REMARK BLOCK (only for Denied) =================
        $remarkBlock = '';
        if ($status === 'Denied' && $remark) {
            $remarkBlock = "
                <table width='100%' cellpadding='0' cellspacing='0' style='background:#fff7ed; border:1px solid #fed7aa; border-radius:8px; margin:18px 0 6px;'>
                    <tr>
                        <td style='padding:14px 18px;'>
                            <div style='font-size:11px; color:#92400e; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:6px;'>
                                 Reason for Declining
                            </div>
                            <div style='font-size:14px; color:#78350f; line-height:1.7;'>
                                " . htmlspecialchars($remark) . "
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

                <!-- Status Banner -->
                <tr>
                  <td style='background:{$statusBg}; border-bottom:2px solid {$statusBorder}; padding:22px 36px; text-align:center;'>
                    <div style='font-size:36px; margin-bottom:8px;'>{$statusIcon}</div>
                    <div style='font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:1px; color:{$statusColor}; margin-bottom:6px;'>{$statusLabel}</div>
                    <div style='font-size:16px; font-weight:700; color:#1e293b;'>{$statusHeading}</div>
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

                <!-- Requirement Card -->
                <tr>
                  <td style='padding:16px 36px;'>
                    <table width='100%' cellpadding='0' cellspacing='0' style='background:#f8f7ff; border:1px solid #e0d9ff; border-radius:8px;'>
                      <tr>
                        <td style='padding:14px 18px;'>
                          <div style='font-size:11px; color:#6b7280; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px;'>Document</div>
                          <div style='font-size:15px; font-weight:700; color:#07145f;'>" . htmlspecialchars($requirementName) . "</div>
                        </td>
                      </tr>
                    </table>
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

        $mail->AltBody = "Hi $fullName, your requirement $requirementName is now $statusLabel. Please log in to the OJT portal for details.";

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
            $bodyMessage   = "Congratulations! Your OJT application to <strong>" . htmlspecialchars($companyName) . "</strong> has been <strong style='color:#16a34a;'>APPROVED</strong> by the administrator.";
            $subMessage    = "You may now proceed to the next steps of your OJT process. Please log in to the portal for further instructions.";
            $footerNote    = "We're excited for you to begin your OJT journey. Good luck! 🎉";
        } else {
            $mail->Subject = 'OJT Application Declined — ' . $companyName;   // ADJUSTMENT: was "Status Update"

            $statusColor   = '#dc2626';
            $statusBg      = '#fff1f1';
            $statusBorder  = '#fecaca';
            $statusIcon    = '';
            $statusHeading = 'Your Application Has Been Declined';
            $bodyMessage   = "We regret to inform you that your OJT application to <strong>" . htmlspecialchars($companyName) . "</strong> has been <strong style='color:#dc2626;'>DECLINED</strong> by the administrator.";
            $subMessage    = "You may apply to a different company through the portal. If you have questions or concerns, please contact your OJT coordinator.";
            $footerNote    = "Don't be discouraged — other opportunities are available. Please reach out to your coordinator for guidance.";
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

                <!-- Status Banner -->
                <tr>
                  <td style='background:{$statusBg}; border-bottom:2px solid {$statusBorder}; padding:22px 36px; text-align:center;'>
                    <div style='font-size:32px; margin-bottom:8px;'>{$statusIcon}</div>
                    <div style='font-size:17px; font-weight:700; color:{$statusColor};'>{$statusHeading}</div>
                  </td>
                </tr>

                <!-- Body -->
                <tr>
                  <td style='padding:30px 36px;'>
                    <p style='margin:0 0 14px; font-size:15px; color:#374151;'>Dear <strong>{$fullName}</strong>,</p>
                    <p style='margin:0 0 10px; font-size:14px; color:#4b5563; line-height:1.7;'>{$bodyMessage}</p>
                    <p style='margin:0 0 24px; font-size:14px; color:#4b5563; line-height:1.7;'>{$subMessage}</p>

                    <!-- Company Card -->
                    <table width='100%' cellpadding='0' cellspacing='0' style='background:#f8f7ff; border:1px solid #e0d9ff; border-radius:8px; margin-bottom:24px;'>
                      <tr>
                        <td style='padding:14px 18px;'>
                          <div style='font-size:11px; color:#6b7280; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px;'>Company Applied To</div>
                          <div style='font-size:16px; font-weight:700; color:#07145f;'>" . htmlspecialchars($companyName) . "</div>
                        </td>
                      </tr>
                    </table>

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

        $mail->AltBody = "Hi $fullName, your OJT application to $companyName has been " . ($result === 'approved' ? 'APPROVED' : 'DECLINED') . ". Please log in to the portal for details.";

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
                $bodyMessage   = "Your submitted Memorandum of Agreement (MOA) document for <strong>" . htmlspecialchars($companyName) . "</strong> is currently <strong style='color:#1e40af;'>UNDER REVIEW</strong> by the administrator.";
                $subMessage    = "No action is needed from you at this time. We will notify you again once the review has been completed.";
                break;

            case 'approved':
                $statusColor   = '#065f46';
                $statusBg      = '#d1fae5';
                $statusBorder  = '#a7f3d0';
                $statusLabel   = 'Approved';
                $statusHeading = 'Great news! Your MOA Document has been approved.';
                $bodyMessage   = "Your Memorandum of Agreement (MOA) document for <strong>" . htmlspecialchars($companyName) . "</strong> has been <strong style='color:#065f46;'>APPROVED</strong> by the administrator.";
                $subMessage    = "The next step is the official signing schedule. You will receive another email shortly with the proposed date and time for signing.";
                break;

            case 'scheduled':
                $statusColor   = '#5b21b6';
                $statusBg      = '#ede9fe';
                $statusBorder  = '#ddd6fe';
                $statusLabel   = 'Scheduled for Signing';
                $statusHeading = 'Your MOA signing has been scheduled!';
                $bodyMessage   = "Your Memorandum of Agreement (MOA) document for <strong>" . htmlspecialchars($companyName) . "</strong> has been verified and is now <strong style='color:#5b21b6;'>SCHEDULED FOR SIGNING</strong>.";
                $subMessage    = "Please review the signing schedule below. Should you have any questions or need to make adjustments, kindly contact your OJT coordinator.";
                break;

            case 'pending':
            default:
                $statusColor   = '#854d0e';
                $statusBg      = '#fef9c3';
                $statusBorder  = '#fde68a';
                $statusLabel   = 'Pending';
                $statusHeading = 'Your MOA Document has been received.';
                $bodyMessage   = "We have received your Memorandum of Agreement (MOA) document for <strong>" . htmlspecialchars($companyName) . "</strong>. It is now <strong style='color:#854d0e;'>PENDING</strong> and will be reviewed by the administrator shortly.";
                $subMessage    = "You will receive another notification once your document review begins.";
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

                <!-- Status Banner -->
                <tr>
                  <td style='background:{$statusBg}; border-bottom:2px solid {$statusBorder}; padding:22px 36px; text-align:center;'>
                    <div style='font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:1px; color:{$statusColor}; margin-bottom:6px;'>MOA Document — {$statusLabel}</div>
                    <div style='font-size:16px; font-weight:700; color:#1e293b;'>{$statusHeading}</div>
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

                <!-- Company Card -->
                <tr>
                  <td style='padding:16px 36px;'>
                    <table width='100%' cellpadding='0' cellspacing='0' style='background:#f8f7ff; border:1px solid #e0d9ff; border-radius:8px;'>
                      <tr>
                        <td style='padding:14px 18px;'>
                          <div style='font-size:11px; color:#6b7280; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px;'>Company</div>
                          <div style='font-size:15px; font-weight:700; color:#07145f;'>" . htmlspecialchars($companyName) . "</div>
                        </td>
                      </tr>
                    </table>
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

        $altBody = "Hi $fullName, the MOA document for $companyName is now \"$statusLabel\".";
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