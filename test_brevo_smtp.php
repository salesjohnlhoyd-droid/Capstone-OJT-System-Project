<?php
// ============================================
// TEMPORARY DEBUG SCRIPT — not part of the app, safe to delete after use.
// ------------------------------------------------------------
// Purpose: isolate the Brevo SMTP authentication failure by turning on
// PHPMailer's full debug output, so we can see the EXACT text Brevo's
// server sends back instead of the generic "Could not authenticate."
// message monitoring.php currently shows.
//
// HOW TO USE:
//   1. Place this file in the SAME folder as monitoring.php
//      (e.g. C:\xampp\htdocs\phpmailer\test_brevo_smtp.php)
//   2. Open it in your browser:
//      http://localhost/phpmailer/test_brevo_smtp.php
//   3. Copy the FULL debug output shown and send it back — it will show
//      exactly what Brevo's server said, which tells us the real cause.
//
// This file reads the same config/env.local.php you already set up, so
// no credentials need to be typed here.
// ============================================

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require 'PHPMailer/src/Exception.php';

$envFile = __DIR__ . '/config/env.local.php';
if (file_exists($envFile)) {
    require_once $envFile;
    echo "<p>Loaded config/env.local.php successfully.</p>";
} else {
    die("<p style='color:red;'>config/env.local.php was not found at: " . htmlspecialchars($envFile) . "</p>");
}

$smtpUser = getenv('SMTP_USERNAME') ?: '';
$smtpPass = getenv('SMTP_PASSWORD') ?: '';

if ($smtpUser === '' || $smtpPass === '') {
    die("<p style='color:red;'>SMTP_USERNAME or SMTP_PASSWORD is empty — env.local.php isn't setting them correctly.</p>");
}

echo "<p>Username being used: <b>" . htmlspecialchars($smtpUser) . "</b></p>";
echo "<p>Password length being used: <b>" . strlen($smtpPass) . " characters</b> (should be a long string, not blank)</p>";

$smtpHost = getenv('SMTP_HOST') ?: 'smtp-relay.brevo.com';
$smtpPort = (int)(getenv('SMTP_PORT') ?: 587);
echo "<p>Host being used: <b>" . htmlspecialchars($smtpHost) . ":" . $smtpPort . "</b></p>";

echo "<pre style='background:#111;color:#0f0;padding:15px;white-space:pre-wrap;'>";

$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host       = $smtpHost;
    $mail->SMTPAuth   = true;
    $mail->Username   = $smtpUser;
    $mail->Password   = $smtpPass;
    $mail->SMTPSecure = 'tls';
    $mail->Port       = $smtpPort;
    $mail->AuthType   = 'LOGIN'; // force LOGIN instead of PHPMailer's default CRAM-MD5 pick — Brevo needs this
    $mail->SMTPDebug  = SMTP::DEBUG_SERVER; // full server conversation
    $mail->Debugoutput = function ($str, $level) {
        echo htmlspecialchars($str) . "\n";
    };
    $mail->CharSet = 'UTF-8';
    $mail->setFrom($smtpUser, 'Debug Test');
    $mail->addAddress($smtpUser, 'Debug Test'); // send to yourself for the test
    $mail->isHTML(true);
    $mail->Subject = 'Brevo SMTP debug test';
    $mail->Body    = 'If you received this, Brevo SMTP is working correctly.';
    $mail->send();

    echo "\n=== SUCCESS: Email sent! ===\n";
} catch (\Throwable $e) {
    echo "\n=== FAILURE ===\n";
    echo "Exception message: " . htmlspecialchars($e->getMessage()) . "\n";
    echo "PHPMailer ErrorInfo: " . htmlspecialchars($mail->ErrorInfo) . "\n";
}

echo "</pre>";