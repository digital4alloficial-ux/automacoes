<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
$result = $_SESSION['bling_result'] ?? null;
unset($_SESSION['bling_result']);
session_write_close();
if (!is_array($result) || time() - $result['at'] > 300) {
    http_response_code(400);
    $result = ['ok' => false, 'reference' => $requestId];
}
$title = $result['ok'] ? 'Bling conectado com sucesso' : 'Não foi possível conectar ao Bling';
$message = $result['ok'] ? 'A Digital4All recebeu a autorização. Você pode fechar esta página.' : 'Entre no sistema Digital4All e inicie uma nova autorização. Se necessário, informe a referência ao suporte.';
echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><title>Digital4All — Bling</title><h1>' . $title . '</h1><p>' . $message . '</p><p>Referência: ' . htmlspecialchars($result['reference'], ENT_QUOTES, 'UTF-8') . '</p></html>';
