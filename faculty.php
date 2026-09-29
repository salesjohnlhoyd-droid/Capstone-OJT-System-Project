
<?php
session_start();
if ($_SESSION['role'] != "faculty") {
    header("Location: login.php");
    exit;
}

include "db.php";
include "mail.php";


$facultyId = $_SESSION['user_id'];

// Fetch faculty department
$deptQuery = $conn->prepare("SELECT department FROM faculty WHERE id=?");
$deptQuery->bind_param("i", $facultyId);
$deptQuery->execute();
$deptResult = $deptQuery->get_result();
$deptData = $deptResult->fetch_assoc();
$deptQuery->close();

// Always define $faculty_course, even if empty
$faculty_course = trim($deptData['department'] ?? '');

// Now $faculty_course is ready for all later department checks
$pageTitle = "Faculty Management";


/* ================= REQUIREMENT LABELS ================= */
$reqLabels = [
    "cert_registration"=>"Certification of Registration",
    "certificate_pdos"=>"Certificate of participation(PDOS)",
    "ojt_sheet"=>"On-the-job Training Program and information sheet",
    "application_sit"=>"Application for supervised Industrial Training",
    "waiver_form"=>"Waiver and permission form",
    "student_contract"=>"Student/University contract",
    "psych_result"=>"Psych Test Result",
    "medical_result"=>"Medical result"
];

$remarks = [
    "Blurry Image",
    "Wrong Document",
    "Incomplete Document",
    "Unreadable File",
    "Incorrect Format",
    "Expired Document",
    "Fake or Invalid"
];

