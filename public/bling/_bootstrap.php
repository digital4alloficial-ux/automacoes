<?php
declare(strict_types=1);
// Hosting-specific path is not a secret. Never put credentials in this directory.
ini_set('display_errors', '0');
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
$private = dirname(__DIR__, 2) . '/private';
$pathFile = __DIR__ . '/private-path.php';
try {
    if (is_file($pathFile)) {
        $private = require $pathFile;
    }
    if (!is_string($private) || $private === '' || $private[0] !== '/') {
        throw new RuntimeException('Invalid private path');
    }
    $resolved = realpath($private);
    $public = realpath($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__));
    if ($resolved === false || $public === false || $public === '/' ||
        $resolved === $public || str_starts_with($resolved, rtrim($public, '/') . '/') ||
        preg_match('~(?:^|/)public_html(?:/|$)~', $resolved) ||
        !is_file($resolved . '/bootstrap.php')) {
        throw new RuntimeException('Private directory must be outside public roots');
    }
} catch (Throwable) {
    header('Cache-Control: no-store');
    http_response_code(503);
    echo 'Integração indisponível. Verifique a configuração privada da hospedagem.';
    exit;
}
require $resolved . '/bootstrap.php';
