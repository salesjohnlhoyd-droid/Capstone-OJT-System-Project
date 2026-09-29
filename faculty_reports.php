<?php
session_start();
include "db.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != "faculty") {
    die("Access denied.");
}

$faculty_id = $_SESSION['user_id'];

/* GET FACULTY (REMOVED course_id) */
$stmt = $conn->prepare("SELECT department FROM faculty WHERE id=?");
$stmt->bind_param("i", $faculty_id);
$stmt->execute();
$faculty = $stmt->get_result()->fetch_assoc();

/* COMPANY */
$company_id = $_GET['company_id'] ?? 0;

/* FILTERS */
$date = $_GET['date'] ?? date("Y-m-d");
$search = $_GET['search'] ?? '';
$course = $_GET['course'] ?? '';

$search_param = "%$search%";

/* WEEK */
$week_start = date("Y-m-d", strtotime("monday this week", strtotime($date)));
$today_week = date("Y-m-d", strtotime("monday this week"));

/* QUERY */
$query = "
SELECT 
u.id as student_id,
u.first_name,
u.last_name,
u.course,

r.id as report_id,
r.submitted_at,
r.report_file,
r.remark,
r.feedback,
r.company_grade,
r.faculty_grade

FROM ojt_assignments oa
JOIN users u ON oa.student_id = u.id

LEFT JOIN reports r 
ON r.user_id = u.id
AND r.company_id = oa.company_id
AND r.week_start = ?

WHERE oa.company_id = ?
";

/* APPLY COURSE FILTER */
if (!empty($course)) {
    $query .= " AND u.course = ?";
}

$query .= "
AND (u.first_name LIKE ? OR u.last_name LIKE ?)
GROUP BY u.id
ORDER BY u.first_name ASC
";

/* PREPARE */
$stmt = $conn->prepare($query);

if (!empty($course)) {
    $stmt->bind_param("sisss", $week_start, $company_id, $course, $search_param, $search_param);
} else {
    $stmt->bind_param("siss", $week_start, $company_id, $search_param, $search_param);
}

$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html>
<head>
<title>Faculty Reports</title>
<link rel="stylesheet" href="faculty_report.css">
</head>
<body>

<h1>Faculty Reports</h1>
<a href="faculty_companies.php" class="back-btn">⬅ Back to Companies</a>

<!-- FILTERS -->
<div class="controls">

<!-- COURSE FILTER -->
<select id="course">
    <option value="">All Courses</option>
    <option value="Bachelor of Science in Information Technology"
        <?= ($course == "Bachelor of Science in Information Technology") ? 'selected' : '' ?>>
        BSIT
    </option>
    <option value="Bachelor of Science in Business Administration"
        <?= ($course == "Bachelor of Science in Business Administration") ? 'selected' : '' ?>>
        BSBA
    </option>
    <option value="Bachelor of Science in Entrepreneurship"
        <?= ($course == "Bachelor of Science in Entrepreneurship") ? 'selected' : '' ?>>
        BSEntrep
    </option>
</select>

<!-- DATE -->
<input type="date" id="date" value="<?= $date ?>">

<!-- SEARCH -->
<input type="text" id="search" placeholder="Search students..." value="<?= htmlspecialchars($search) ?>">

</div>

<!-- HEADER -->
<div class="table-header">
    <div>Name</div>
    <div>Date Submitted</div>
    <div>Status</div>
    <div>Company Grade</div>
    <div>Faculty Grade</div>
    <div></div>
</div>

<div class="report-list">

<?php while($row = $result->fetch_assoc()): 

$status = "No Report Submitted";
$statusClass = "none";

if ($row['report_file']) {
    $status = "Has Report";
    $statusClass = "report";
} elseif ($week_start > $today_week) {
    $status = "Pending Submission";
    $statusClass = "pending";
}

$isLocked = $row['faculty_grade'] !== null;
?>

<div class="report-card">

