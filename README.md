# Digital4All · Bling OAuth 2.0

Integração PHP 8.2+ para múltiplas empresas, destinada ao subdomínio `api.digital4all.com.br`. Implementa autorização, callback, armazenamento criptografado e renovação. **Não realiza operações de negócio, leitura ou escrita na API do Bling.** Os únicos POSTs do provedor são trocas OAuth.

O Codex desenvolve e testa o código; a aplicação PHP na Hostinger mantém a autorização OAuth com o Bling. Conectar ferramentas diretamente ao Codex exigiria uma camada adicional de ferramentas autenticadas, fora do escopo implementado.

## Arquitetura

```text
Cliente autenticado na Digital4All
  → GET /bling/connect.php → formulário protegido por CSRF
  → POST /bling/connect.php → autorização no Bling com state aleatório
  → GET /bling/callback.php → valida e consome state → troca code por tokens
  → /bling/result.php → resultado sem tokens ou parâmetros OAuth

public/bling/     Rotas públicas, sem configurações ou credenciais
private/         Fora do document root: config.php, oauth.php, autenticação e chaves
sql/schema.sql   Empresas, conexões por empresa e estados temporários
MySQL/InnoDB     Cifras autenticadas e bloqueios de concorrência por empresa
```

O `state` tem 256 bits, TTL de 10 minutos e só seu hash é persistido. Ele é vinculado ao usuário, empresa e sessão autenticada; é consumido uma vez, inclusive quando há recusa do provedor. Uma nova autorização invalida a tentativa anterior da empresa. O POST inicial exige CSRF e tem intervalo mínimo de 10 segundos por sessão. O callback exige o mesmo usuário e a mesma empresa; mudar de empresa ou perder a sessão exige reiniciar o fluxo.

Tokens usam XChaCha20-Poly1305 com nonce aleatório e contexto autenticado contendo empresa e tipo do token. Mover uma cifra entre empresas ou entre campos invalida a autenticação. A chave fica fora do banco e da pasta pública. PDO usa prepared statements nativos. Não há endpoint que retorne tokens, nem parâmetro HTTP que selecione o titular da conexão.

A renovação ocorre sob demanda, 60 segundos antes da expiração informada pelo provedor. `GET_LOCK` serializa conexão/renovação por empresa, sem bloquear as demais. Um marcador durável é salvo antes da renovação e removido no mesmo UPDATE que persiste os dois novos tokens. Queda do processo, timeout, erro do provedor ou resposta inválida exigem reconexão: não há retry automático de refresh potencialmente consumido. O prazo é contado desde o início da requisição, conservadoramente. Não há renovação em background nem garantia de conexão permanente quando o cliente fica inativo.

## Estado e pré-requisitos de produção

**Ainda não habilitado para produção.** Não foram fornecidos aplicativo/credenciais Bling ou acesso ao hPanel da Hostinger. Foi implementado login próprio, ainda sem contas de produção criadas. A documentação oficial agora foi consultada: os endpoints foram preenchidos no exemplo e o cliente solicita JWT na troca e renovação. Veja [contrato oficial conferido](docs/BLING.md). Não houve consentimento real nem implantação na Hostinger.

O modo padrão agora é login próprio (`auth_mode=local`), sem autocadastro e sem contas padrão. A Digital4All provisiona usuários e vínculos por empresa pelo phpMyAdmin. Veja [login e primeiro cliente](docs/LOGIN.md). O modo alternativo `adapter` continua disponível para quem integrar outro sistema de identidade; seu exemplo nega acesso por padrão. Nenhum dos modos autoriza uma empresa apenas pelo ID recebido do navegador.

Hospedagem compartilhada precisa oferecer:

- PHP 8.2+, `curl`, `pdo_mysql`, `sodium`, `openssl`, sessões graváveis, CA bundle atualizado;
- MySQL 8.0+ ou MariaDB 10.6+, InnoDB e `GET_LOCK`/`RELEASE_LOCK` permitidos;
- AutoSSL e HTTPS real no Apache, saída TCP/HTTPS 443 aos endpoints oficiais do Bling;
- pastas privadas fora de todos os diretórios públicos; no hPanel, usar `private-path.php` para apontar para a pasta privada sem mover o document root;
- controle dos access logs/proxy/WAF para não gravar query strings do callback.

Se o provedor registrar a linha HTTP completa, ela poderá conter `code` e `state` antes do PHP executar. `.htaccess` e o logger da aplicação **não corrigem logs do Apache, CDN, WAF ou backups já criados**. Solicite ao provedor omissão/redação da query na rota callback em todas essas camadas. Sem isso não é possível garantir o requisito de ausência de códigos nos logs. TLS terminado em proxy exige configuração confiável pelo provedor; o código não aceita `X-Forwarded-Proto` enviado pelo cliente. Não requer daemon, Redis, Composer, SSH ou cron para funcionar.

## Arquivos e instalação

- [Login próprio e primeiro cliente](docs/LOGIN.md)
- [Instalação na Hostinger / hPanel](docs/HOSTINGER.md)
- [Referência anterior para cPanel](docs/CPANEL.md)
- [Contrato e verificação da documentação Bling](docs/BLING.md)
- [Roteiro de testes e homologação](docs/TESTING.md)
- [Banco de dados](sql/schema.sql)
- [Configuração privada de exemplo](private/settings.example.php)
- [Adaptador de autenticação](private/auth.example.php)

## Testes locais

Com Docker e Compose v2, em um ambiente de desenvolvimento (não na hospedagem):

```sh
docker compose -p d4a-oauth-test -f tests/compose.yaml up --build --abort-on-container-exit --exit-code-from test
docker compose -p d4a-oauth-test -f tests/compose.yaml down --volumes
```

O banco de teste é efêmero, não expõe portas e não usa credenciais de produção. O runner recusa nomes de banco diferentes de `oauth_test` e recria apenas as tabelas da suíte. O provedor é simulado; não confundir testes locais com homologação no Bling. Para rodar sem Docker, disponibilize PHP com as extensões e um MySQL isolado com o usuário/senha de teste descritos em `tests/support.php`, e execute `TEST_DSN='mysql:host=127.0.0.1;dbname=oauth_test;charset=utf8mb4' php tests/run.php`.
