<?php
// super_admin_logout.php - Logout handler for Super Admin

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

unset($_SESSION['is_super_admin']);
unset($_SESSION['super_admin_id']);
unset($_SESSION['super_admin_username']);
unset($_SESSION['super_admin_email']);

header("Location: super_admin_login.php");
exit();
