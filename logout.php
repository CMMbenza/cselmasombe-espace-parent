<?php
// /parent/logout.php
declare(strict_types=1);
if (session_status()===PHP_SESSION_NONE) session_start();

// Anti-cache
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$_SESSION = [];
if (ini_get('session.use_cookies')) {
  $params = session_get_cookie_params();
  setcookie(session_name(), '', time()-42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();

header('Location: /parent/login.php'); exit;
