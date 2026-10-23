<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}
try {
    $oauth->callback($principal, session_id(), $_GET);
    $log->write('authorized', $requestId);
    $_SESSION['bling_result'] = ['ok' => true, 'reference' => $requestId, 'at' => time()];
} catch (Throwable $e) {
    $log->write($e instanceof \Digital4All\OAuthFailure ? $e->reason : 'internal_error', $requestId);
    $_SESSION['bling_result'] = ['ok' => false, 'reference' => $requestId, 'at' => time()];
}
session_write_close();
// Remove code and state from the address before rendering any page assets.
header('Location: /bling/result.php', true, 303);
