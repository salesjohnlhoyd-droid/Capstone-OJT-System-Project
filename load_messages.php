<?php
session_start();
include "db.php";

$company_id = intval($_GET['company_id']);
$current_user_email = $_SESSION['user_email'] ?? '';
; // admin or company email

$stmt = $conn->prepare("SELECT * FROM company_messages WHERE company_id=? ORDER BY created_at ASC");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$result = $stmt->get_result();

while($row = $result->fetch_assoc()){
    $isSender = ($row['sender_email'] === $current_user_email);
    echo "<div class='chat-message ".($isSender ? 'sender':'')."'>";
    echo "<b>".htmlspecialchars($row['sender_email'])."</b><br>";
    echo nl2br(htmlspecialchars($row['message']));
    if($row['edited']) echo " <small>(edited)</small>";
    echo "<small>".$row['created_at']."</small><br>";
    if($isSender){
        echo "<button onclick='deleteMessage({$row['id']})'>Delete</button>";
        echo "<button onclick='editMessage({$row['id']})'>Edit</button>";
    }
    echo "</div>";
}
