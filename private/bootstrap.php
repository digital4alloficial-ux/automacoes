<?php
declare(strict_types=1);
use Digital4All\{Crypto, OAuth, OAuthFailure, Principal, TechnicalLog, TokenClient};

require_once __DIR__ . '/oauth.php';
ini_set('display_errors', '0');
ini_set('log_errors', '1');
header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
header('Strict-Transport-Security: max-age=31536000');
$requestId = bin2hex(random_bytes(12));
$log = null;

set_exception_handler(static function (Throwable $e) use (&$log, $requestId): void {
    $reason = $e instanceof OAuthFailure ? $e->reason : 'internal_error';
    if ($log instanceof TechnicalLog) {
        $log->write($reason, $requestId);
    } else {
        error_log('Digital4All OAuth: initialization failed; reference=' . $requestId);
    }
    http_response_code($reason === 'denied' ? 403 : 400);
    echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><title>Conexão Bling</title><h1>Não foi possível conectar</h1><p>Entre no sistema Digital4All e inicie uma nova autorização. Se o problema persistir, informe a referência ao suporte.</p><p>Referência: ' . htmlspecialchars($requestId, ENT_QUOTES, 'UTF-8') . '</p></html>';
});

$c = require __DIR__ . '/config.php';
$log = new TechnicalLog($c['log_file']);
// Do not trust forwarded headers on shared hosting; TLS must terminate at Apache.
if (($_SERVER['HTTPS'] ?? '') !== 'on' || ($_SERVER['HTTP_HOST'] ?? '') !== $c['allowed_host']) {
    throw new OAuthFailure('denied');
}
if (session_status() !== PHP_SESSION_NONE) {
    throw new RuntimeException('Unexpected prestarted session');
}
session_name('__Host-D4A');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
if (!session_start()) {
    throw new RuntimeException('Session unavailable');
}
$bindingKey = base64_decode($c['binding_key'], true);
if ($bindingKey === false || strlen($bindingKey) !== 32) {
    throw new RuntimeException('Invalid binding key');
}
$c['binding_key'] = $bindingKey;
$db = new PDO($c['dsn'], $c['db_user'], $c['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_PERSISTENT => false]);
$db->exec("SET time_zone = '+00:00'");
require_once __DIR__ . '/src/LocalAuth.php';
$localAuth = new \Digital4All\LocalAuth($db, $c['binding_key']);
$localMode = ($c['auth_mode'] ?? 'adapter') === 'local';
if ($localMode) {
    $identity = $localAuth->identity($_SESSION);
    if ($localAuth->userId($_SESSION) !== null) { $_SESSION['auth_seen'] = time(); }
} else {
    $adapter = require $c['auth_adapter'];
    if (!is_callable($adapter)) { throw new RuntimeException('Authentication adapter unavailable'); }
    $identity = $adapter();
}
if (!(defined('D4A_LOCAL_AUTH_PAGE') && D4A_LOCAL_AUTH_PAGE === true && $localMode)) {
    if (!is_array($identity) || !is_string($identity['principal_id'] ?? null) || !is_int($identity['company_id'] ?? null)) {
        throw new OAuthFailure('denied');
    }
    $principal = new Principal($identity['principal_id'], $identity['company_id']);
}
$oauth = new OAuth($db, $c, new Crypto($c['encryption_key']), new TokenClient($c));
