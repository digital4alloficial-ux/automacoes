<?php
declare(strict_types=1);

// This entire directory MUST be outside the document root.
$configFile = __DIR__ . '/settings.php';
if (!is_file($configFile)) {
    throw new RuntimeException('Missing private settings');
}
$c = require $configFile;
if (!is_array($c)) {
    throw new RuntimeException('Invalid private settings');
}
foreach (['dsn', 'db_user', 'db_password', 'client_id', 'client_secret', 'encryption_key', 'binding_key', 'authorize_url', 'token_url', 'auth_adapter', 'log_file'] as $key) {
    if (!isset($c[$key]) || !is_string($c[$key]) || $c[$key] === '') {
        throw new RuntimeException('Incomplete private settings');
    }
}
if (($c['endpoints_verified'] ?? false) !== true) {
    throw new RuntimeException('Official OAuth documentation verification required');
}
foreach (['authorize_url', 'token_url'] as $key) {
    $u = parse_url($c[$key]);
    if (!is_array($u) || ($u['scheme'] ?? '') !== 'https' ||
        !in_array($u['host'] ?? '', ['www.bling.com.br', 'api.bling.com.br'], true) ||
        isset($u['user']) || isset($u['pass']) || isset($u['port']) || isset($u['query']) || isset($u['fragment']) || empty($u['path'])) {
        throw new RuntimeException('Invalid OAuth endpoint');
    }
}
$c['redirect_uri'] = 'https://api.digital4all.com.br/bling/callback.php';
$c['state_ttl'] = 600;
$c['refresh_margin'] = 60;
$c['allowed_host'] = 'api.digital4all.com.br';
if (!str_starts_with($c['dsn'], 'mysql:') || !str_contains($c['dsn'], 'charset=utf8mb4')) {
    throw new RuntimeException('A MySQL utf8mb4 DSN is required');
}
return $c;
