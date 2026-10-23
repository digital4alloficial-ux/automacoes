<?php
declare(strict_types=1);
define('D4A_LOCAL_AUTH_PAGE',true);
require __DIR__ . '/_bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Allow: POST'); http_response_code(405); exit; }
if (!is_string($_POST['csrf'] ?? null) || !isset($_SESSION['logout_csrf']) || !hash_equals($_SESSION['logout_csrf'],$_POST['csrf'])) { throw new \Digital4All\OAuthFailure('csrf_invalid'); }
$_SESSION=[];
setcookie(session_name(),'', ['expires'=>time()-3600,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
session_destroy();
header('Location: /bling/login.php',true,303);