<div class="report-row" onclick="toggleDetails(this)">
    <div><?= $row['first_name']." ".$row['last_name'] ?></div>

    <div>
        <?= $row['submitted_at'] 
        ? date("m/d/Y", strtotime($row['submitted_at'])) 
        : '—' ?>
    </div>

    <div class="status <?= $statusClass ?>"><?= $status ?></div>

    <div><?= $row['company_grade'] ? $row['company_grade']."/100" : '-' ?></div>

    <div><?= $row['faculty_grade'] ? $row['faculty_grade']."/100" : '-' ?></div>

    <div class="arrow">▼</div>
</div>

<div class="report-details">

<p><strong>Course:</strong> <?= $row['course'] ?></p>

<p><strong>Remarks:</strong> <?= $row['remark'] ?? '-' ?></p>

<p><strong>Company Feedback:</strong> <?= $row['feedback'] ?? '-' ?></p>

<p><strong>Report File:</strong>
<?php if($row['report_file']): ?>
<a href="<?= $row['report_file'] ?>" target="_blank">View Report</a>
<?php else: ?>
<?= $status ?>
<?php endif; ?>
</p>

<?php if($row['report_id']): ?>

<form method="POST" action="save_faculty_grade.php" class="edit-form">

<input type="hidden" name="report_id" value="<?= $row['report_id'] ?>">

<input type="number"
name="faculty_grade"
value="<?= $row['faculty_grade'] ?>"
min="0"
max="100"
class="grade-input"
<?= $isLocked ? 'disabled' : '' ?>
required>

<?php if(!$isLocked): ?>
<button type="button" class="save-btn" onclick="openModal(this)">
Save Grade
</button>
<?php else: ?>
<button type="button" class="edit-btn" onclick="confirmEdit(this)">
Edit Grade
</button>
<?php endif; ?>

</form>

<?php endif; ?>

</div>
</div>

<?php endwhile; ?>

</div>

<!-- MODAL -->
<div id="confirmModal" class="modal">
  <div class="modal-content">
    <p id="modalText">Are you sure?</p>
    <div class="modal-actions">
      <button id="confirmYes">Yes</button>
      <button onclick="closeModal()">Cancel</button>
    </div>
  </div>
</div>

<script>

/* FILTER */
function reload(){
let s = document.getElementById("search").value;
let d = document.getElementById("date").value;
let c = document.getElementById("course").value;
let company = <?= $company_id ?>;

window.location = `?company_id=${company}&search=${s}&date=${d}&course=${c}`;
}

document.getElementById("search").addEventListener("keyup", reload);
document.getElementById("date").addEventListener("change", reload);
document.getElementById("course").addEventListener("change", reload);

/* TOGGLE */
function toggleDetails(row){
let card = row.parentElement;

document.querySelectorAll(".report-card").forEach(c => {
if(c !== card) c.classList.remove("active");
});

card.classList.toggle("active");

let arrow = row.querySelector(".arrow");
arrow.textContent = card.classList.contains("active") ? "▲" : "▼";
}

/* MODAL */
let currentForm = null;
let currentInput = null;
let mode = "";

function openModal(btn){
currentForm = btn.closest("form");
mode = "save";
document.getElementById("modalText").innerText =
"Are you sure you want to save this grade?";
document.getElementById("confirmModal").style.display = "flex";
}

function confirmEdit(btn){
currentForm = btn.closest("form");
currentInput = currentForm.querySelector(".grade-input");
mode = "edit";
document.getElementById("modalText").innerText =
"Are you sure you want to edit this grade?";
document.getElementById("confirmModal").style.display = "flex";
}

function closeModal(){
document.getElementById("confirmModal").style.display = "none";
}

document.getElementById("confirmYes").onclick = function(){
if(mode === "save") currentForm.submit();
if(mode === "edit"){
    currentInput.disabled = false;
    currentInput.focus();
}
closeModal();
};

</script>

</body>
</html>