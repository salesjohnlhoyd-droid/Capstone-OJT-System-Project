<?php
session_start();
include "db.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require 'PHPMailer/src/Exception.php';

if(isset($_POST['sender'], $_POST['receiver'], $_POST['company_id'], $_POST['message'])){

    $sender = $_POST['sender'];
    $receiver = $_POST['receiver'];
    $company_id = $_POST['company_id'];
    $message = $_POST['message'];

    // ================= SAVE MESSAGE TO DATABASE =================
    $stmt = $conn->prepare("INSERT INTO company_messages (company_id, sender_email, receiver_email, message) VALUES (?,?,?,?)");
    $stmt->bind_param("isss", $company_id, $sender, $receiver, $message);
    $stmt->execute();
    $stmt->close();

    // ================= SEND EMAIL =================
    $mail = new PHPMailer(true);

    try{
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'salesjohnlhoyd@gmail.com';   // SYSTEM EMAIL
        $mail->Password   = 'qwufanprpmezotly';           // Gmail App Password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // Sender
        $mail->setFrom('salesjohnlhoyd@gmail.com', 'OJT Monitoring System');

        // Receiver
        $mail->addAddress($receiver);

        // Reply-to is original sender
        $mail->addReplyTo($sender);

        $mail->isHTML(true);

        // Automatically include COMPANY_<id> in subject
        $mail->Subject = "COMPANY_{$company_id} | New Message from OJT Monitoring System";

        $mail->Body = "
        <h3>New Message Notification</h3>
        <p><b>Sender:</b> $sender</p>
        <p><b>Message:</b></p>
        <p>$message</p>
        <br>
        <p>You can reply directly in the system dashboard or via email.</p>
        ";

        $mail->send();

    }catch(Exception $e){
        // Optional logging
        file_put_contents("mail_error_log.txt",$mail->ErrorInfo);
    }
}
?>
