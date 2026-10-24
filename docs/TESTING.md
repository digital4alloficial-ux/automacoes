# Roteiro de testes

## Automatizados: sem credenciais reais

Executar na raiz do projeto:

```sh
docker compose -p d4a-oauth-test -f tests/compose.yaml up --build --abort-on-container-exit --exit-code-from test
# O comando precisa terminar com exit 0 e todos os checks aprovados.
docker compose -p d4a-oauth-test -f tests/compose.yaml down --volumes
```

Quando a máquina não permitir escrita em `~/.docker`, usar um diretório privado gravável via `DOCKER_CONFIG`, por exemplo `mkdir -p /tmp/d4a-docker` e prefixar os comandos com `DOCKER_CONFIG=/tmp/d4a-docker`. Isso não muda a verificação de assinatura/TLS dos downloads.

A suíte usa PHP 8.2, MariaDB 11.4 real, transporte de tokens simulado para regras de negócio e processos PHP separados para concorrência. Também executa cURL real contra um provedor TLS local, com certificado de teste explicitamente confiado, e testa as rotas HTTP em implantação temporária com login simulado no servidor. Não existe bypass de autenticação/TLS no código de produção. Imagens base estão fixadas por digest; atualizar conscientemente em manutenção. Não precisa de internet durante a execução após baixar/construir as imagens. Banco efêmero, sem porta publicada. O runner recria tabelas exclusivamente em `oauth_test`.

Cobertura: state aleatório/hash/expiração/uso único, vínculo à sessão/usuário/empresa, replay, recusa do provedor, parâmetros inválidos, geração de novos estados, troca de código, tokens cifrados, detecção de adulteração/troca entre empresas e campos, expiração, rotação, resposta inválida, erro de rede, processo interrompido, renovação em dois processos e callback duplicado concorrente, isolamento de empresas e logs por allowlist.

Os testes simulados não confirmam endpoints, HTTP Basic aceito pelo Bling, aprovação do aplicativo, extensões do PHP na Hostinger, SSO, comportamento do Apache ou validade de credenciais reais. Essas validações continuam necessárias.

## Resultado local registrado

Em 24/10/2026: **95 checks aprovados, 0 falhas**, em PHP 8.2 e MariaDB 11.4. Incluem POST Basic, enable-jwt: 1 e form-urlencoded com escape correto, TLS confiável, rejeição de hostname incorreto/certificado não confiável, bloqueio de redirect, login local, provisionamento de hash, isolamento de permissões, revogação/expiração de sessões, rotas protegidas, CSRF e remoção da query antes do resultado. A hospedagem e o Bling real não foram acessados. A documentação oficial, inicialmente bloqueada pelo proxy, foi acessada posteriormente; endpoints e requisitos JWT foram conferidos.

## Homologação na Hostinger / hPanel, após configuração oficial

1. **Proteção física:** solicitar `/private/settings.php`, `/sql/schema.sql`, `/.git/config` e caminhos equivalentes por todos os domínios da conta. Esperado 403/404, sem conteúdo, sem download de PHP. Confirmar que nenhuma pasta privada é servida por alias.
2. **HTTPS:** certificado válido e callback HTTP recusado, sem redirect contendo query. Certificar que cabeçalhos enviados pelo cliente não permitem simular HTTPS.
3. **Login:** sessão anônima recebe recusa em connect e callback. Usuário autenticado sem permissão também. Confirmar expiração de login, desligamento de usuário e mudança de associação empresarial.
4. **CSRF:** GET mostra formulário; POST sem CSRF/CSRF errado não gera state nem redireciona ao Bling. Reenvio do mesmo POST não cria outro fluxo. Métodos diferentes de GET/POST recebem 405 nas rotas correspondentes.
5. **Consentimento real:** com conta Bling controlada, iniciar pelo formulário. Revisar nome do aplicativo e permissões. Consentir: callback consome state, cifra os tokens e redireciona para resultado sem query. Não capturar URL com code nem copiá-la para tickets.
6. **Recusa:** negar consentimento retorna falha genérica. Nova tentativa deve funcionar com novo state. Sessão expirada durante consentimento exige login e novo fluxo.
7. **Replay e separação:** reutilizar state de homologação deve falhar, sem nova troca de código. Empresa B e outro usuário não podem concluir state de A. Executar com empresas de teste distintas e verificar no banco que cada linha é isolada, sem copiar cifras para logs.
8. **Expiração:** em homologação isolada, expirar state via banco; callback deve falhar. Fazer nova conexão e testar renovação sob demanda com expiração local ajustada (não alterar tokens). Duas chamadas internas concorrentes devem renovar uma única vez. Confirmar tokens rotacionados e novo prazo sem exibir valores.
9. **Falhas:** simular timeout/rejeição em ambiente de teste; a conexão deve exigir reautorização sem repetir refresh. Simular interrupção após marcador durável; a próxima chamada também exige reconectar.
10. **Logs:** com marcadores sintéticos, conferir logs privados da aplicação e todos os logs do Apache/CDN/WAF/APM. Não inserir credenciais reais em marcadores. Esperado nenhum code/state/token/secret, corpo HTTP ou query OAuth. Confirmar retenção, acesso restrito e rotação.
11. **Dados:** confirmar que nenhum endpoint de negócio do Bling foi chamado e nenhuma operação de escrita ocorreu. Revogar a conta de homologação no painel ao concluir se não for mantida em uso.

