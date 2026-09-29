<?php
session_start();
include "db.php";

/* ================= AUTH ================= */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != "faculty") {
    die("Access denied.");
}

/* ================= SEARCH ================= */
$search = $_GET['search'] ?? '';

/* ================= GET ALL COMPANIES ================= */
$query = "
SELECT
cp.user_id AS company_id,
ci.company
FROM company_profile cp
LEFT JOIN company_information ci 
ON cp.user_id = ci.user_id
WHERE ci.company IS NOT NULL
";

/* SEARCH FILTER */
if (!empty($search)) {
    $query .= " AND ci.company LIKE ?";
}

$query .= " ORDER BY ci.company ASC";

/* PREPARE */
$stmt = $conn->prepare($query);

if (!empty($search)) {
    $searchParam = "%$search%";
    $stmt->bind_param("s", $searchParam);
}

$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html>
<head>
<title>Select Company</title>
<link rel="stylesheet" href="faculty_companies.css">
</head>

<body>

<div class="container">

<!-- HEADER -->
<div class="header">
<h1>🏢 Select Company</h1>
<p>Select company for OJT student attendance</p>
</div>

<!-- SEARCH -->
<div class="top-bar">
<input 
type="text" 
id="search"
placeholder="Search company..."
value="<?= htmlspecialchars($search) ?>">
</div>

<!-- COMPANY LIST -->
<div class="company-list">

<?php if ($result->num_rows > 0): ?>

<?php while($row = $result->fetch_assoc()): ?>
<a href="faculty_reports.php?company_id=<?= $row['company_id'] ?>" class="card">

<div class="company-name">
🏢 <?= htmlspecialchars($row['company']) ?>
</div>

<div class="select-btn">
Select →
</div>

</a>
<?php endwhile; ?>

<?php else: ?>

<div class="no-data">No companies found.</div>

<?php endif; ?>

</div>

</div>

<script>

let timer;

document.getElementById("search").addEventListener("keyup", function(){
    clearTimeout(timer);

    let value = this.value;

    timer = setTimeout(function(){
        window.location = "?search=" + encodeURIComponent(value);
    }, 500); // same as admin
});

</script>

</body>
</html>