/* ========================================================= */
/* ================= UPDATE PHOTO ========================== */
/* ========================================================= */
if(isset($_POST['update_photo'])){
    $user_id = $_POST['user_id'];

    // 🔒 Security: Ensure student belongs to faculty department
    $checkDept = $conn->prepare("SELECT course FROM users WHERE id=?");
    $checkDept->bind_param("i",$user_id);
    $checkDept->execute();
    $deptRes = $checkDept->get_result()->fetch_assoc();
    $checkDept->close();

    if($deptRes['course'] != $faculty_course){
        exit("Unauthorized access.");
    }

    $status = $_POST['photo_status'];
    $remark = ($status == "Denied") ? $_POST['photo_remark'] : NULL;

    if($status == "Denied" && empty($remark)){
        echo "<script>alert('Please select remark if Denied'); window.history.back();</script>";
        exit;
    }

    if($status == "Denied"){
        $stmt = $conn->prepare("
            UPDATE student_information
            SET student_photo = NULL,
                photo_status = 'Denied',
                photo_remark = ?
            WHERE user_id = ?
        ");
        $stmt->bind_param("si",$remark,$user_id);
    }else{
        $stmt = $conn->prepare("
            UPDATE student_information
            SET photo_status = ?,
                photo_remark = NULL
            WHERE user_id = ?
        ");
        $stmt->bind_param("si",$status,$user_id);
    }

    $stmt->execute();
    $stmt->close();

    /* ==== SAVE VERIFIED PHOTO ==== */
    if($status == "Verified"){

        $userQuery = $conn->prepare("
            SELECT first_name, middle_name, last_name 
            FROM users 
            WHERE id = ?
        ");
        $userQuery->bind_param("i", $user_id);
        $userQuery->execute();
        $resultUser = $userQuery->get_result();
        $userData = $resultUser->fetch_assoc();
        $userQuery->close();

        $safeFirst  = preg_replace("/[^a-zA-Z0-9]/", "_", $userData['first_name']);
        $safeMiddle = preg_replace("/[^a-zA-Z0-9]/", "_", $userData['middle_name']);
        $safeLast   = preg_replace("/[^a-zA-Z0-9]/", "_", $userData['last_name']);

        $folderName = !empty($safeMiddle) 
            ? $safeFirst . "_" . $safeMiddle . "_" . $safeLast
            : $safeFirst . "_" . $safeLast;

        if(!file_exists("uploads")) mkdir("uploads",0777,true);

        $uploadPath = "uploads/" . $folderName;
        if(!file_exists($uploadPath)) mkdir($uploadPath,0777,true);

        $photoQuery = $conn->prepare("
            SELECT student_photo 
            FROM student_information 
            WHERE user_id = ?
        ");
        $photoQuery->bind_param("i",$user_id);
        $photoQuery->execute();
        $photoResult = $photoQuery->get_result();
        $photoData = $photoResult->fetch_assoc();
        $photoQuery->close();

        if(!empty($photoData['student_photo'])){
            $filePath = $uploadPath . "/2x2_verified_" . time() . ".jpg";
            file_put_contents($filePath,$photoData['student_photo']);
        }
    }

    header("Location: faculty.php?msg=photo_updated");
    exit;
}

/* ========================================================= */
/* ================= UPDATE REQUIREMENT ==================== */
/* ========================================================= */
if(isset($_POST['update_requirement'])){

    $user_id = $_POST['user_id'];
    $type = $_POST['requirement_type'];

    // 🔒 Department Check
    $checkDept = $conn->prepare("SELECT course FROM users WHERE id=?");
    $checkDept->bind_param("i",$user_id);
    $checkDept->execute();
    $deptRes = $checkDept->get_result()->fetch_assoc();
    $checkDept->close();

    if($deptRes['course'] != $faculty_course){
        exit("Unauthorized access.");
    }

    $status = $_POST['status'];
    $remark = ($status == "Denied") ? $_POST['remark'] : NULL;

    if($status == "Denied" && empty($remark)){
        echo "<script>alert('Please select remark if Denied'); window.history.back();</script>";
        exit;
    }

    if($status == "Denied"){
        $stmt = $conn->prepare("
            UPDATE requirements
            SET file_name = NULL,
                status = 'Denied',
                remark = ?
            WHERE user_id = ?
            AND requirement_type = ?
        ");
        $stmt->bind_param("sis",$remark,$user_id,$type);
    }else{
        $stmt = $conn->prepare("
            UPDATE requirements
            SET status = ?,
                remark = NULL
            WHERE user_id = ?
            AND requirement_type = ?
        ");
        $stmt->bind_param("sis",$status,$user_id,$type);
    }

    $stmt->execute();
    $stmt->close();

    /* ==== SAVE VERIFIED REQUIREMENT ==== */
    if($status == "Verified"){

        $userQuery = $conn->prepare("
            SELECT first_name, middle_name, last_name 
            FROM users 
            WHERE id = ?
        ");
        $userQuery->bind_param("i", $user_id);
        $userQuery->execute();
        $resultUser = $userQuery->get_result();
        $userData = $resultUser->fetch_assoc();
        $userQuery->close();

        $safeFirst  = preg_replace("/[^a-zA-Z0-9]/", "_", $userData['first_name']);
        $safeMiddle = preg_replace("/[^a-zA-Z0-9]/", "_", $userData['middle_name']);
        $safeLast   = preg_replace("/[^a-zA-Z0-9]/", "_", $userData['last_name']);

        $folderName = !empty($safeMiddle) 
            ? $safeFirst . "_" . $safeMiddle . "_" . $safeLast
            : $safeFirst . "_" . $safeLast;

        if(!file_exists("uploads")) mkdir("uploads",0777,true);

        $uploadPath = "uploads/" . $folderName;
        if(!file_exists($uploadPath)) mkdir($uploadPath,0777,true);

        $fileQuery = $conn->prepare("
            SELECT file_name 
            FROM requirements 
            WHERE user_id = ?
            AND requirement_type = ?
        ");
        $fileQuery->bind_param("is",$user_id,$type);
        $fileQuery->execute();
        $fileResult = $fileQuery->get_result();
        $fileData = $fileResult->fetch_assoc();
        $fileQuery->close();

        if(!empty($fileData['file_name'])){
            $safeLabel = preg_replace("/[^a-zA-Z0-9]/", "_", $reqLabels[$type]);
            $filePath = $uploadPath . "/" . $safeLabel . "_verified_" . time() . ".jpg";
            file_put_contents($filePath,$fileData['file_name']);
        }
    }

    /* ==== EMAIL NOTIFICATION ==== */
    $userQuery = $conn->prepare("
        SELECT first_name, last_name, email 
        FROM users 
        WHERE id = ?
    ");
    $userQuery->bind_param("i", $user_id);
    $userQuery->execute();
    $resultUser = $userQuery->get_result();
    $userData = $resultUser->fetch_assoc();
    $userQuery->close();

    $fullName = $userData['first_name']." ".$userData['last_name'];
    $email = $userData['email'];
    $requirementLabel = $reqLabels[$type];

    sendStatusEmail($email, $fullName, $requirementLabel, $status, $remark);

    header("Location: faculty.php?msg=requirement_updated");
    exit;
}

/* ================= FETCH STUDENTS ================= */
// 🔹 Only show students in faculty department
$students = $conn->prepare("
    SELECT 
        u.id, u.first_name, u.middle_name, u.last_name, u.course,
        si.student_photo, si.photo_status, si.photo_remark,
        ci.company, ci.company_address, ci.telephone,
        ci.contact_first_name, ci.contact_middle_initial, ci.contact_last_name, ci.position
    FROM users u
    LEFT JOIN student_information si ON u.id = si.user_id
    LEFT JOIN company_information ci ON u.id = ci.user_id
    WHERE u.course LIKE ?
    ORDER BY u.last_name ASC
");
$likeDept = "%".$faculty_course."%";
$students->bind_param("s", $likeDept);
$students->execute();
$students = $students->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>NEUST Admin Portal</title>
    <title><?php echo $pageTitle; ?> </title>
    <link rel="stylesheet" href="administrator.css?v=<?= time(); ?>">
 
    <!-- ================= IMAGE PREVIEW MODAL ================= -->
    <div id="imagePreviewModal" style="
        display:none;
        position:fixed;
        top:0;
        left:0;
        width:100%;
        height:100%;
        background:rgba(0,0,0,0.85);
        justify-content:center;
        align-items:center;
        z-index:9999;
    ">

        <!-- Close Button -->
        <span onclick="closePreview()" style="
            position:absolute;
            top:20px;
            right:40px;
            font-size:45px;
            color:white;
            cursor:pointer;
            font-weight:bold;
        ">&times;</span>

        <!-- Image -->
        <img id="previewImage" style="
            max-width:90%;
            max-height:90%;
            border-radius:12px;
            box-shadow:0 0 25px rgba(0,0,0,0.8);
        ">
    </div>

    
</head>
<body>

<nav class="navbar">
    <div class="logo-section">
        <img src="logo.webp" class="university-logo" alt="NEUST Logo">
        <div>
            <div style="font-weight:bold; font-size:1.2rem; color:white;">NEUST</div>
            <div style="font-size:0.8rem; color:#ffcc00;">OJT VALIDATION SYSTEM</div>
        </div>
    </div>
    <a href="login.php" class="logout-btn">Logout</a>
</nav>

<div class="container">
    <div class="filter-nav">
        <input type="text" id="searchInput" placeholder="Search by student name..." class="search-bar" onkeyup="filterAll()">


        <!-- Status Filter -->
        <select class="filter-item" id="statusFilter" onchange="filterAll()">
            <option value="All">All Status</option>
            <option value="Pending">Pending</option>
            <option value="Verified">Verified</option>
        </select>
    </div>


    <?php while($student = $students->fetch_assoc()): 
        $user_id = $student['id'];
        $name = $student['first_name']." ".$student['middle_name']." ".$student['last_name'];

                    /* ================= COMPUTE OVERALL STATUS ================= */

            // Default assume Verified
            $overallStatus = "Verified";

            // Check photo
            if ($student['photo_status'] != "Verified") {
                $overallStatus = "Pending";
            }

            // Check requirements
            $reqCheck = $conn->prepare("
                SELECT status FROM requirements WHERE user_id = ?
            ");
            $reqCheck->bind_param("i", $user_id);
            $reqCheck->execute();
            $reqResult = $reqCheck->get_result();

            while ($reqRow = $reqResult->fetch_assoc()) {
                if ($reqRow['status'] != "Verified") {
                    $overallStatus = "Pending";
                    break;
                }
            }
            $reqCheck->close();

    ?>
    <div class="student-row">
        <input type="checkbox" id="user_<?= $user_id ?>" class="toggle-input" style="display:none;">
        <label for="user_<?= $user_id ?>" class="row-summary">
            <span><?= htmlspecialchars($name) ?></span>
            <span style="color:#666; font-size:0.9rem;"><?= htmlspecialchars($student['course']) ?></span>
            <span style="color:<?= ($overallStatus=='Verified') ? '#1cc88a':'#f6c23e' ?>;">
                ● <?= $overallStatus ?>
            </span>

        </label>

        <div class="details-pane">
            <div class="detail-grid">

                <!-- Requirements Section -->
                <div class="req-list">
                    <h4 style="color: #0038a8;">Document Verification</h4>
                    <?php foreach($reqLabels as $type => $label): 
                        $stmt = $conn->prepare("SELECT file_name, status, remark FROM requirements WHERE user_id = ? AND requirement_type = ?");
                        $stmt->bind_param("is",$user_id,$type); 
                        $stmt->execute();
                        $res = $stmt->get_result()->fetch_assoc();
                    ?>
                    <div class="req-item">
                        <?php if($res && $res['file_name']): ?>
                            <img src="data:image/jpeg;base64,<?= base64_encode($res['file_name']) ?>" 
                            onclick="openPreview(this.src)" 
                            title="Click to preview"
                            style="cursor:pointer;">
                        <?php else: ?>
                            <div style="width:60px; height:60px; background:#f0f0f0; border-radius:6px; display:flex; align-items:center; justify-content:center; font-size:10px;">N/A</div>
                        <?php endif; ?>
                        <div style="flex:1;">
                            <div style="font-weight:600; font-size:0.9rem;"><?= $label ?></div>
                            <form method="POST" style="margin-top:5px; display:flex; gap:10px; align-items:center;">
                                <input type="hidden" name="user_id" value="<?= $user_id ?>">
                                <input type="hidden" name="requirement_type" value="<?= $type ?>">
                                <select name="status" onchange="toggleRemark(this,'rem_<?= $user_id.$type ?>')">
                                    <option value="Pending" <?= ($res && $res['status']=="Pending")?"selected":"" ?>>Pending</option>
                                    <option value="Verified" <?= ($res && $res['status']=="Verified")?"selected":"" ?>>Verified</option>
                                    <option value="Denied" <?= ($res && $res['status']=="Denied")?"selected":"" ?>>Denied</option>
                                </select>
                                <button name="update_requirement" class="btn-update">Save</button>

                                <select name="remark" id="rem_<?= $user_id.$type ?>" class="remark-select" 
                                        style="<?= ($res && $res['status']=="Denied")?'display:inline-block':'display:none' ?>">
                                    <option value="">Reason</option>
                                    <?php foreach($remarks as $r): ?>
                                        <option value="<?= $r ?>" <?= ($res && $res['remark']==$r)?"selected":"" ?>><?= $r ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Photo & Company Section -->
                <div class="info-section">
                    <h4 style="color: #0038a8; border-bottom:2px solid #ffcc00; padding-bottom:5px;">Company Info</h4>
                    <p><strong>Company:</strong> <?= htmlspecialchars($student['company'] ?? 'N/A') ?></p>
                    <p><strong>Supervisor:</strong> <?= htmlspecialchars($student['contact_first_name'] . " " . $student['contact_last_name']) ?></p>
                    <p><strong>Telephone:</strong> <?= htmlspecialchars($student['telephone'] ?? 'N/A') ?></p>

                    <div style="margin-top:30px; background:white; padding:20px; border-radius:10px; text-align:center; border:1px solid #eee;">
                        <h5 style="margin-top:0;">Official 2x2 Photo</h5>
                        <?php if($student['student_photo']): ?>
                            <img src="data:image/jpeg;base64,<?= base64_encode($student['student_photo']) ?>" 
                            class="profile-img-large"
                            onclick="openPreview(this.src)"
                            style="cursor:pointer;">
                        <?php endif; ?>

                        <form method="POST" style="margin-top:10px;">
                            <input type="hidden" name="user_id" value="<?= $user_id ?>">
                            <select name="photo_status" onchange="togglePhotoRemark(this,'photo_rem_<?= $user_id ?>')">
                                <option value="Pending" <?= ($student['photo_status']=="Pending")?"selected":"" ?>>Pending</option>
                                <option value="Verified" <?= ($student['photo_status']=="Verified")?"selected":"" ?>>Verified</option>
                                <option value="Denied" <?= ($student['photo_status']=="Denied")?"selected":"" ?>>Denied</option>
                            </select>

                            <select name="photo_remark" id="photo_rem_<?= $user_id ?>" class="remark-select"
                                    style="<?= ($student['photo_status']=="Denied")?'display:inline-block':'display:none' ?>">
                                <option value="">Reason</option>
                                <?php foreach($remarks as $r): ?>
                                    <option value="<?= $r ?>" <?= ($student['photo_remark']==$r)?"selected":"" ?>><?= $r ?></option>
                                <?php endforeach; ?>
                            </select>

                            <button name="update_photo" class="btn-update">Update ID</button>
                        </form>
                    </div>
                </div>

            </div>
        </div>
    </div>
    <?php endwhile; ?>
</div>

<script>
    /* ================= IMAGE PREVIEW FUNCTIONS ================= */

        function openPreview(src) {
            document.getElementById("previewImage").src = src;
            document.getElementById("imagePreviewModal").style.display = "flex";
        }

        function closePreview() {
            document.getElementById("imagePreviewModal").style.display = "none";
        }

        /* Close when clicking outside image */
        document.getElementById("imagePreviewModal").addEventListener("click", function(e) {
            if (e.target === this) {
                closePreview();
            }
        });

    
    // Toggle remark for photo
    function togglePhotoRemark(select, id) {
        const remark = document.getElementById(id);
        if (select.value === "Denied") {
            remark.style.display = "inline-block";
            remark.required = true;
        } else {
            remark.style.display = "none";
            remark.required = false;
            remark.value = "";
        }
    }

    // Toggle remark for requirements
    function toggleRemark(select, id) {
        const remark = document.getElementById(id);
        if (select.value === "Denied") {
            remark.style.display = "inline-block";
            remark.required = true;
        } else {
            remark.style.display = "none";
            remark.required = false;
            remark.value = "";
        }
    }

    // Search function
    function filterAll() {

    let nameInput = document.getElementById('searchInput').value.toUpperCase();
    let statusFilter = document.getElementById('statusFilter').value;

    let rows = document.getElementsByClassName('student-row');

    for (let i = 0; i < rows.length; i++) {

        let summary = rows[i].getElementsByClassName('row-summary')[0];
        let spans = summary.getElementsByTagName('span');

        let nameText = spans[0].innerText.toUpperCase();
        let statusText = spans[1].innerText.replace("●", "").trim();

        let matchName = nameText.indexOf(nameInput) > -1;
        let matchStatus = (statusFilter === "All" || statusText === statusFilter);

        if (matchName && matchStatus) {
            rows[i].style.display = "";
        } else {
            rows[i].style.display = "none";
        }
    }
}


    /* ============================= */
/* Allow Only One Expanded Row   */
/* ============================= */

document.addEventListener("DOMContentLoaded", function () {

    const toggles = document.querySelectorAll(".toggle-input");

    toggles.forEach(toggle => {
        toggle.addEventListener("change", function () {

            if (this.checked) {

                toggles.forEach(otherToggle => {
                    if (otherToggle !== this) {
                        otherToggle.checked = false;
                    }
                });

            }

        });
    });

});

const sidebar = document.getElementById('sidebar');
const toggleBtn = document.getElementById('toggleBtn');

toggleBtn.addEventListener('click', () => {
    sidebar.classList.toggle('collapsed');
    toggleBtn.textContent = sidebar.classList.contains('collapsed') ? '⮞' : '⮜';
});

</script>
</body>
</html>