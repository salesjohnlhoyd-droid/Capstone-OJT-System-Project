<?php
// 1. Manually include the core PHPMailer files
// NOTE: Change 'PHPMailer/src/' to match the exact folder path where your files live!
require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

// 2. Define the namespaces
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// 3. Instantiate the class
$mail = new PHPMailer(true);

try {
    // Your email settings go here...
    echo "PHPMailer is loaded successfully!";
} catch (Exception $e) {
    echo "Mailer Error: {$mail->ErrorInfo}";
}