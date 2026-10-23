<?php
declare(strict_types=1);
namespace Digital4All;

require_once __DIR__ . '/src/Security.php';
require_once __DIR__ . '/src/TokenClient.php';

/** Created ONLY by the trusted authentication adapter, never from request input. */
final class Principal
{
    public function __construct(public readonly string $id, public readonly int $companyId)
    {
        if ($id === '' || strlen($id) > 190 || $companyId < 1) {
            throw new OAuthFailure('denied');
        }
    }
}

final class OAuth
{
    public function __construct(private \PDO $db, private array $config, private Crypto $crypto, private TokenTransport $client) {}

    private function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
    private function binding(string $value): string
    {
        return hash_hmac('sha256', $value, $this->config['binding_key']);
    }
    private function locked(int $company, callable $action): mixed
    {
        // A connection-scoped MySQL lock survives COMMIT, so the rotation marker
        // can be persisted BEFORE HTTP. Process death leaves a detectable marker.
        $name = 'd4a:' . substr(hash('sha256', $this->config['dsn'] . ':' . $company), 0, 56);
        if ((int) $this->query('SELECT GET_LOCK(?, 30)', [$name])->fetchColumn() !== 1) {
            throw new OAuthFailure('rate_limited');
        }
        try {
            return $action();
        } finally {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->query('SELECT RELEASE_LOCK(?)', [$name]);
        }
    }
    private function connection(int $company): array
    {
        $row = $this->query('SELECT * FROM bling_connections WHERE company_id = ?', [$company])->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            throw new OAuthFailure('reauthorization_required');
        }
        return $row;
    }
    public function authorizationUrl(Principal $who, string $sessionId): string
    {
        if ($sessionId === '') {
            throw new OAuthFailure('denied');
        }
        $state = bin2hex(random_bytes(32));
        $this->locked($who->companyId, function () use ($who, $sessionId, $state): void {
            $this->db->beginTransaction();
            // Company must already exist; OAuth is never allowed to create tenants.
            $this->query('INSERT INTO bling_connections (company_id) VALUES (?) ON DUPLICATE KEY UPDATE company_id = VALUES(company_id)', [$who->companyId]);
            $row = $this->connection($who->companyId);
            // One pending authorization per company, also limits accidental link races.
            $this->query('DELETE FROM bling_oauth_states WHERE company_id = ? OR expires_at < ?', [$who->companyId, time()]);
            $generation = (int) $row['generation'] + 1;
            $this->query('UPDATE bling_connections SET generation = ? WHERE company_id = ?', [$generation, $who->companyId]);
            $this->query('INSERT INTO bling_oauth_states (state_hash, company_id, principal_hash, session_hash, connection_generation, expires_at) VALUES (?, ?, ?, ?, ?, ?)', [hash('sha256', $state), $who->companyId, $this->binding($who->id), $this->binding($sessionId), $generation, time() + $this->config['state_ttl']]);
            $this->db->commit();
        });
        return $this->config['authorize_url'] . '?' . http_build_query([
            'response_type' => 'code', 'client_id' => $this->config['client_id'],
            'redirect_uri' => $this->config['redirect_uri'], 'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }
    private function consumeState(Principal $who, string $sessionId, string $state): array
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $state) || $sessionId === '') {
            throw new OAuthFailure('state_invalid');
        }
        // Delete is atomic and identity-bound: a competing callback cannot reuse it.
        $params = [hash('sha256', $state), $who->companyId, $this->binding($who->id), $this->binding($sessionId)];
        $row = $this->query('SELECT * FROM bling_oauth_states WHERE state_hash = ? AND company_id = ? AND principal_hash = ? AND session_hash = ?', $params)->fetch(\PDO::FETCH_ASSOC);
        if (!$row || $this->query('DELETE FROM bling_oauth_states WHERE state_hash = ? AND company_id = ? AND principal_hash = ? AND session_hash = ?', $params)->rowCount() !== 1) {
            throw new OAuthFailure('state_invalid');
        }
        if ((int) $row['expires_at'] <= time()) {
            throw new OAuthFailure('state_expired');
        }
        return $row;
    }
    public function callback(Principal $who, string $sessionId, array $input): void
    {
        $state = $input['state'] ?? null;
        if (!is_string($state)) {
            throw new OAuthFailure('state_invalid');
        }
        $pending = $this->consumeState($who, $sessionId, $state);
        // Consume even denial/malformed responses; never print provider descriptions.
        if (array_key_exists('error', $input)) {
            throw new OAuthFailure('provider_denied');
        }
        if (!is_string($input['code'] ?? null) || $input['code'] === '' || strlen($input['code']) > 4096 || preg_match('/[\x00-\x20\x7f]/', $input['code'])) {
            throw new OAuthFailure('callback_invalid');
        }
        $this->locked($who->companyId, function () use ($who, $pending, $input): void {
            $row = $this->connection($who->companyId);
            if ((int) $row['generation'] !== (int) $pending['connection_generation']) {
                throw new OAuthFailure('state_stale');
            }
            $startedAt = time();
            $tokens = $this->client->exchange(['grant_type' => 'authorization_code', 'code' => $input['code']]);
            $this->save($who->companyId, $tokens, $startedAt);
        });
    }
    private function save(int $company, array $tokens, int $startedAt): void
    {
        $tokens = TokenClient::validate($tokens);
        $this->query("UPDATE bling_connections SET status = 'connected', access_cipher = ?, refresh_cipher = ?, expires_at = ?, granted_scope = ?, refresh_in_flight = 0 WHERE company_id = ?", [
            $this->crypto->encrypt($tokens['access_token'], $company, 'access'),
            $this->crypto->encrypt($tokens['refresh_token'], $company, 'refresh'),
            $startedAt + $tokens['expires_in'], $tokens['scope'] ?? null, $company,
        ]);
    }
    private function requireReauthorization(int $company): void
    {
        $this->query("UPDATE bling_connections SET status = 'reauthorization_required', access_cipher = NULL, refresh_cipher = NULL, expires_at = NULL, granted_scope = NULL, refresh_in_flight = 0 WHERE company_id = ?", [$company]);
    }
    /** INTERNAL ONLY: never expose this return value via HTTP or logs. */
    public function accessToken(Principal $who): string
    {
        return $this->locked($who->companyId, function () use ($who): string {
            $row = $this->connection($who->companyId);
            if ($row['status'] !== 'connected') {
                throw new OAuthFailure('reauthorization_required');
            }
            if ((int) $row['refresh_in_flight'] === 1) {
                // A previous worker died during rotation; the old refresh may be spent.
                $this->requireReauthorization($who->companyId);
                throw new OAuthFailure('reauthorization_required');
            }
            if ((int) $row['expires_at'] > time() + $this->config['refresh_margin']) {
                return $this->crypto->decrypt($row['access_cipher'], $who->companyId, 'access');
            }
            try {
                $refresh = $this->crypto->decrypt($row['refresh_cipher'], $who->companyId, 'refresh');
                $this->query('UPDATE bling_connections SET refresh_in_flight = 1 WHERE company_id = ?', [$who->companyId]);
                $startedAt = time();
                $tokens = $this->client->exchange(['grant_type' => 'refresh_token', 'refresh_token' => $refresh]);
                $this->save($who->companyId, $tokens, $startedAt);
                return $tokens['access_token'];
            } catch (\Throwable $e) {
                // Includes ambiguous network errors: never retry a potentially rotated token.
                $this->requireReauthorization($who->companyId);
                throw $e;
            }
        });
    }
}
