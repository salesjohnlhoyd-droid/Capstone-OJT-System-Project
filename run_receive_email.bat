@echo off
REM ============================
REM Run receive_email.php via XAMPP PHP
REM ============================

REM Clear screen
cls

REM Use XAMPP PHP and explicit php.ini
C:\xampp\php\php.exe -c C:\xampp\php\php.ini C:\xampp\htdocs\phpmailer\receive_email.php

REM Optional: pause for debugging (remove for scheduler)
REM pause
