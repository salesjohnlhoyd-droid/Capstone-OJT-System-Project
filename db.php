<?php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "ojt_db";

// Enable detailed error reporting
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = mysqli_connect($servername, $username, $password, $dbname);
} catch (Exception $e) {
    echo "<h1>Database Connection Failed</h1>";
    echo "<p>Error: " . $e->getMessage() . "</p>";
    exit; // Stop everything so we can read the error
}
?>