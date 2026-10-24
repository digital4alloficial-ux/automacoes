<?php
declare(strict_types=1);
// Copy OUTSIDE public_html to settings.php. chmod 600. Never commit the real file.
return [
    'dsn' => 'mysql:host=localhost;dbname=CPANELUSER_digital4all;charset=utf8mb4',
    'db_user' => 'CPANELUSER_bling',
    'db_password' => '',
    'client_id' => '',
    'client_secret' => '',
    // Two independently generated base64-encoded 32-byte random keys.
    'encryption_key' => '',
    'binding_key' => '',
    // Official endpoints checked on 2026-10-23: see docs/BLING.md.
    // Runtime still requires private credentials and a working auth adapter.
    'authorize_url' => 'https://www.bling.com.br/Api/v3/oauth/authorize',
    'token_url' => 'https://api.bling.com.br/Api/v3/oauth/token',
    'endpoints_verified' => true,
    'read_catalog_enabled' => false, // Enable only for controlled homologation; see docs/CATALOG.md.
    'auth_mode' => 'local', // Built-in login; use 'adapter' only for an existing identity system.
    'auth_adapter' => __DIR__ . '/auth.php',
    'log_file' => __DIR__ . '/logs/oauth.jsonl',
];
