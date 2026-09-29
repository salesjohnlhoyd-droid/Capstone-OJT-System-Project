<?php
session_start();
include "db.php";

$user_id = $_GET['user_id'];
$type = $_GET['type'];

$stmt = $conn->prepare("SELECT file_name FROM company_requirements WHERE user_id=? AND requirement_type=?");
$stmt->bind_param("is",$user_id,$type);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$stmt->close();

if(!$res) die("File not found.");

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->buffer($res['file_name']);
$ext = ($mime === 'image/png') ? 'png' : (($mime === 'image/jpeg') ? 'jpg' : 'pdf');

header('Content-Description: File Transfer');
header('Content-Type: '.$mime);
header('Content-Disposition: attachment; filename="'.$type.'.'.$ext.'"');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: '.strlen($res['file_name']));
echo $res['file_name'];
exit;
?>