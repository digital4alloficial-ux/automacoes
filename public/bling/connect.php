<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

// Chrome enforces form-action on the external redirect after the POST too.
// Config validation restricts this origin to an approved HTTPS Bling host.
$authorizeOrigin = 'https://' . parse_url($c['authorize_url'], PHP_URL_HOST);
header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self' $authorizeOrigin; frame-ancestors 'none'; base-uri 'none'");

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $_SESSION['connect_csrf'] ??= bin2hex(random_bytes(32));
    $csrf = htmlspecialchars($_SESSION['connect_csrf'], ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><title>Conectar Bling — Digital4All</title><h1>Conectar sua empresa ao Bling</h1><p>Você será direcionado ao Bling para revisar e autorizar o acesso.</p><form method="post"><input type="hidden" name="csrf" value="' . $csrf . '"><button type="submit">Autorizar no Bling</button></form></html>';
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}
if (!is_string($_POST['csrf'] ?? null) || !isset($_SESSION['connect_csrf']) || !hash_equals($_SESSION['connect_csrf'], $_POST['csrf'])) {
    throw new \Digital4All\OAuthFailure('csrf_invalid');
}
unset($_SESSION['connect_csrf']);
if (time() - (int) ($_SESSION['connect_last_at'] ?? 0) < 10) {
    throw new \Digital4All\OAuthFailure('rate_limited');
}
$_SESSION['connect_last_at'] = time();
$url = $oauth->authorizationUrl($principal, session_id());
session_write_close();
header('Location: ' . $url, true, 303);
