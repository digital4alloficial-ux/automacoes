<?php
declare(strict_types=1);
define('D4A_LOCAL_AUTH_PAGE',true);
require __DIR__ . '/_bootstrap.php';
$user=$localAuth->userId($_SESSION);
if ($user === null) { header('Location: /bling/login.php',true,303); exit; }
if (!in_array($_SERVER['REQUEST_METHOD'],['GET','POST'],true)) { header('Allow: GET, POST'); http_response_code(405); exit; }
$_SESSION['account_csrf'] ??= bin2hex(random_bytes(32));
$companies=$localAuth->companies($user);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['account_csrf'],$_POST['csrf'])) { throw new \Digital4All\OAuthFailure('csrf_invalid'); }
    $selected=null;
    foreach ($companies as $company) { if (is_string($_POST['company'] ?? null) && (string)$company['id'] === $_POST['company']) { $selected=(int)$company['id']; } }
    if ($selected === null) { throw new \Digital4All\OAuthFailure('denied'); }
    // Changing company/session invalidates any pending browser-bound OAuth state.
    if (!session_regenerate_id(true)) { throw new RuntimeException('Session unavailable'); }
    $_SESSION['company_id']=$selected;
    unset($_SESSION['connect_csrf'],$_SESSION['bling_result'],$_SESSION['account_csrf']);
    session_write_close(); header('Location: /bling/connect.php',true,303); exit;
}
$csrf=htmlspecialchars($_SESSION['account_csrf'],ENT_QUOTES,'UTF-8');
echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><title>Digital4All — Empresas</title><h1>Suas empresas</h1>';
if (!$companies) { echo '<p>Você ainda não tem permissão para conectar empresas. Fale com a Digital4All.</p>'; }
else {
    echo '<form method="post"><input type="hidden" name="csrf" value="'.$csrf.'"><label>Empresa <select name="company">';
    foreach ($companies as $company) { echo '<option value="'.(int)$company['id'].'">'.htmlspecialchars($company['name'],ENT_QUOTES,'UTF-8').'</option>'; }
    echo '</select></label><button>Conectar ao Bling</button></form>';
}
$_SESSION['logout_csrf'] ??= bin2hex(random_bytes(32));
echo '<form method="post" action="/bling/logout.php"><input type="hidden" name="csrf" value="'.htmlspecialchars($_SESSION['logout_csrf'],ENT_QUOTES,'UTF-8').'"><button>Sair</button></form></html>';