Registrar data, versão do PHP/MySQL, versão/commit implantado, referências oficiais conferidas e resultado sem copiar valores sensíveis. Não marcar produção como aprovada enquanto login real, endpoints oficiais, rede, logs e consentimento de ponta a ponta permanecerem pendentes.

## Regressão de navegação no Chromium (24/10/2026)

A CSP global `form-action 'self'` bloqueava no Chrome o redirect externo após o POST de conexão. O primeiro clique consumia o CSRF e um segundo clique retornava `csrf_invalid`. Em `public/bling/connect.php`, a CSP agora permite também o origin HTTPS do endpoint de autorização validado pela configuração, tanto no formulário quanto na resposta POST. As demais páginas mantêm a política restrita.

Foram aprovados 95 checks PHP/MariaDB e 2 verificações reais no Chromium: reprodução do bloqueio com a política anterior e aceitação do redirect com a política corrigida. O teste de navegador usa dois origins locais e não contata o Bling; a suíte PHP verifica separadamente o host real no cabeçalho. Para executar a regressão com Node, Playwright e Chromium já instalados: `node tests/browser-csp.cjs` (opcional: definir `CHROMIUM_PATH`). Nenhuma verificação de certificado foi desabilitada. Após atualizar manualmente o arquivo, o usuário confirmou consentimento e renovação reais na Hostinger; ver STATUS.md. A SEC-001 continua aberta.

Implantação desta correção: substituir apenas `public/bling/connect.php` na pasta pública `automacoes/bling/connect.php`, preservando arquivos privados e `private-path.php`. Abrir uma nova página de login para iniciar um formulário novo, sem reenviar o POST antigo.

## Homologação real reportada e limitação de segurança

Em 24/10/2026 o usuário confirmou conexão PHP/MySQL, seis tabelas, login local, consentimento Bling e renovação controlada bem-sucedidos. Os comandos foram executados pelo usuário no seu SSH, sem exposição de tokens ao Codex. O item de logs de infraestrutura **não passou**: o suporte confirmou query OAuth nos access logs, sem garantia de redação no Premium. Ver [SEC-001](SECURITY.md). A continuidade de desenvolvimento foi solicitada com a pendência registrada; não equivale à aprovação de produção. Nenhuma operação de negócio/leitura/escrita foi executada nesta homologação OAuth.

## Produtos/estoque — 24/10/2026

139 checks PHP/MariaDB aprovados após a extensão de leitura, sem credenciais reais. Inclui painel renderizado contra banco real e transporte de dados simulado, tokens por empresa, validação de paginação/IDs, campos ausentes, saldos fracionários/negativos, erros HTTP/JSON, ausência de retry, allowlist de URLs, XSS e GET exclusivo. Os 95 checks citados nas seções anteriores são a linha de base histórica. Ver CATALOG.md para limites e homologação real ainda pendente. A verificação dos logs de infraestrutura permanece reprovada na SEC-001.
