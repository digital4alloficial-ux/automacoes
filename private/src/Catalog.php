<?php
declare(strict_types=1);
namespace Digital4All;

final class ReadFailure extends \RuntimeException
{
    public function __construct(public readonly string $reason) { parent::__construct($reason); }
}
interface ReadTransport
{
    public function get(string $path, array $query, string $token): array;
}
final class CurlReadTransport implements ReadTransport
{
    public const BASE_URL = 'https://api.bling.com.br/Api/v3';
    public static function url(string $path, array $query): string
    {
        if (!in_array($path, ['/produtos', '/estoques/saldos'], true)) { throw new ReadFailure('api_invalid_request'); }
        // Bracketed array parameters follow the official OpenAPI idsProdutos[].
        $encoded = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $encoded = preg_replace('/%5B[0-9]+%5D/i', '%5B%5D', $encoded);
        return self::BASE_URL . $path . '?' . $encoded;
    }
    public static function decode(int $status, string $body): array
    {
        $reason = match ($status) {
            401 => 'api_unauthorized', 403 => 'api_forbidden', 429 => 'api_rate_limited',
            200 => null, default => 'api_unavailable',
        };
        if ($reason !== null) { throw new ReadFailure($reason); }
        if (strlen($body) > 1048576) { throw new ReadFailure('api_response_invalid'); }
        try { $json = json_decode($body, true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new ReadFailure('api_response_invalid'); }
        if (!is_array($json) || !is_array($json['data'] ?? null) || !array_is_list($json['data'])) {
            throw new ReadFailure('api_response_invalid');
        }
        return $json['data'];
    }
    public function get(string $path, array $query, string $token): array
    {
        if ($token === '' || preg_match('/[\x00-\x20\x7f]/', $token)) { throw new ReadFailure('api_unauthorized'); }
        $ch = curl_init(self::url($path, $query)); $body = '';
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'enable-jwt: 1', 'Authorization: Bearer ' . $token],
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 1048576) { return 0; }
                $body .= $chunk; return strlen($chunk);
            },
        ]);
        try { $ok = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); }
        finally { curl_close($ch); }
        if ($ok === false) { throw new ReadFailure('api_transport_failed'); }
        return self::decode($status, $body);
    }
}
final class Catalog
{
    public function __construct(private OAuth $oauth, private ReadTransport $http) {}
    public static function page(mixed $page): int
    {
        if (!is_string($page) || !preg_match('/\A[1-9][0-9]{0,5}\z/D', $page)) { throw new ReadFailure('api_invalid_request'); }
        return (int)$page;
    }
    private static function balance(mixed $value): ?float
    {
        return (is_int($value) || is_float($value)) && is_finite((float)$value) ? (float)$value : null;
    }
    public function list(Principal $who, int $page): array
    {
        self::page((string)$page);
        $token = $this->oauth->accessToken($who);
        try {
            $products = $this->http->get('/produtos', ['pagina'=>$page,'limite'=>20,'criterio'=>2,'tipo'=>'P'], $token);
            if (count($products)>20) { throw new ReadFailure('api_response_invalid'); }
            $rows=[];
            foreach ($products as $p) {
                if (!is_array($p) || !is_int($p['id'] ?? null) || $p['id']<1 || !is_string($p['nome'] ?? null) || strlen($p['nome'])>1024 ||
                    (isset($p['codigo']) && (!is_string($p['codigo']) || strlen($p['codigo'])>1024)) || isset($rows[$p['id']])) {
                    throw new ReadFailure('api_response_invalid');
                }
                $rows[$p['id']]=['id'=>$p['id'],'name'=>$p['nome'],'sku'=>$p['codigo'] ?? '', 'physical'=>null,'virtual'=>null];
            }
            $stockError = null;
            if ($rows) {
                try {
                    $balances=$this->http->get('/estoques/saldos', ['idsProdutos'=>array_keys($rows)], $token);
                    if (count($balances)>20) { throw new ReadFailure('api_response_invalid'); }
                    $seen=[];
                    foreach ($balances as $s) {
                        $id=is_array($s) ? ($s['produto']['id'] ?? null) : null;
                        if (!is_int($id) || !isset($rows[$id]) || isset($seen[$id])) { throw new ReadFailure('api_response_invalid'); }
                        $seen[$id]=true;
                        $rows[$id]['physical']=self::balance($s['saldoFisicoTotal'] ?? null);
                        $rows[$id]['virtual']=self::balance($s['saldoVirtualTotal'] ?? null);
                    }
                } catch (ReadFailure $e) {
                    // Preserve a useful product page without showing partial balances as complete.
                    foreach ($rows as &$row) { $row['physical']=null; $row['virtual']=null; } unset($row);
                    $stockError=$e->reason;
                }
            }
            return ['rows'=>array_values($rows),'page'=>$page,'may_have_next'=>count($products)===20 && $page<999999,'stock_error'=>$stockError];
        } finally { unset($token); }
    }
}
