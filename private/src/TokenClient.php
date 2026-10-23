<?php
declare(strict_types=1);
namespace Digital4All;

interface TokenTransport
{
    /** Only return a validated token set. Never log a request or response body. */
    public function exchange(array $form): array;
}

final class TokenClient implements TokenTransport
{
    public function __construct(private array $config) {}
    public function exchange(array $form): array
    {
        $ch = curl_init($this->config['token_url']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($form, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_HTTPHEADER => ['Accept: 1.0', 'enable-jwt: 1', 'Content-Type: application/x-www-form-urlencoded', 'Authorization: Basic ' . base64_encode($this->config['client_id'] . ':' . $this->config['client_secret'])],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $response = '';
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($handle, string $chunk) use (&$response): int {
            if (strlen($response) + strlen($chunk) > 65536) {
                return 0;
            }
            $response .= $chunk;
            return strlen($chunk);
        });
        try {
            $ok = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        } finally {
            curl_close($ch);
        }
        if ($ok === false) {
            throw new OAuthFailure('token_transport_failed');
        }
        if ($status !== 200) {
            // No automatic retries: the provider may have consumed/rotated the token.
            throw new OAuthFailure('token_rejected');
        }
        try {
            $data = json_decode($response, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new OAuthFailure('token_response_invalid');
        }
        return self::validate($data);
    }
    public static function validate(mixed $data): array
    {
        if (!is_array($data) || !is_string($data['access_token'] ?? null) || !is_string($data['refresh_token'] ?? null) ||
            $data['access_token'] === '' || $data['refresh_token'] === '' || strlen($data['access_token']) > 16384 || strlen($data['refresh_token']) > 16384 ||
            preg_match('/[\x00-\x20\x7f]/', $data['access_token']) || preg_match('/[\x00-\x20\x7f]/', $data['refresh_token']) ||
            !is_string($data['token_type'] ?? null) || strcasecmp($data['token_type'], 'Bearer') !== 0 ||
            !is_int($data['expires_in'] ?? null) || $data['expires_in'] < 1 || $data['expires_in'] > 31536000 ||
            (isset($data['scope']) && (!is_string($data['scope']) || strlen($data['scope']) > 8192))) {
            throw new OAuthFailure('token_response_invalid');
        }
        return $data;
    }
}
