<?php
declare(strict_types=1);
// Disposable deployment and HTTP router. Never deploy this fixture to cPanel.
$fixture = sys_get_temp_dir() . '/d4a-web-' . bin2hex(random_bytes(8));
mkdir($fixture, 0700); mkdir($fixture . '/private', 0700); mkdir($fixture . '/private/src', 0700); mkdir($fixture . '/private/logs', 0700); mkdir($fixture . '/public', 0700); mkdir($fixture . '/public/bling', 0700);
$project = dirname(__DIR__);
foreach (['config.php', 'bootstrap.php', 'oauth.php', 'src/Security.php', 'src/TokenClient.php', 'src/LocalAuth.php'] as $file) { copy($project . '/private/' . $file, $fixture . '/private/' . $file); }
foreach (['connect.php', 'callback.php', 'result.php', '_bootstrap.php', 'login.php', 'account.php', 'logout.php'] as $file) { copy($project . '/public/bling/' . $file, $fixture . '/public/bling/' . $file); }
$settings = testConfig();
$settings['authorize_url'] = 'https://www.bling.com.br/'; // Fixture website URL, NOT a claimed API endpoint.
$settings['token_url'] = 'https://www.bling.com.br/'; // No outbound requests in route tests.
$settings['db_user'] = 'oauth_test'; $settings['db_password'] = 'local-test-only';
$settings['encryption_key'] = base64_encode(str_repeat('k', 32)); $settings['binding_key'] = base64_encode(str_repeat('b', 32));
$settings['endpoints_verified'] = true; // Simulated fixture contract only.
$settings['auth_adapter'] = $fixture . '/private/auth.php'; $settings['log_file'] = $fixture . '/private/logs/oauth.jsonl';
file_put_contents($fixture . '/private/settings.php', '<?php return ' . var_export($settings, true) . ';');
file_put_contents($fixture . '/private/auth.php', '<?php return static fn(): ?array => $_SESSION["test_identity"] ?? null;');
file_put_contents($fixture . '/router.php', <<<'PHP'
<?php
// TEST ONLY. Simulates Apache TLS and a trusted server-side login fixture.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/fixture/login') {
    session_name('__Host-D4A'); session_start(); session_regenerate_id(true);
    $_SESSION['test_identity'] = ['principal_id' => 'user-a', 'company_id' => 1];
    echo 'fixture login'; return;
}
$_SERVER['DOCUMENT_ROOT'] = __DIR__ . '/public';
$_SERVER['HTTPS'] = $path === '/fixture/insecure' ? 'off' : 'on';
if ($path === '/fixture/insecure') { $path = '/bling/connect.php'; }
if (!in_array($path, ['/bling/connect.php', '/bling/callback.php', '/bling/result.php', '/bling/login.php', '/bling/account.php', '/bling/logout.php'], true)) { http_response_code(404); return; }
require __DIR__ . '/public' . $path;
PHP);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr); $address = stream_socket_get_name($socket, false); fclose($socket);
$proc = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', $address, $fixture . '/router.php'], [0 => ['pipe', 'r'], 1 => ['file', $fixture . '/server.log', 'a'], 2 => ['file', $fixture . '/server.log', 'a']], $pipes);
if (!is_resource($proc)) { throw new RuntimeException('Cannot start route tests'); }
fclose($pipes[0]);
function webRequest(string $address, string $path, ?string $cookie = null, ?array $post = null, string $host = 'api.digital4all.com.br'): array {
    $headers = ['Host: ' . $host]; if ($cookie !== null) { $headers[] = 'Cookie: ' . $cookie; }
    $ch = curl_init('http://' . $address . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 3, CURLOPT_PROXY => '']);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $raw = curl_exec($ch); $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $n = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    return [$status, substr((string) $raw, 0, $n), substr((string) $raw, $n)];
}
try {
    $ready = false;
    for ($i = 0; $i < 50; ++$i) { $s = @stream_socket_client('tcp://' . $address, $e, $err, 0.1); if ($s) { fclose($s); $ready = true; break; } usleep(20000); }
    if (!$ready) { throw new RuntimeException('Route server not ready'); }
    [$status] = webRequest($address, '/bling/connect.php'); check($status === 403, 'rota connect nega sessão anônima');
    [$status] = webRequest($address, '/bling/callback.php?code=SENSITIVE'); check($status === 403, 'rota callback nega sessão anônima');
    [, $headers] = webRequest($address, '/fixture/login');
    preg_match('/Set-Cookie: (__Host-D4A=[^;\r\n]+)/i', $headers, $m); $cookie = $m[1] ?? '';
    check($cookie !== '', 'fixture usa sessão autenticada no servidor');
    [$status] = webRequest($address, '/bling/connect.php?company_id=2', $cookie, null, 'evil.invalid'); check($status === 403, 'Host não autorizado bloqueado');
    [$status] = webRequest($address, '/fixture/insecure', $cookie); check($status === 403, 'HTTPS obrigatório na aplicação');
    [$status, $headers, $body] = webRequest($address, '/bling/connect.php?company_id=2', $cookie);
    check($status === 200 && str_contains($headers, 'no-store') && str_contains($headers, 'no-referrer'), 'formulário autenticado sem cache e sem referrer');
    check(str_contains($headers, "form-action 'self' https://www.bling.com.br;"), 'CSP do formulário permite redirect apenas ao origin Bling configurado');
    preg_match('/name="csrf" value="([a-f0-9]+)"/', $body, $m); $csrf = $m[1] ?? '';
    [$status] = webRequest($address, '/bling/connect.php', $cookie, ['csrf' => 'wrong']); check($status === 400, 'CSRF inválido bloqueado na rota');
    [$status, $headers] = webRequest($address, '/bling/connect.php?company_id=2', $cookie, ['csrf' => $csrf]);
    check($status === 303 && str_contains($headers, 'Location: https://www.bling.com.br/'), 'POST autenticado inicia autorização');
    check(str_contains($headers, "form-action 'self' https://www.bling.com.br;"), 'CSP da resposta POST também permite redirect para Bling');
    preg_match('/Location: ([^\r\n]+)/i', $headers, $m); $state = stateOf($m[1]);
    $stmt = $db->prepare('SELECT company_id FROM bling_oauth_states WHERE state_hash = ?'); $stmt->execute([hash('sha256', $state)]);
    check((int) $stmt->fetchColumn() === 1, 'company_id da URL ignorado em favor da identidade autorizada');
    [$status, $headers] = webRequest($address, '/bling/callback.php?' . http_build_query(['state' => $state, 'error' => 'access_denied', 'error_description' => 'SENSITIVE-MARKER']), $cookie);
    check($status === 303 && str_contains($headers, 'Location: /bling/result.php') && !str_contains($headers, 'SENSITIVE-MARKER'), 'callback remove parâmetros antes da página de resultado');
    [$status, , $body] = webRequest($address, '/bling/result.php', $cookie);
    check($status === 200 && str_contains($body, 'Não foi possível') && !str_contains($body, 'SENSITIVE-MARKER'), 'página mostra falha genérica sem mensagem do provedor');
    check(!str_contains(file_get_contents($settings['log_file']), 'SENSITIVE'), 'logger não registra dados do callback');
    [$status] = webRequest($address, '/bling/result.php', $cookie); check($status === 400, 'resultado de sessão consumido uma única vez');
    $settings['auth_mode'] = 'local';
    file_put_contents($fixture . '/private/settings.php', '<?php return ' . var_export($settings, true) . ';');
    [$status, $headers, $body] = webRequest($address, '/bling/login.php');
    check($status === 200 && str_contains($body, 'Área do cliente'), 'login próprio disponível sem autenticação anterior');
    preg_match('/Set-Cookie: (__Host-D4A=[^;\r\n]+)/i', $headers, $m); $loginCookie=$m[1] ?? '';
    check(str_contains(strtolower($headers),'secure') && str_contains(strtolower($headers),'httponly') && str_contains(strtolower($headers),'samesite=lax'),'cookie de login tem atributos seguros');
    preg_match('/name="csrf" value="([a-f0-9]+)"/', $body, $m); $loginCsrf=$m[1];
    [$status] = webRequest($address, '/bling/login.php', $loginCookie, ['email'=>'client@example.invalid','password'=>'fixture-password-long-enough','csrf'=>'wrong']);
    check($status === 400,'login próprio exige CSRF');
    [$status, $headers] = webRequest($address, '/bling/login.php', $loginCookie, ['email'=>'client@example.invalid','password'=>'fixture-password-long-enough','csrf'=>$loginCsrf]);
    preg_match('/Set-Cookie: (__Host-D4A=[^;\r\n]+)/i', $headers, $m); $newCookie=$m[1] ?? '';
    check($status === 303 && str_contains($headers,'Location: /bling/account.php') && $newCookie !== $loginCookie && $newCookie !== '', 'login real verifica senha e troca ID da sessão');
    $cookie=$newCookie;
    [$status] = webRequest($address, '/bling/account.php', $loginCookie); check($status === 303,'ID antigo não conserva acesso após login');
    [$status, , $body] = webRequest($address, '/bling/account.php', $cookie);
    check($status === 200 && str_contains($body,'Empresa A') && !str_contains($body,'Empresa B'),'painel lista somente empresas autorizadas');
    preg_match('/name="csrf" value="([a-f0-9]+)"/', $body, $m); $accountCsrf=$m[1];
    [$status] = webRequest($address,'/bling/account.php',$cookie,['csrf'=>$accountCsrf,'company'=>'2']); check($status === 403,'seleção adulterada de empresa recusada');
    [$status, $headers] = webRequest($address,'/bling/account.php',$cookie,['csrf'=>$accountCsrf,'company'=>'1']);
    preg_match('/Set-Cookie: (__Host-D4A=[^;\r\n]+)/i', $headers, $m); $cookie=$m[1] ?? $cookie;
    check($status === 303 && str_contains($headers,'Location: /bling/connect.php'),'empresa autorizada selecionada no servidor');
    rename($fixture . '/private', $fixture . '/hostinger-private');
    $settings['auth_adapter'] = $fixture . '/hostinger-private/auth.php';
    $settings['log_file'] = $fixture . '/hostinger-private/logs/oauth.jsonl';
    file_put_contents($fixture . '/hostinger-private/settings.php', '<?php return ' . var_export($settings, true) . ';');
    file_put_contents($fixture . '/public/bling/private-path.php', '<?php return ' . var_export($fixture . '/hostinger-private', true) . ';');
    [$status, , $body] = webRequest($address, '/bling/connect.php', $cookie);
    check($status === 200 && str_contains($body, 'Autorizar no Bling'), 'layout Hostinger carrega pasta privada externa configurada');
    [$status, , $body] = webRequest($address,'/bling/account.php',$cookie);
    preg_match('~action="/bling/logout.php"><input type="hidden" name="csrf" value="([a-f0-9]+)"~',$body,$m);
    [$status] = webRequest($address,'/bling/logout.php',$cookie,['csrf'=>$m[1]]); check($status === 303,'logout usa POST com CSRF');
    [$status] = webRequest($address,'/bling/connect.php',$cookie); check($status === 403,'logout invalida acesso à conexão Bling');
    file_put_contents($fixture . '/public/bootstrap.php', '<?php echo "PRIVATE-MARKER";');
    file_put_contents($fixture . '/public/bling/private-path.php', '<?php return ' . var_export($fixture . '/public', true) . ';');
    [$status, , $body] = webRequest($address, '/bling/connect.php', $cookie);
    check($status === 503 && !str_contains($body, 'PRIVATE-MARKER'), 'carregador recusa segredos dentro da raiz pública');
    symlink($fixture . '/public', $fixture . '/public-shortcut');
    file_put_contents($fixture . '/public/bling/private-path.php', '<?php return ' . var_export($fixture . '/public-shortcut', true) . ';');
    [$status] = webRequest($address, '/bling/connect.php', $cookie);
    check($status === 503, 'symlink para raiz pública não contorna isolamento');
    unlink($fixture . '/public-shortcut');
} finally {
    proc_terminate($proc); proc_close($proc);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); } rmdir($fixture);
}
