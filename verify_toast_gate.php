<?php
/* ============================================================================
   ADJUSTMENT: VERIFY-TOAST GATE (held applications wait for the undo toast)
   ----------------------------------------------------------------------------
   When the administrator sets a requirement to "Verified" in administrator.php
   the status is written at once, and an Undo toast stays on screen (up to
   5 minutes) so the action can still be reverted. A student whose application
   is ON HOLD (placement replaced, see placement_hold.php) used to be applied
   the very moment every requirement read "Verified" — i.e. while that toast was
   still active, and even if the admin then pressed Undo.

   Each Verified action is now recorded here under its undo token:
     • cv_vt_mark()   — administrator.php, right after a Verified save
     • cv_vt_clear()  — the toast ended (ajax_confirm_send) or was undone (ajax_undo)
     • cv_vt_pending() — company_list.php: while the student still has a live
                         entry, the held application is NOT released yet.
   An entry also expires on its own after the 5-minute undo window (a closed
   tab never leaves a student on hold forever). Every function swallows its
   errors: if anything goes wrong the previous behaviour applies unchanged.
   ============================================================================ */

if (!defined('CV_VT_WINDOW_SECONDS')) define('CV_VT_WINDOW_SECONDS', 305); // undo window (300 s) + a short grace

function cv_vt_ensure($conn) {
    static $done = false;
    if ($done) return true;
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS verify_toast_pending (
            undo_token VARCHAR(64) NOT NULL PRIMARY KEY,
            student_id INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_vt_student (student_id)
        )");
        $done = true;
    } catch (\Throwable $e) { return false; }
    return $done;
}

function cv_vt_mark($conn, $student_id, $token) {
    try {
        $student_id = (int)$student_id; $token = (string)$token;
        if ($student_id <= 0 || $token === '' || !cv_vt_ensure($conn)) return;
        $conn->query("DELETE FROM verify_toast_pending WHERE created_at < (NOW() - INTERVAL " . (int)CV_VT_WINDOW_SECONDS . " SECOND)");
        $st = $conn->prepare("REPLACE INTO verify_toast_pending (undo_token, student_id, created_at) VALUES (?, ?, NOW())");
        $st->bind_param('si', $token, $student_id);
        $st->execute(); $st->close();
    } catch (\Throwable $e) { /* never affects the save */ }
}

function cv_vt_clear($conn, $token) {
    try {
        $token = (string)$token;
        if ($token === '' || !cv_vt_ensure($conn)) return;
        $st = $conn->prepare("DELETE FROM verify_toast_pending WHERE undo_token = ?");
        $st->bind_param('s', $token);
        $st->execute(); $st->close();
    } catch (\Throwable $e) {}
}

// true while a Verified action for this student is still inside its undo toast
function cv_vt_pending($conn, $student_id) {
    try {
        $student_id = (int)$student_id;
        if ($student_id <= 0 || !cv_vt_ensure($conn)) return false;
        $st = $conn->prepare("SELECT 1 FROM verify_toast_pending WHERE student_id = ? AND created_at >= (NOW() - INTERVAL " . (int)CV_VT_WINDOW_SECONDS . " SECOND) LIMIT 1");
        $st->bind_param('i', $student_id);
        $st->execute();
        $found = (bool)$st->get_result()->fetch_row();
        $st->close();
        return $found;
    } catch (\Throwable $e) { return false; }
}

// ph_release_if_ready() (placement_hold.php), but only once the admin's undo toast is over
function cv_vt_release_if_ready($conn, $student_id) {
    if (cv_vt_pending($conn, $student_id)) return;
    ph_release_if_ready($conn, (int)$student_id);
}
