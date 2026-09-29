<?php
include "db.php";

$first_name = "System";
$last_name  = "Administrator";
$email      = "admin@neust.edu.ph";
$password   = "Admin123!";

// Hash password properly
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

// Check if already exists
$check = $conn->prepare("SELECT id FROM admins WHERE email = ?");
$check->bind_param("s", $email);
$check->execute();
$result = $check->get_result();

if ($result->num_rows > 0) {
    echo "Admin already exists.";
} else {

    $stmt = $conn->prepare("INSERT INTO admins (first_name, last_name, email, password) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $first_name, $last_name, $email, $hashed_password);

    if ($stmt->execute()) {
        echo "Default admin created successfully!";
    } else {
        echo "Error creating admin.";
    }

    $stmt->close();
}

$check->close();
$conn->close();
?>
