@echo off
title Interview Notification Worker

:loop
echo Running script at %date% %time%

"C:\xampp\php\php.exe" "C:\xampp\htdocs\phpmailer\send_interview_notifications.php"


timeout /t 60 /nobreak >nul

goto loop
