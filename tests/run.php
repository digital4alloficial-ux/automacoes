<?php
declare(strict_types=1);
require __DIR__ . '/support.php';
use Digital4All\{Principal, OAuthFailure, Crypto, TechnicalLog, TokenClient};
$db = testDb();
foreach (['login_limits', 'company_users', 'app_users', 'bling_oauth_states', 'bling_connections', 'companies', 'test_http_calls'] as $table) { $db->exec("DROP TABLE IF EXISTS $table"); }
$db->exec(file_get_contents(dirname(__DIR__) . '/sql/schema.sql'));
$db->exec(file_get_contents(dirname(__DIR__) . '/sql/auth.sql'));
$db->exec('CREATE TABLE test_http_calls (id INT AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB');
$db->exec("INSERT INTO companies(id,name) VALUES (1,'Empresa A'),(2,'Empresa B')");
$a = new Principal('user-a', 1); $b = new Principal('user-b', 2);
$passed = 0;
function check(bool $ok, string $name): void { global $passed; if (!$ok) { throw new RuntimeException('FAIL: ' . $name); } ++$passed; echo "PASS $name\n"; }
function fails(callable $fn, string $reason, string $name): void {
    try { $fn(); } catch (OAuthFailure $e) { check($e->reason === $reason, $name); return; }
    throw new RuntimeException('FAIL: ' . $name);
}
function row(PDO $db, int $company = 1): array { return $db->query('SELECT * FROM bling_connections WHERE company_id = ' . $company)->fetch(PDO::FETCH_ASSOC); }
function parallelWorkers(string $mode, string $state = ''): array {
    $jobs = [];
    for ($i = 0; $i < 2; ++$i) {
        $p = proc_open([PHP_BINARY, __DIR__ . '/worker.php', $mode, $state], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($p)) { throw new RuntimeException('Cannot spawn concurrency test'); }
        fclose($pipes[0]); $jobs[] = [$p, $pipes];
    }
    $results = [];
    foreach ($jobs as [$p, $pipes]) { $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($p); if ($err !== '') { throw new RuntimeException('Worker failed'); } $results[] = [$exit, $out]; }
    return $results;
}
$client = new FakeTransport(); $oauth = testService($db, $client);
$url = $oauth->authorizationUrl($a, 'session-a'); $state = stateOf($url);
parse_str(parse_url($url, PHP_URL_QUERY), $query);
check(strlen($state) === 64 && ctype_xdigit($state), 'state criptograficamente aleatório com 256 bits');
check($query['response_type'] === 'code' && $query['redirect_uri'] === testConfig()['redirect_uri'], 'parâmetros da autorização e callback fixo');
check(!str_contains($url, 'fixture-secret'), 'client_secret ausente da autorização');
$stored = $db->query('SELECT * FROM bling_oauth_states')->fetch(PDO::FETCH_ASSOC);
check($stored['state_hash'] === hash('sha256', $state) && !in_array($state, $stored, true), 'somente hash do state persistido');
fails(fn() => $oauth->callback($b, 'session-a', ['state' => $state, 'code' => 'fixture-code']), 'state_invalid', 'empresa B não consome state da A');
fails(fn() => $oauth->callback(new Principal('other-user', 1), 'session-a', ['state' => $state, 'code' => 'fixture-code']), 'state_invalid', 'usuário diferente bloqueado');
fails(fn() => $oauth->callback($a, 'wrong-session', ['state' => $state, 'code' => 'fixture-code']), 'state_invalid', 'sessão diferente bloqueada');
$oauth->callback($a, 'session-a', ['state' => $state, 'code' => 'fixture-code']);
check(row($db)['status'] === 'connected', 'troca de código conecta empresa');
check($client->forms[0] === ['grant_type' => 'authorization_code', 'code' => 'fixture-code'], 'formulário da troca de código');
check(!str_contains(row($db)['access_cipher'], 'test-access') && !str_contains(row($db)['refresh_cipher'], 'test-refresh'), 'tokens cifrados no MySQL');
check($oauth->accessToken($a) === 'test-access' && count($client->forms) === 1, 'access token válido reutilizado sem refresh');
fails(fn() => $oauth->accessToken($b), 'reauthorization_required', 'empresa B não acessa conexão da A');
fails(fn() => $oauth->callback($a, 'session-a', ['state' => $state, 'code' => 'fixture-code']), 'state_invalid', 'replay bloqueado');
$s = stateOf($oauth->authorizationUrl($a, 'session-a'));
$db->exec('UPDATE bling_oauth_states SET expires_at = 1');
fails(fn() => $oauth->callback($a, 'session-a', ['state' => $s, 'code' => 'fixture-code']), 'state_expired', 'state expirado bloqueado e consumido');
fails(fn() => $oauth->callback($a, 'session-a', ['state' => $s, 'code' => 'fixture-code']), 'state_invalid', 'state expirado não reutilizável');
$s = stateOf($oauth->authorizationUrl($a, 'session-a'));
fails(fn() => $oauth->callback($a, 'session-a', ['state' => $s, 'error' => 'access_denied', 'error_description' => 'SENSITIVE']), 'provider_denied', 'recusa do provedor tratada');
fails(fn() => $oauth->callback($a, 'session-a', ['state' => $s, 'code' => 'fixture-code']), 'state_invalid', 'recusa consome state');
$s = stateOf($oauth->authorizationUrl($a, 'session-a'));
fails(fn() => $oauth->callback($a, 'session-a', ['state' => $s, 'code' => ['array']]), 'callback_invalid', 'code malformado bloqueado');
fails(fn() => $oauth->callback($a, 'session-a', ['state' => ['array'], 'code' => 'fixture-code']), 'state_invalid', 'state malformado bloqueado');
$old = stateOf($oauth->authorizationUrl($a, 'session-a'));
$new = stateOf($oauth->authorizationUrl($a, 'session-a'));
check($old !== $new, 'state diferente em cada tentativa');
fails(fn() => $oauth->callback($a, 'session-a', ['state' => $old, 'code' => 'fixture-code']), 'state_invalid', 'nova tentativa invalida anterior');
$crypto = new Crypto(base64_encode(str_repeat('k', 32)));
$cipher = $crypto->encrypt('fixture-token', 1, 'access');
check($crypto->decrypt($cipher, 1, 'access') === 'fixture-token', 'cifragem autenticada reversível');
fails(fn() => $crypto->decrypt($cipher, 2, 'access'), 'stored_token_invalid', 'cifra vinculada à empresa');
fails(fn() => $crypto->decrypt($cipher, 1, 'refresh'), 'stored_token_invalid', 'cifra vinculada ao tipo do token');
fails(fn() => $crypto->decrypt(substr($cipher, 0, -5) . 'AAAAA', 1, 'access'), 'stored_token_invalid', 'adulteração da cifra detectada');
foreach ([['token_type' => 'mac'], ['expires_in' => 0], ['expires_in' => '3600'], ['refresh_token' => ''], ['access_token' => "bad\r\nheader"], ['scope' => []]] as $bad) {
    fails(fn() => TokenClient::validate(array_replace(testTokens(), $bad)), 'token_response_invalid', 'resposta inválida: ' . array_key_first($bad));
}
$db->exec('UPDATE bling_connections SET expires_at = 1 WHERE company_id = 1');
$client->tokens = testTokens('-rotated');
check($oauth->accessToken($a) === 'test-access-rotated', 'token expirado renovado');
check(end($client->forms) === ['grant_type' => 'refresh_token', 'refresh_token' => 'test-refresh'], 'renovação usa refresh anterior');
check($crypto->decrypt(row($db)['refresh_cipher'], 1, 'refresh') === 'test-refresh-rotated', 'refresh rotacionado persistido');
$db->exec('UPDATE bling_connections SET expires_at = 1 WHERE company_id = 1');
$before = count($client->forms); $client->failure = 'token_transport_failed';
fails(fn() => $oauth->accessToken($a), 'token_transport_failed', 'falha de rede tratada sem retry');
check(row($db)['status'] === 'reauthorization_required' && row($db)['refresh_cipher'] === null, 'falha ambígua invalida credenciais locais');
fails(fn() => $oauth->accessToken($a), 'reauthorization_required', 'refresh potencialmente consumido não reutilizado');
check(count($client->forms) === $before + 1, 'uma única chamada após falha');
$client->failure = null;
$s = stateOf($oauth->authorizationUrl($a, 'session-a')); $oauth->callback($a, 'session-a', ['state' => $s, 'code' => 'fixture-code']);
$db->exec('UPDATE bling_connections SET refresh_in_flight = 1 WHERE company_id = 1');
fails(fn() => $oauth->accessToken($a), 'reauthorization_required', 'queda durante renovação exige reconexão');
$s = stateOf($oauth->authorizationUrl($a, 'session-a')); $oauth->callback($a, 'session-a', ['state' => $s, 'code' => 'fixture-code']);
$db->exec('UPDATE bling_connections SET expires_at = 1 WHERE company_id = 1');
$db->exec('TRUNCATE test_http_calls');
$results = parallelWorkers('refresh');
check($results[0][0] === 0 && $results[1][0] === 0, 'dois processos concorrentes obtêm token');
check((int) $db->query('SELECT COUNT(*) FROM test_http_calls')->fetchColumn() === 1, 'refresh concorrente faz uma única troca HTTP');
$db->exec('TRUNCATE test_http_calls');
$s = stateOf($oauth->authorizationUrl($a, 'session-a'));
$results = parallelWorkers('callback', $s);
$successes = count(array_filter($results, fn($r) => $r[0] === 0));
check($successes === 1 && (int) $db->query('SELECT COUNT(*) FROM test_http_calls')->fetchColumn() === 1, 'callbacks concorrentes consomem state uma vez');
$s = stateOf($oauth->authorizationUrl($b, 'session-b')); $oauth->callback($b, 'session-b', ['state' => $s, 'code' => 'fixture-code']);
check(row($db, 2)['status'] === 'connected' && row($db, 1)['status'] === 'connected', 'duas empresas mantêm conexões independentes');
$file = tempnam(sys_get_temp_dir(), 'd4a-log-'); $logger = new TechnicalLog($file);
$logger->write('test-access SECRET fixture-code', str_repeat('a', 24)); $logger->write('authorized', str_repeat('a', 24));
$logs = file_get_contents($file); unlink($file);
check(!str_contains($logs, 'SECRET') && !str_contains($logs, 'test-access') && !str_contains($logs, 'fixture-code'), 'log restringe eventos e elimina dados arbitrários');
check(str_contains($logs, 'internal_error') && str_contains($logs, 'authorized'), 'log técnico estruturado');
require __DIR__ . '/auth.php';
require __DIR__ . '/provision.php';
require __DIR__ . '/catalog.php';
require __DIR__ . '/web.php';
require __DIR__ . '/transport.php';
echo "\n$passed checks passed; 0 failed. Provider simulated; MariaDB real.\n";
