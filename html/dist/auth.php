<?php
// /var/www/html/auth.php
// Simple session auth (change username/password here)

session_start();

define("RCM_USER", "admin");
define("RCM_PASS", "admin123"); // غيّرها بعدين براحتك

function rcm_require_login() {
    if (!isset($_SESSION["rcm_logged_in"]) || $_SESSION["rcm_logged_in"] !== true) {
        header("Location: /login.php");
        exit;
    }
}

function rcm_logout() {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $p = session_get_cookie_params();
        setcookie(session_name(), "", time() - 42000, $p["path"], $p["domain"], $p["secure"], $p["httponly"]);
    }
    session_destroy();
}
