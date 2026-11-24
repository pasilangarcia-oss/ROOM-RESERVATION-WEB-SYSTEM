<?php
if(session_status() !==PHP_SESSION_ACTIVE){
    ini_set('session.use_strict_mode','1');
    session_set_cookie_params(['httponly' => true, 'secure'=>!empty($_SERVER['HTPPS']) && $_SERVER['HTTPS']!=='off','samesite'=>'Lax']);
    session_start();
}

function require_login(): void{
    if (empty($_SESSION['user_id']))  { header('Location; ../login.php'); exit;}
}


function e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
?> hello po nel

