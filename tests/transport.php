<?php
declare(strict_types=1);
$dir = sys_get_temp_dir() . '/d4a-tls-' . bin2hex(random_bytes(8)); mkdir($dir, 0700);
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$csr = openssl_csr_new(['commonName' => 'localhost'], $key, ['digest_alg' => 'sha256']);
$cert = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
if (!$cert) { throw new RuntimeException('Cannot create TLS test certificate'); }
openssl_x509_export($cert, $pem); openssl_pkey_export($key, $privatePem);
file_put_contents($dir . '/ca.pem', $pem); file_put_contents($dir . '/server.pem', $pem . $privatePem);
$p = proc_open([PHP_BINARY, __DIR__ . '/tls-server.php', $dir . '/server.pem'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $dir . '/server.log', 'a']], $pipes);
if (!is_resource($p)) { throw new RuntimeException('Cannot start TLS fixture'); }
fclose($pipes[0]); stream_set_timeout($pipes[1], 5); $address = trim((string) fgets($pipes[1]));
if (!preg_match('/\A127\.0\.0\.1:([0-9]+)\z/', $address, $m)) { proc_terminate($p); throw new RuntimeException('TLS fixture not ready'); }
$port = $m[1];
function transportRequest(string $url, ?string $ca): string {
    $args = [PHP_BINARY]; if ($ca !== null) { array_push($args, '-d', 'curl.cainfo=' . $ca); }
    array_push($args, __DIR__ . '/transport-client.php', $url);
    $p = proc_open($args, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($p);
    if ($exit !== 0 || $err !== '') { throw new RuntimeException('Transport fixture failed'); }
    return $out;
}
try {
    check(transportRequest("https://localhost:$port/ok", $dir . '/ca.pem') === 'ok', 'cURL real envia Basic, enable-jwt e formulário escapado sobre TLS validado');
    check(transportRequest("https://localhost:$port/reject", $dir . '/ca.pem') === 'token_rejected', 'cURL trata rejeição sem expor corpo');
    check(transportRequest("https://localhost:$port/invalid", $dir . '/ca.pem') === 'token_response_invalid', 'cURL rejeita JSON inválido');
    check(transportRequest("https://localhost:$port/redirect", $dir . '/ca.pem') === 'token_rejected', 'cURL não segue redirect com credenciais');
    check(transportRequest("https://127.0.0.1:$port/ok", $dir . '/ca.pem') === 'token_transport_failed', 'TLS rejeita hostname diferente do certificado');
    check(transportRequest("https://localhost:$port/ok", null) === 'token_transport_failed', 'TLS rejeita certificado não confiável');
} finally {
    proc_terminate($p); fclose($pipes[1]); proc_close($p);
    foreach (glob($dir . '/*') as $f) { unlink($f); } rmdir($dir);
}
