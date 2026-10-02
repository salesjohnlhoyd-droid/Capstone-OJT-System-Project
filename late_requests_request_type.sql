-- Optional manual migration. student_attendance.php / attendance_management.php add this column
-- automatically on first use if the DB user has ALTER permission.
ALTER TABLE `late_requests`
  ADD COLUMN `request_type` ENUM('late','overtime') NOT NULL DEFAULT 'late' AFTER `type`;
