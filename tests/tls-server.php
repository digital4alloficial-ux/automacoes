<?php
declare(strict_types=1);
// Local TLS provider fixture, not an API URL and never deployed.
$context = stream_context_create(['ssl' => ['local_cert' => $argv[1], 'crypto_method' => STREAM_CRYPTO_METHOD_TLS_SERVER]]);
$server = stream_socket_server('tls://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if (!$server) { exit(1); }
echo stream_socket_get_name($server, false) . "\n"; flush();
while (true) {
    $peer = @stream_socket_accept($server, 5);
    if (!$peer) { continue; }
    stream_set_timeout($peer, 3);
    $request = fgets($peer); $headers = [];
    while (($line = fgets($peer)) !== false && trim($line) !== '') {
        [$name, $value] = explode(':', $line, 2); $headers[strtolower(trim($name))] = trim($value);
    }
    $body = ''; $length = (int) ($headers['content-length'] ?? 0);
    while (strlen($body) < $length && !feof($peer)) { $chunk = fread($peer, $length - strlen($body)); if ($chunk === false || $chunk === '') { break; } $body .= $chunk; }
    parse_str($body, $form);
    $valid = str_starts_with((string) $request, 'POST ') &&
        ($headers['authorization'] ?? '') === 'Basic ' . base64_encode('fixture-client:fixture-secret') &&
        ($headers['content-type'] ?? '') === 'application/x-www-form-urlencoded' &&
        ($headers['enable-jwt'] ?? '') === '1' && ($headers['accept'] ?? '') === '1.0' &&
        $form === ['grant_type' => 'authorization_code', 'code' => 'fixture+code&value'];
    $path = explode(' ', (string) $request)[1] ?? '/';
    $status = $valid ? 200 : 400; $extra = '';
    $response = json_encode(['access_token' => 'fixture-access', 'refresh_token' => 'fixture-refresh', 'token_type' => 'Bearer', 'expires_in' => 3600]);
    if ($path === '/reject') { $status = 400; $response = '{"error":"invalid_grant","error_description":"SENSITIVE"}'; }
    if ($path === '/invalid') { $response = 'not json SENSITIVE'; }
    if ($path === '/redirect') { $status = 302; $extra = "Location: https://example.invalid/must-not-follow\r\n"; }
    fwrite($peer, "HTTP/1.1 $status Fixture\r\nContent-Type: application/json\r\nConnection: close\r\nContent-Length: " . strlen($response) . "\r\n" . $extra . "\r\n" . $response);
    fclose($peer);
}
