# Produtos e estoque — leitura em homologação

Implementado em 24/10/2026 a pedido do usuário. A pendência [SEC-001](SECURITY.md) continua aberta. Esta entrega permite desenvolvimento/homologação controlada; não representa liberação para novos clientes de produção.

## Contrato oficial consultado

Referência: https://developer.bling.com.br/referencia . O JavaScript oficial dessa página aponta para https://developer.bling.com.br/build/assets/openapi-DA9aa7wN.json, consultado em 24/10/2026.

| Consulta | Endpoint confirmado |
|---|---|
| Produtos | GET https://api.bling.com.br/Api/v3/produtos |
| Saldos totais | GET https://api.bling.com.br/Api/v3/estoques/saldos |

Produtos: parâmetros `pagina`, `limite=20`, `criterio=2` (ativos), `tipo=P` (produtos). Saldos: `idsProdutos[]` com os IDs retornados naquela página, enviados em lote. Campos utilizados: produtos `id`, `nome`, `codigo`; saldos `produto.id`, `saldoFisicoTotal`, `saldoVirtualTotal`. Autenticação Bearer e header `enable-jwt: 1`. Nenhuma URL ou método de escrita está disponível nesse cliente.

Saldos representam o total de todos os depósitos. Não há listagem individual de depósitos, movimentação, exportação, sincronização local ou escrita nesta etapa. A lista usa produtos ativos; página vazia significa nenhum resultado naquela página, não prova que a conta não tenha outros tipos/situações de produto. Quando uma página contém 20 registros, “Próxima” é uma possibilidade, não uma contagem total confirmada.

## Comportamento e segurança

- A rota usa o principal autenticado e as permissões existentes por empresa; não aceita uma empresa ou IDs de produtos do navegador para decidir o token/saldo consultado.
- Reutiliza OAuth::accessToken, com renovação controlada. Token fica no backend e não é exibido, colocado na URL ou registrado no log.
- Transporte restrito aos dois caminhos GET e ao host oficial, com TLS verificado, sem redirects, no máximo 1 MiB de resposta e sem retries automáticos. Não há proxy HTTP arbitrário.
- Paginação validada e limitada. Duas consultas sequenciais por página não vazia, uma para página vazia; sem fan-out por produto ou leitura automática de todas as páginas. Intervalo mínimo de 2 segundos por sessão, sem promessa de limite global entre usuários; respeitar erros 429 do provedor.
- Nomes/SKUs são escapados ao renderizar HTML. Não são carregadas imagens ou outros recursos externos. Cache desabilitado, seguindo os cabeçalhos existentes.
- Saldo ausente aparece como “Não informado”, sem virar zero. Estoque fracionário ou negativo é preservado, com exibição de quatro casas decimais.
- Erro de estoque preserva a página de produtos e informa indisponibilidade; não mistura saldos parciais com resultado completo. As duas leituras ocorrem em instantes diferentes, sem garantia de fotografia transacional.
- 401 exige verificar a conexão; 403 indica verificar permissões; 429 pede aguardar. Nenhum corpo de erro do Bling é mostrado ou registrado. Não há ciclo automático de renovação após 401 nem alteração automática de escopos.

## Atualização na Hostinger (sem migração SQL)

Baixar a versão atual do repositório. Preservar settings.php, private-path.php, chaves, logs e tokens do banco. Guardar cópia privada dos arquivos substituídos; não criar `.bak` público com credenciais.

Enviar estes arquivos à pasta privada existente `/home/u445223663/domains/digital4all.com.br/bling-private`:

- `private/src/Catalog.php` → `src/Catalog.php` (novo).
- `private/src/Security.php` → `src/Security.php` (atualizado: inclui eventos de leitura na allowlist do logger).

Enviar estes arquivos à pasta pública existente `/home/u445223663/domains/digital4all.com.br/public_html/automacoes/bling`:

- `public/bling/products.php` → `products.php` (novo).
- `public/bling/account.php` → `account.php` (atualizado: botão de consulta).

No settings.php privado, adicionar uma vez, dentro do array retornado:

```php
'read_catalog_enabled' => true,
```

O exemplo fica `false` por padrão. Usar booleano PHP `true` sem aspas somente no ambiente de homologação escolhido. A ausência da configuração também desabilita a consulta. Não substituir settings.php pelo exemplo: isso apagaria os valores locais. Não alterar ou importar novamente o banco para esta atualização.

Depois: sair e entrar novamente pelo login, selecionar a Digital4All e clicar em **Consultar produtos e estoque**. A página também fica em `/bling/products.php`, desde que a empresa já esteja selecionada. Para desligar, definir `read_catalog_enabled=false`; isso não apaga a conexão ou o token.

Se houver 403 do Bling, verificar as permissões de leitura de Produtos e Estoques do aplicativo/usuário. A documentação avisa que alterar escopos revoga autorizações existentes: avaliar antes de mudar e planejar nova autorização. Não fornecer permissões de escrita por conveniência.

## Validação

139 checks PHP/MariaDB aprovados localmente após esta implementação. Cobrem contrato/paginação, saldos fracionários/ausentes, tokens distintos por empresa, IDs inesperados, erros HTTP/JSON, ausência de retries, rotas protegidas, modo desabilitado, XSS, cache e renderização do painel com transporte simulado. As verificações de regras e tela usam provedor simulado; não foi feita leitura real de produtos/estoque da conta do usuário. O cURL de leitura ainda deve ser homologado com o Bling na hospedagem; a suíte TLS existente exercita o transporte OAuth separadamente. O teste de navegador existente da correção de CSP não foi alterado.

Na homologação real: validar uma página com produtos conhecidos e seus saldos, ausência de dados de outra empresa e comportamento de permissões/limite sem publicar os dados em GitHub ou chat. Manter SEC-001 registrada e não confundir sucesso funcional com resolução da exposição em logs.
