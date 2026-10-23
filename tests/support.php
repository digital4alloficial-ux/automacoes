<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/private/oauth.php';
use Digital4All\{TokenTransport, OAuthFailure, OAuth, Crypto};
function testDb(): PDO {
    $dsn = getenv('TEST_DSN') ?: 'mysql:host=db;dbname=oauth_test;charset=utf8mb4';
    if (!preg_match('/;dbname=oauth_test(?:;|$)/', $dsn)) {
        throw new RuntimeException('Tests require the disposable oauth_test database');
    }
    return new PDO($dsn, 'oauth_test', 'local-test-only', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
}
function testConfig(): array {
    return ['dsn' => getenv('TEST_DSN') ?: 'mysql:host=db;dbname=oauth_test;charset=utf8mb4',
        'authorize_url' => 'https://provider.invalid/authorize', 'token_url' => 'https://provider.invalid/token',
        'client_id' => 'fixture-client', 'client_secret' => 'fixture-secret',
        'redirect_uri' => 'https://api.digital4all.com.br/bling/callback.php',
        'binding_key' => str_repeat('b', 32), 'state_ttl' => 600, 'refresh_margin' => 60];
}
function testTokens(string $suffix = ''): array {
    return ['access_token' => 'test-access' . $suffix, 'refresh_token' => 'test-refresh' . $suffix, 'token_type' => 'Bearer', 'expires_in' => 3600];
}
final class FakeTransport implements TokenTransport {
    public array $forms = [];
    public ?string $failure = null;
    public array $tokens;
    public function __construct(private ?PDO $db = null) { $this->tokens = testTokens(); }
    public function exchange(array $form): array {
        $this->forms[] = $form;
        if ($this->db) {
            $this->db->exec('INSERT INTO test_http_calls (id) VALUES (NULL)');
            usleep(400000);
        }
        if ($this->failure) { throw new OAuthFailure($this->failure); }
        return $this->tokens;
    }
}
function testService(PDO $db, ?FakeTransport $client = null): OAuth {
    return new OAuth($db, testConfig(), new Crypto(base64_encode(str_repeat('k', 32))), $client ?? new FakeTransport());
}
function stateOf(string $url): string { parse_str((string) parse_url($url, PHP_URL_QUERY), $q); return $q['state']; }
