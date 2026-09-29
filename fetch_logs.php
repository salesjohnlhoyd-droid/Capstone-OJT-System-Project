<?php
require_once "db.php";

// Select full name parts and log details
$sql = "SELECT l.details, l.created_at, u.first_name, u.last_name, u.role 
        FROM activity_logs l 
        JOIN users u ON l.user_id = u.id 
        ORDER BY l.created_at DESC LIMIT 10";

$res = $conn->query($sql);

if($res && $res->num_rows > 0) {
    while($row = $res->fetch_assoc()){
        // Formatting: Title Case for Name and Role
        $fullName = ucwords(strtolower($row['first_name'] . ' ' . $row['last_name']));
        $role = ucfirst(strtolower($row['role'])); 
        $time = date("h:i A", strtotime($row['created_at']));

        echo '<div style="padding: 10px 15px; border-bottom: 1px solid #ececec; display: flex; justify-content: space-between; align-items: center; font-family: sans-serif;">';
        
        // Display: Full Name (Role) Action
        echo '<span style="color: #333; font-size: 0.9rem;">';
        echo '<strong>' . $fullName . '</strong> ';
        echo '<span style="color: #666; font-weight: 500;">(' . $role . ')</span> ';
        echo $row['details'];
        echo '</span>';
        
        // Professional Grey Timestamp
        echo '<span style="color: #888; font-size: 0.8rem; min-width: 80px; text-align: right;">' . $time . '</span>';
        
        echo '</div>';
    }
} else {
    echo '<div style="padding: 20px; color: #999; text-align: center; font-size: 0.85rem;">No recent activity recorded.</div>';
}
?>