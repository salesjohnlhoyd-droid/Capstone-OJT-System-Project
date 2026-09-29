<?php
// Get inserted user ID
$newUserId = $stmt->insert_id;

// Log account creation
logActivity(
    $conn,
    $newUserId,
    "Account Created",
    "$role account created for $email"
);
?>