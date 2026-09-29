<?php
session_start();
include "db.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != "admin") {
    die("Access denied.");
}

$search = $_GET['search'] ?? '';

/* ================= QUERY ================= */
$query = "
SELECT 
cp.user_id AS company_id,
ci.company
FROM company_profile cp
LEFT JOIN company_information ci 
ON cp.user_id = ci.user_id
WHERE ci.company IS NOT NULL
";

/* SEARCH */
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
<title>Admin Companies</title>
<link rel="stylesheet" href="admin_companies.css">
</head>
<body>

<h1>🏢 Companies</h1>

<!-- SEARCH -->
<div class="controls">
    <input 
        type="text" 
        id="search" 
        placeholder="Search companies..." 
        value="<?= htmlspecialchars($search) ?>">
</div>

<!-- GRID -->
<div class="company-grid">

<?php if($result->num_rows > 0): ?>

    <?php while($row = $result->fetch_assoc()): ?>
        <a href="admin_reports.php?company_id=<?= $row['company_id'] ?>" class="company-card">
            
            <div class="company-name">
                <?= htmlspecialchars($row['company']) ?>
            </div>

            <div class="company-action">
                View Reports →
            </div>

        </a>
    <?php endwhile; ?>

<?php else: ?>

<div class="no-data">
    No companies found.
</div>

<?php endif; ?>

</div>

<script>

let timer;

document.getElementById("search").addEventListener("keyup", function(){
    clearTimeout(timer);

    let value = this.value;

    timer = setTimeout(function(){
        window.location = "?search=" + encodeURIComponent(value);
    }, 500); // delay bago mag reload
});

</script>

</body>
</html>