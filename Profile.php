<?php
session_start();
include "db.php";

$pageTitle = "Create Account"; 
if (basename($_SERVER['PHP_SELF']) == 'CompanyForm.php') $pageTitle = "Company Requirements";
if (basename($_SERVER['PHP_SELF']) == 'Profile.php') $pageTitle = "Profile";
if (basename($_SERVER['PHP_SELF']) == 'add_ojt_student.php') $pageTitle = "OJT Student Management";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require 'PHPMailer/src/Exception.php';

// ================= SESSION & USER =================
$current_user_email = $_SESSION['user_email'] ?? '';
$current_user_id    = $_SESSION['user_id'] ?? 0;
$current_user_role  = $_SESSION['role'] ?? 'company';

if (!$current_user_id) {
    if (isset($_GET['load_messages']) || isset($_POST['mode']) || isset($_GET['get_last_admin'])) {
        http_response_code(403);
        exit("Session expired");
    }
    header("Location: login.php");
    exit;
}
// Fetch pending late requests count (sidebar badge)
$pending_lr_count = 0;
$stmt_plr = $conn->prepare("SELECT COUNT(*) as total FROM late_requests WHERE company_id=? AND status='pending'");
$stmt_plr->bind_param("i", $current_user_id);
$stmt_plr->execute();
$res_plr = $stmt_plr->get_result()->fetch_assoc();
$pending_lr_count = $res_plr['total'] ?? 0;
$stmt_plr->close();
// ================= FETCH INBOX COUNT (ADDED ONLY) =================
$inbox_count = 0;
$stmt_inbox = $conn->prepare("SELECT COUNT(*) as total FROM ojt_applications WHERE company_id=? AND phase='pending'");
$stmt_inbox->bind_param("i", $current_user_id);
$stmt_inbox->execute();
$res_inbox = $stmt_inbox->get_result()->fetch_assoc();
$inbox_count = $res_inbox['total'] ?? 0;
$stmt_inbox->close();

