<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once $resolved . '/src/Catalog.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { header('Allow: GET'); http_response_code(405); exit; }
if (($c['read_catalog_enabled'] ?? false) !== true) {
    http_response_code(403); echo 'Consulta não habilitada neste ambiente.'; exit;
}
function escapeCatalog(string $value): string { return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function catalogMessage(string $reason): string {
    return match ($reason) {
        'api_forbidden'=>'O Bling não permitiu esta consulta. Confira as permissões do aplicativo antes de alterar os escopos.',
        'api_unauthorized', 'reauthorization_required'=>'A conexão precisa ser verificada. Volte à área do cliente para conectar novamente.',
        'api_rate_limited'=>'O limite de consultas foi atingido. Aguarde antes de tentar novamente.',
        'api_invalid_request'=>'O número da página informado é inválido.',
        default=>'Não foi possível concluir a consulta agora. Tente novamente mais tarde.',
    };
}
$error=null; $result=null;
try {
    $page=\Digital4All\Catalog::page($_GET['page'] ?? '1');
    // Scope always comes from the authenticated principal, never request company/IDs.
    if (microtime(true)-(float)($_SESSION['catalog_last_at'] ?? 0)<2) { throw new \Digital4All\ReadFailure('api_rate_limited'); }
    $_SESSION['catalog_last_at']=microtime(true);
    $catalog=new \Digital4All\Catalog($oauth,new \Digital4All\CurlReadTransport());
    $result=$catalog->list($principal,$page);
    if ($result['stock_error'] !== null) { $log->write($result['stock_error'],$requestId); }
} catch (\Digital4All\ReadFailure|\Digital4All\OAuthFailure $e) {
    $error=catalogMessage($e->reason); $log->write($e->reason,$requestId);
    http_response_code($e->reason==='api_invalid_request' ? 400 : 503);
} catch (Throwable) { $error=catalogMessage('internal_error'); $log->write('internal_error',$requestId); http_response_code(503); }
session_write_close();
echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><title>Digital4All — Produtos e estoque</title><h1>Produtos e estoque</h1><p>Ambiente de homologação · Consulta somente leitura</p><p><a href="/bling/account.php">Área do cliente</a></p>';
if ($error !== null) { echo '<p>'.escapeCatalog($error).'</p><p>Referência: '.escapeCatalog($requestId).'</p>'; }
elseif ($result !== null) {
    if ($result['stock_error'] !== null) { echo '<p>Produtos carregados. Estoque indisponível: '.escapeCatalog(catalogMessage($result['stock_error'])).'</p><p>Referência: '.escapeCatalog($requestId).'</p>'; }
    if (!$result['rows']) { echo '<p>Nenhum produto ativo encontrado nesta página.</p>'; }
    else {
        echo '<table><caption>Produtos ativos — saldos totais de todos os depósitos</caption><thead><tr><th>SKU</th><th>Produto</th><th>Saldo físico</th><th>Saldo virtual</th></tr></thead><tbody>';
        foreach ($result['rows'] as $row) {
            echo '<tr><td>'.escapeCatalog($row['sku']).'</td><td>'.escapeCatalog($row['name']).'</td>';
            foreach (['physical','virtual'] as $field) { echo '<td>'.($row[$field]===null ? 'Não informado' : escapeCatalog(number_format($row[$field],4,',','.'))).'</td>'; }
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
    echo '<p>Página '.(int)$result['page'].'</p>';
    if ($result['page']>1) { echo '<a href="?page='.($result['page']-1).'">Anterior</a> '; }
    if ($result['may_have_next']) { echo '<a href="?page='.($result['page']+1).'">Próxima</a>'; }
    echo '<p>Saldos consultados após a listagem; não representam uma fotografia transacional. “Não informado” não significa estoque zero.</p>';
}
echo '</html>';
