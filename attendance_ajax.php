<?php
session_start();
include "db.php";

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$company_id = $_SESSION['user_id'];
$date = $_GET['date'] ?? date('Y-m-d');

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['success' => false, 'message' => 'Invalid date format']);
    exit;
}

// Check if date is weekend
$dow = (int)date('w', strtotime($date));
$is_weekend = ($dow === 0 || $dow === 6);
$day_name = date('l', strtotime($date));

if ($is_weekend) {
    // For weekends, just return students with DAY OFF status
    $students_query = "
        SELECT u.id, u.first_name, u.middle_name, u.last_name
        FROM ojt_assignments oa
        JOIN users u ON oa.student_id = u.id
        WHERE oa.company_id = ?
        ORDER BY u.first_name ASC
    ";
    $stmt = $conn->prepare($students_query);
    $stmt->bind_param("i", $company_id);
    $stmt->execute();
    $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $mn = trim($student['middle_name'] ?? '');
    $rows = [];
    foreach ($students as $idx => $student) {
        $mn = trim($student['middle_name'] ?? '');
        $rows[] = [
            'id' => $student['id'],
            'name' => trim($student['first_name'] . ($mn ? ' ' . $mn : '') . ' ' . $student['last_name']),
            'status' => 'DAY OFF',
            'am_time_in' => null,
            'am_time_in_photo' => null,
            'am_time_out' => null,
            'am_time_out_photo' => null,
            'pm_time_in' => null,
            'pm_time_in_photo' => null,
            'pm_time_out' => null,
            'pm_time_out_photo' => null,
        ];
    }
    
    echo json_encode([
        'success' => true,
        'date' => $date,
        'is_weekend' => true,
        'day_name' => $day_name,
        'rows' => $rows
    ]);
    exit;
}

// For weekdays, get attendance data with proper status calculation
$query = "
SELECT
    u.id as student_id,
    u.first_name,
    u.middle_name,
    u.last_name,
    a.am_time_in, 
    a.am_time_out, 
    a.am_time_in_photo, 
    a.am_time_out_photo,
    a.pm_time_in, 
    a.pm_time_out, 
    a.pm_time_in_photo, 
    a.pm_time_out_photo,
    CASE
        -- FIXED: Check if all four entries are 'missed' or NULL → ABSENT
        WHEN (a.am_time_in = 'missed' OR a.am_time_in IS NULL OR a.am_time_in = '')
         AND (a.am_time_out = 'missed' OR a.am_time_out IS NULL OR a.am_time_out = '')
         AND (a.pm_time_in = 'missed' OR a.pm_time_in IS NULL OR a.pm_time_in = '')
         AND (a.pm_time_out = 'missed' OR a.pm_time_out IS NULL OR a.pm_time_out = '') THEN 'ABSENT'
        WHEN (a.am_time_in IS NOT NULL AND a.am_time_in != '' AND a.am_time_in != 'missed')
         AND (a.am_time_out IS NOT NULL AND a.am_time_out != '' AND a.am_time_out != 'missed')
         AND (a.pm_time_in IS NOT NULL AND a.pm_time_in != '' AND a.pm_time_in != 'missed')
         AND (a.pm_time_out IS NOT NULL AND a.pm_time_out != '' AND a.pm_time_out != 'missed') THEN 'PRESENT'
        WHEN (a.am_time_in = 'missed' OR a.am_time_out = 'missed'
           OR a.pm_time_in = 'missed' OR a.pm_time_out = 'missed'
           OR (a.am_time_in IS NOT NULL AND a.am_time_in != '' AND a.am_time_in != 'missed')
           OR (a.pm_time_in IS NOT NULL AND a.pm_time_in != '' AND a.pm_time_in != 'missed')) THEN 'INCOMPLETE'
        WHEN a.id IS NULL THEN 'ABSENT'
        ELSE 'ABSENT'
    END AS status,
    -- Helper to format time for display
    TIME_FORMAT(a.am_time_in, '%h:%i %p') as am_time_in_fmt,
    TIME_FORMAT(a.am_time_out, '%h:%i %p') as am_time_out_fmt,
    TIME_FORMAT(a.pm_time_in, '%h:%i %p') as pm_time_in_fmt,
    TIME_FORMAT(a.pm_time_out, '%h:%i %p') as pm_time_out_fmt
FROM ojt_assignments oa
JOIN users u ON oa.student_id = u.id
LEFT JOIN attendance_logs a
    ON a.user_id    = oa.student_id
   AND a.company_id = oa.company_id
   AND a.date       = ?
WHERE oa.company_id = ?
ORDER BY u.first_name ASC
";

$stmt = $conn->prepare($query);
$stmt->bind_param("si", $date, $company_id);
$stmt->execute();
$result = $stmt->get_result();

$rows = [];
while ($row = $result->fetch_assoc()) {
    // Convert BLOB photos to base64 for display
    $am_in_photo = !empty($row['am_time_in_photo']) ? base64_encode($row['am_time_in_photo']) : null;
    $am_out_photo = !empty($row['am_time_out_photo']) ? base64_encode($row['am_time_out_photo']) : null;
    $pm_in_photo = !empty($row['pm_time_in_photo']) ? base64_encode($row['pm_time_in_photo']) : null;
    $pm_out_photo = !empty($row['pm_time_out_photo']) ? base64_encode($row['pm_time_out_photo']) : null;
    
    // Format times for display
    $am_time_in = $row['am_time_in_fmt'] ?: ($row['am_time_in'] === 'missed' ? 'MISSED' : ($row['am_time_in'] ?: '-'));
    $am_time_out = $row['am_time_out_fmt'] ?: ($row['am_time_out'] === 'missed' ? 'MISSED' : ($row['am_time_out'] ?: '-'));
    $pm_time_in = $row['pm_time_in_fmt'] ?: ($row['pm_time_in'] === 'missed' ? 'MISSED' : ($row['pm_time_in'] ?: '-'));
    $pm_time_out = $row['pm_time_out_fmt'] ?: ($row['pm_time_out'] === 'missed' ? 'MISSED' : ($row['pm_time_out'] ?: '-'));
    $mn = trim($row['middle_name'] ?? '');
    $rows[] = [
        'id' => $row['student_id'],     
        'name' => trim($row['first_name'] . ($mn ? ' ' . $mn : '') . ' ' . $row['last_name']),
        'status' => $row['status'],
        'am_time_in' => $am_time_in,
        'am_time_in_photo' => $am_in_photo,
        'am_time_out' => $am_time_out,
        'am_time_out_photo' => $am_out_photo,
        'pm_time_in' => $pm_time_in,
        'pm_time_in_photo' => $pm_in_photo,
        'pm_time_out' => $pm_time_out,
        'pm_time_out_photo' => $pm_out_photo,
    ];
}

echo json_encode([
    'success' => true,
    'date' => $date,
    'is_weekend' => false,
    'day_name' => $day_name,
    'rows' => $rows
]);
?>