// ================= FETCH UNGRADED COUNT =================
$ungraded_count = 0;
$stmt_ungraded = $conn->prepare("
    SELECT COUNT(*) as total
    FROM reports r
    JOIN ojt_assignments oa ON oa.student_id = r.user_id AND oa.company_id = r.company_id
    WHERE r.company_id = ?
      AND r.week_start <= CURDATE()
      AND (r.remark IS NULL OR r.remark != 'Wrong Document')
      AND r.company_grade IS NULL
");
$stmt_ungraded->bind_param("i", $current_user_id);
$stmt_ungraded->execute();
$res_ungraded = $stmt_ungraded->get_result()->fetch_assoc();
$ungraded_count = $res_ungraded['total'] ?? 0;
$stmt_ungraded->close();


// ================= FETCH ACTUAL ADMIN EMAIL =================
$stmt_admin = $conn->prepare("SELECT email FROM admins LIMIT 1");
$stmt_admin->execute();
$result_admin = $stmt_admin->get_result();
$admin_row = $result_admin->fetch_assoc();
$admin_email = $admin_row['email'] ?? 'admin@example.com';
$stmt_admin->close();

// ================= FETCH COMPANY PROFILE =================
$stmt = $conn->prepare("SELECT email, telephone, facebook_link, google_map_link FROM company_profile WHERE user_id=?");
$stmt->bind_param("i",$current_user_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Ensure session email is always available
if (empty($current_user_email) && !empty($row['email'])) {
    $_SESSION['user_email'] = $row['email'];
    $current_user_email = $row['email'];
}

// ================= FETCH COMPANY NAME & FULL NAME =================
$stmt_user = $conn->prepare("SELECT company, contact_first_name, contact_middle_initial, contact_last_name FROM company_information WHERE user_id=?");
$stmt_user->bind_param("i",$current_user_id);
$stmt_user->execute();
$row_user = $stmt_user->get_result()->fetch_assoc();
$stmt_user->close();

$company_name = $row_user['company'] ?? '';
$full_name = trim(($row_user['contact_first_name'] ?? '').' '.($row_user['contact_middle_initial'] ?? '').' '.($row_user['contact_last_name'] ?? ''));
$user_email = $profile['email'] ?? ($_SESSION['user_email'] ?? 'Not available');

// Clean Google Map
$google_map_src = '';
if (!empty($row['google_map_link'])){
    if (preg_match('/<iframe.*?src=["\']([^"\']+)["\'].*?>/i', $row['google_map_link'], $matches)) $google_map_src=$matches[1];
    else $google_map_src=$row['google_map_link'];
}

// ================= AJAX HANDLERS (CHAT) =================
if(isset($_GET['load_messages'])){
    $stmt = $conn->prepare("SELECT * FROM company_messages WHERE company_id=? ORDER BY created_at ASC");
    $stmt->bind_param("i", $current_user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while($msg = $res->fetch_assoc()){
        $isMe = ($msg['sender_email'] === $user_email);
        echo "<div style='margin-bottom:12px; display:flex; flex-direction:column; align-items:".($isMe ? "flex-end" : "flex-start")."'>";
        echo "<div style='max-width:80%; padding:10px; border-radius:12px; font-size:13px; background:".($isMe ? "#07145f" : "#e2e8f0")."; color:".($isMe ? "white" : "#333")."'>";
        echo nl2br(htmlspecialchars($msg['message']));
        if($msg['edited']) echo " <small style='opacity:0.7'>(edited)</small>";
        echo "</div>";
        echo "<div style='font-size:10px; color:gray; margin-top:4px;'>".$msg['created_at']."</div>";
        if($isMe && $msg['message'] !== 'Message removed'){
            echo "<div style='margin-top:2px;'><button onclick='editMessage(".$msg['id'].")' style='border:none; background:none; font-size:10px; color:#0038a8; cursor:pointer;'>Edit</button>";
            echo " <button onclick='deleteMessage(".$msg['id'].")' style='border:none; background:none; font-size:10px; color:red; cursor:pointer;'>Delete</button></div>";
        }
        echo "</div>";
    }
    exit;
}

if(isset($_POST['mode'])){
    $msg = trim($_POST['message'] ?? '');
    $mid = intval($_POST['message_id'] ?? 0);

    if($_POST['mode']==='send' && $msg !== ''){
        $receiver = $_POST['receiver'] ?? $admin_email;
        $stmt = $conn->prepare("INSERT INTO company_messages (company_id, sender_email, receiver_email, message) VALUES (?,?,?,?)");
        $stmt->bind_param("isss", $current_user_id, $user_email, $receiver, $msg);
        $stmt->execute();
        
        // Mail Notification Logic here (as per your original code)
        exit('success');
    }
    
    if($_POST['mode']==='delete'){
        $stmt = $conn->prepare("UPDATE company_messages SET message='Message removed' WHERE id=? AND sender_email=?");
        $stmt->bind_param("is", $mid, $user_email);
        $stmt->execute();
        exit('deleted');
    }
    if($_POST['mode'] === 'edit' && isset($_POST['message_id'], $_POST['new_message'])){
        $msg_id = intval($_POST['message_id']);
        $new_message = trim($_POST['new_message']);
        if($msg_id > 0 && $new_message !== ''){
            $stmt = $conn->prepare("UPDATE company_messages SET message=?, edited=1 WHERE id=? AND sender_email=? AND company_id=?");
            $stmt->bind_param("sisi", $new_message, $msg_id, $user_email, $current_user_id);
            $stmt->execute();
            $stmt->close();
        }
        exit('edited');
    }  
}

if(isset($_GET['load_messages'])){
    $company_id = isset($_GET['company_id']) ? intval($_GET['company_id']) : 0;
    if($company_id <= 0){ exit('Invalid company'); }

    $stmt = $conn->prepare("SELECT * FROM company_messages WHERE company_id=? ORDER BY created_at ASC");
    $stmt->bind_param("i", $company_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while($row = $res->fetch_assoc()){
        $isSender = ($row['sender_email'] === $admin_email);
        $bgColor = $isSender ? "#e7f3ff" : "#f1f0f0";
        $align = $isSender ? "margin-left: auto; border-bottom-right-radius: 2px;" : "margin-right: auto; border-bottom-left-radius: 2px;";
        
        echo "<div style='max-width:80%; margin-bottom:12px; padding:10px; border-radius:12px; background:$bgColor; font-size:13px; $align'>";
        echo "<b style='font-size:11px; color:#555;'>".htmlspecialchars($row['sender_email'])."</b> ";
        if(!empty($row['edited']) && $row['edited']==1) echo "<span style='font-size:10px;color:gray'>(edited)</span>";
        echo "<div style='margin-top:4px;'>".nl2br(htmlspecialchars($row['message']))."</div>";
        echo "<div style='font-size:10px;color:#999; margin-top:5px; display:flex; justify-content:space-between; align-items:center;'>";
        echo "<span>".$row['created_at']."</span>";
        if($isSender && $row['message'] !== 'Message removed'){
            echo "<div><span onclick='editMessage(".$row['id'].")' style='cursor:pointer; color:blue; margin-right:8px;'>Edit</span>";
            echo "<span onclick='deleteMessage(".$row['id'].")' style='cursor:pointer; color:red;'>Delete</span></div>";
        }
        echo "</div></div>";
    }
    exit;
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $pageTitle ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --neust-maroon: #07145fe5;
            --neust-gold: #FFD700;
            --bg: #fcfaf7;
            --white: #ffffff;
        }

        body { font-family: 'Segoe UI', Tahoma, sans-serif; background: var(--bg); margin: 0; display: flex; min-height: 100vh; }

        /* --- SIDEBAR --- */
        .sidebar { width: 260px; background: var(--neust-maroon); height: 100vh; position: fixed; display: flex; flex-direction: column; transition: 0.3s; z-index: 1000; }
        .sidebar.collapsed { width: 80px; }
        .sidebar-header { padding: 20px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-header h2 { color: var(--neust-gold); margin: 0; font-size: 20px; white-space: nowrap; overflow: hidden; }
        .sidebar.collapsed h2 { opacity: 0; width: 0; }
        .sidebar-links { flex: 1; padding: 10px 0; }
        .sidebar a { padding: 15px 25px; color: #cbd5e0; text-decoration: none; font-size: 14px; display: flex; align-items: center; }
        .sidebar a i { width: 30px; font-size: 18px; margin-right: 15px; }
        .sidebar.collapsed .link-text { display: none; }
        .sidebar a.active { background: #1a237e; color: white; border-left: 4px solid var(--neust-gold); }
        .logout-link { margin-top: auto; padding: 20px; border-top: 1px solid rgba(255,255,255,0.1); }
        .logout-link a { border: 1px solid var(--neust-gold); color: var(--neust-gold); border-radius: 6px; justify-content: center; padding: 10px; text-decoration: none; display: flex; }

        /* --- CONTENT --- */
        .main-content { margin-left: 260px; width: 100%; transition: 0.3s; }
        .sidebar.collapsed + .main-content { margin-left: 80px; }
        .navbar { background: var(--neust-maroon); padding: 10px 30px; display: flex; align-items: center; color: white; height: 60px; }

        .container { padding: 40px; max-width: 900px; margin: 0 auto; }
        .profile-card { background: white; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); overflow: hidden; }
        .profile-banner { background: var(--neust-maroon); height: 100px; position: relative; }
        .profile-info { padding: 30px; padding-top: 20px; }
        .profile-info h2 { margin: 0; color: var(--neust-maroon); border-bottom: 2px solid var(--neust-gold); padding-bottom: 10px; margin-bottom: 20px; }
        
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .info-item { margin-bottom: 15px; }
        .info-item label { display: block; font-size: 12px; color: #718096; font-weight: bold; text-transform: uppercase; }
        .info-item span { font-size: 15px; color: #2d3748; font-weight: 500; }

        .map-section { margin-top: 30px; border-top: 1px solid #edf2f7; padding-top: 20px; }
        iframe { width: 100%; height: 350px; border-radius: 8px; border: 1px solid #eee; }

        /* --- CHAT STYLES --- */
        #chatIcon { position:fixed; bottom:30px; right:30px; width:60px; height:60px; background:var(--neust-maroon); border-radius:50%; display:flex; justify-content:center; align-items:center; cursor:pointer; box-shadow:0 4px 15px rgba(0,0,0,0.3); z-index:1001; color:white; font-size:24px; }
        #chatModal { display:none; position:fixed; bottom:100px; right:30px; width:350px; height:450px; background:white; border-radius:12px; box-shadow:0 10px 40px rgba(0,0,0,0.2); flex-direction:column; overflow:hidden; z-index:1002; border: 1px solid #ddd; }
        /* ── SIDEBAR BADGE (ADDED) ── */
.sidebar a { position: relative; }

.sidebar-badge {
    background: #dc2626;
    color: white;
    border-radius: 50%;
    width: 18px;
    height: 18px;
    font-size: 10px;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    position: absolute;
    right: 18px;
    top: 50%;
    transform: translateY(-50%);
}
.sidebar-badge-ungraded {
    background: #d97706;
    color: white;
    border-radius: 50%;
    width: 18px;
    height: 18px;
    font-size: 10px;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    position: absolute;
    right: 18px;
    top: 50%;
    transform: translateY(-50%);
}
.sidebar-badge-late {
    background: #d97706;
    color: white;
    border-radius: 50%;
    width: 18px; height: 18px;
    font-size: 10px; font-weight: 700;
    display: inline-flex;
    align-items: center; justify-content: center;
    position: absolute;
    right: 18px; top: 50%;
    transform: translateY(-50%);
    animation: badge-pulse-late 2s ease-in-out infinite;
}
@keyframes badge-pulse-late {
    0%,100% { box-shadow: 0 0 0 0 rgba(217,119,6,0.55); }
    50%      { box-shadow: 0 0 0 6px rgba(217,119,6,0); }
}
    </style>
</head>
<body>

<div id="sidebar" class="sidebar">
    <div class="sidebar-header">
        <h2>Profile</h2>
        <button id="toggleBtn" style="background:none; border:none; color:white; cursor:pointer;"><i class="fas fa-bars"></i></button>
    </div>
    <div class="sidebar-links">
        <a href="Profile.php" class="active"><i class="fas fa-user-circle"></i><span class="link-text">My Profile</span></a>
        <a href="add_ojt_student.php">
            <i class="fas fa-user-graduate"></i>
            <span class="link-text">OJT Student List</span>
            <?php if ($inbox_count > 0): ?>
                <span class="sidebar-badge"><?= $inbox_count ?></span>
            <?php endif; ?>
        </a>

        <a href="CompanyForm.php"><i class="fas fa-file-contract"></i><span class="link-text">Requirements</span></a>
        <a href="attendance_management.php"><i class="fas fa-building"></i>
            <span class="link-text">Attendance Management</span>
            <?php if ($pending_lr_count > 0): ?>
                <span class="sidebar-badge-late"><?= $pending_lr_count ?></span>
            <?php endif; ?>
        </a>
        <a href="company_reports.php">
            <i class="fas fa-chart-bar"></i>
            <span class="link-text">Company Reports</span>
            <?php if ($ungraded_count > 0): ?>
                <span class="sidebar-badge-ungraded"><?= $ungraded_count ?></span>
            <?php endif; ?>
        </a>

    </div>
    <div class="logout-link">
        <a href="login.php"><i class="fas fa-sign-out-alt"></i><span class="link-text" style="margin-left:10px;">Logout</span></a>
    </div>
</div>

<div class="main-content">
    <nav class="navbar">
        <img src="logo.webp" style="height:40px;margin-right:15px;">
        <div>
            <div style="font-weight:bold; font-size:16px;">NEUST Atate Campus</div>
            <div style="font-size:11px; color:var(--neust-gold);">Web-Based Smart OJT Monitoring and Supervision Analytics System</div>
        </div>
    </nav>

    <div class="container">
        <div class="profile-card">
            <div class="profile-banner"></div>
            <div class="profile-info">
                <h2>Company Profile Information</h2>
                <div class="info-grid">
                    <div class="info-item"><label>Company Name</label><span><?= htmlspecialchars($company_name) ?></span></div>
                    <div class="info-item"><label>Contact Person</label><span><?= htmlspecialchars($full_name) ?></span></div>
                    <div class="info-item"><label>Email Address</label><span><?= htmlspecialchars($row['email'] ?? 'N/A') ?></span></div>
                    <div class="info-item"><label>Telephone</label><span> <?= htmlspecialchars($row['telephone'] ?? 'N/A') ?></span></div>
                    <div class="info-item"><label>Facebook</label><a href="<?= htmlspecialchars($row['facebook_link'] ?? '#') ?>" target="_blank" style="font-size:14px; color:#0038a8;">Visit Page</a></div>
                </div>

                <?php if($google_map_src): ?>
                <div class="map-section">
                    <h3 style="font-size:16px; color:var(--neust-maroon); margin-bottom:15px;"><i class="fas fa-map-marker-alt"></i> Office Location</h3>
                    <iframe src="<?= htmlspecialchars($google_map_src) ?>" allowfullscreen loading="lazy"></iframe>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div id="chatIcon"><i class="fas fa-comments"></i></div>
<div id="chatModal">
    <div style="background:var(--neust-maroon); color:white; padding:15px; font-weight:bold; display:flex; justify-content:space-between; align-items:center;">
        Admin Support <span style="cursor:pointer;" onclick="closeChat()">✖</span>
    </div>
    <div id="chatMessages" style="flex:1; overflow-y:auto; padding:15px; background:#f8fafc;"></div>
    <div style="display:flex; border-top:1px solid #eee; padding:10px;">
        <input type="text" id="chatInput" style="flex:1; padding:10px; border:1px solid #ddd; border-radius:20px; outline:none;" placeholder="Type a message...">
        <button onclick="sendChatMessage()" style="background:var(--neust-maroon); color:white; border:none; border-radius:50%; width:40px; height:40px; margin-left:8px; cursor:pointer;"><i class="fas fa-paper-plane"></i></button>
    </div>
</div>

<script>
    const sb = document.getElementById('sidebar');
    document.getElementById('toggleBtn').addEventListener('click', () => sb.classList.toggle('collapsed'));

    const chatModal = document.getElementById('chatModal');
    document.getElementById('chatIcon').addEventListener('click', () => {
        chatModal.style.display = 'flex';
        loadChatMessages();
    });

    function closeChat() { chatModal.style.display = 'none'; }

    function loadChatMessages() {
        fetch(window.location.href + "?load_messages=1")
        .then(res => res.text())
        .then(data => {
            const container = document.getElementById('chatMessages');
            container.innerHTML = data;
            container.scrollTop = container.scrollHeight;
        });
    }

    function sendChatMessage() {
        let msg = document.getElementById('chatInput').value;
        if(!msg.trim()) return;
        fetch(window.location.href, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: "mode=send&message=" + encodeURIComponent(msg)
        }).then(() => {
            document.getElementById('chatInput').value = '';
            loadChatMessages();
        });
    }

    function deleteMessage(id) {
        if(!confirm('Delete message?')) return;
        fetch(window.location.href, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: "mode=delete&message_id=" + id
        }).then(() => loadChatMessages());
    }
    function editMessage(id){
        let newMsg = prompt("Edit your message:");
        if(!newMsg) return;
        fetch('<?= $_SERVER['PHP_SELF'] ?>', {
            method: "POST",
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: "mode=edit&message_id=" + id + "&new_message=" + encodeURIComponent(newMsg)
        })
        .then(() => loadChatMessages());
    }


    setInterval(() => { if(chatModal.style.display === 'flex') loadChatMessages(); }, 4000);
</script>

</body>
</html>