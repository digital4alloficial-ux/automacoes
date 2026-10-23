<?php
declare(strict_types=1);
define('D4A_LOCAL_AUTH_PAGE', true);
require __DIR__ . '/_bootstrap.php';
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET','POST'], true)) { header('Allow: GET, POST'); http_response_code(405); exit; }
$_SESSION['login_csrf'] ??= bin2hex(random_bytes(32));
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['login_csrf'],$_POST['csrf'])) { throw new \Digital4All\OAuthFailure('csrf_invalid'); }
    $user = is_string($_POST['email'] ?? null) && is_string($_POST['password'] ?? null)
        ? $localAuth->login($_POST['email'],$_POST['password'],$_SERVER['REMOTE_ADDR'] ?? '') : null;
    if ($user !== null) {
        if (!session_regenerate_id(true)) { throw new RuntimeException('Session unavailable'); }
        $_SESSION = ['user_id'=>$user['id'],'auth_version'=>$user['version'],'auth_started'=>time(),'auth_seen'=>time()];
        session_write_close(); header('Location: /bling/account.php',true,303); exit;
    }
    http_response_code(401);
    $message = '<p>Não foi possível entrar. Confira seus dados ou aguarde 15 minutos antes de tentar novamente.</p>';
}
$csrf=htmlspecialchars($_SESSION['login_csrf'],ENT_QUOTES,'UTF-8');
echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><title>Digital4All — Entrar</title><h1>Área do cliente Digital4All</h1>'.$message.'<form method="post"><input type="hidden" name="csrf" value="'.$csrf.'"><p><label>Email <input type="email" name="email" maxlength="254" autocomplete="username" required></label></p><p><label>Senha <input type="password" name="password" maxlength="72" autocomplete="current-password" required></label></p><button>Entrar</button></form><p>Para criar ou recuperar seu acesso, fale com a Digital4All.</p></html>';
