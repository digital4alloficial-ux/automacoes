<?php
declare(strict_types=1);
namespace Digital4All;

final class OAuthFailure extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}

final class Crypto
{
    private string $key;
    public function __construct(string $encodedKey)
    {
        $key = base64_decode($encodedKey, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new \RuntimeException('Invalid encryption key');
        }
        $this->key = $key;
    }
    public function encrypt(string $value, int $company, string $kind): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $aad = "digital4all:bling:v1:$company:$kind";
        return 'v1.' . base64_encode($nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($value, $aad, $nonce, $this->key));
    }
    public function decrypt(string $value, int $company, string $kind): string
    {
        $raw = str_starts_with($value, 'v1.') ? base64_decode(substr($value, 3), true) : false;
        if ($raw === false || strlen($raw) < 40) {
            throw new OAuthFailure('stored_token_invalid');
        }
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($raw, 24), "digital4all:bling:v1:$company:$kind", substr($raw, 0, 24), $this->key);
        if ($plain === false) {
            throw new OAuthFailure('stored_token_invalid');
        }
        return $plain;
    }
}

final class TechnicalLog
{
    public function __construct(private string $file) {}
    public function write(string $event, string $requestId): void
    {
        // Strict allowlist: never pass exceptions, URLs, request bodies or identities.
        $allowed = ['authorized', 'denied', 'state_invalid', 'state_expired', 'state_stale', 'provider_denied', 'callback_invalid', 'token_rejected', 'token_transport_failed', 'token_response_invalid', 'reauthorization_required', 'stored_token_invalid', 'internal_error', 'csrf_invalid', 'rate_limited'];
        if (!in_array($event, $allowed, true)) {
            $event = 'internal_error';
        }
        if (!preg_match('/\A[a-f0-9]{24}\z/D', $requestId)) {
            $requestId = bin2hex(random_bytes(12));
        }
        $line = json_encode(['time' => gmdate('c'), 'event' => $event, 'request_id' => $requestId], JSON_THROW_ON_ERROR) . "\n";
        if (@file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX) === false) {
            error_log('Digital4All OAuth: technical log unavailable');
        }
    }
}
