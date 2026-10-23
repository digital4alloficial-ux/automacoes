# Hostinger: instalação com hPanel

Domínio: `digital4all.com.br`. Subdomínio: `api.digital4all.com.br`.
Callback a registrar no aplicativo público Bling: `https://api.digital4all.com.br/bling/callback.php`.

O Codex trabalha no código e nos testes; quem mantém e renova a autorização é o PHP na Hostinger. O hPanel administra domínio, arquivos, PHP e banco. Esta versão não oferece ferramentas/MCP para o Codex consultar o Bling diretamente. Não compartilhe senha do hPanel, tokens ou client_secret no chat.

Os nomes dos menus do hPanel variam por plano e versão; localizar as funções indicadas na gestão do site. Este procedimento não foi executado em sua conta. Não há confirmação de recursos ou acesso ao seu plano.

## 1. Recursos do plano

Na gestão do site, procurar Configuração PHP e selecionar uma versão disponível **8.2 ou superior**. Confirmar `curl`, `pdo_mysql`, `sodium`, `openssl`, sessões e PHP 64 bits. Verificar PHP ativo também no subdomínio. Confirmar MySQL/MariaDB com InnoDB, prepared statements e `GET_LOCK`/`RELEASE_LOCK`, saída HTTPS 443 ao Bling e timeout adequado (60 s). Se uma extensão não estiver no seletor, confirmar com o suporte antes de implantar. PHP e MySQL são necessários; um produto apenas de criação de sites sem execução PHP não basta. SSH, Docker e cron não são necessários para produção; Docker é só para testes locais.

## 2. Subdomínio, DNS e SSL

Na seção de domínios/subdomínios do site, criar `api.digital4all.com.br`. Anotar o **diretório real** que o hPanel associa ao subdomínio; não presumir que seja `public_html/api`. Se o DNS for gerenciado fora da Hostinger, configurar lá o registro indicado pelo painel. Não alterar os registros do site principal ou de email sem necessidade. Ativar o certificado SSL do subdomínio e aguardar validade/propagação antes de usar OAuth. Esta aplicação recusa HTTP.

Se houver proxy/CDN, confirmar como o servidor PHP identifica HTTPS; não confiar em cabeçalhos encaminhados que possam ser forjados por clientes. Não ativar redirecionamento que replique parâmetros OAuth para outros hosts.

## 3. Arquivos públicos e privados

Usar o Gerenciador de Arquivos para descobrir os caminhos absolutos permitidos. Exemplo **ilustrativo**, que deve ser substituído pelo caminho real do seu plano:

```text
/home/USUARIO/domains/digital4all.com.br/
    bling-private/             ← conteúdo de private/ do projeto; FORA de public_html
        bootstrap.php
        config.php
        oauth.php
        src/
        settings.php           ← configuração secreta
        auth.php               ← opcional, apenas para modo adapter
        logs/
    public_html/
        api/                   ← apenas se este for o diretório REAL do subdomínio
            .htaccess          ← conteúdo de public/.htaccess
            bling/             ← conteúdo de public/bling/
                _bootstrap.php
                private-path.php
                connect.php
                callback.php
                result.php
                login.php
                account.php
                logout.php
```

Enviar **somente o conteúdo de `public/`** ao diretório público real do subdomínio. Não criar um nível adicional `public/` dentro dele, senão o callback deixará de corresponder à URL registrada. Enviar o conteúdo de `private/` para a pasta privada fora de todos os diretórios públicos. Não colocar SQL, testes, `.git`, ZIP ou documentação em `public_html`. Se o site principal usa outra raiz, confirmar que a pasta privada também não está exposta por ele.

Copiar `public/bling/private-path.example.php` para `private-path.php` e ajustar **somente o caminho absoluto** para `bling-private`. Esse arquivo não recebe credenciais. O carregador resolve o caminho real e recusa pastas dentro do document root ou de `public_html`, inclusive symlinks que apontem para dentro deles. Os includes relativos entre os arquivos privados devem ser preservados.

O servidor precisa permitir includes na pasta privada (`open_basedir` pode restringir isso). Se o Gerenciador de Arquivos ou o PHP não permitir acesso a uma pasta fora de todos os diretórios públicos, pedir uma disposição segura ao suporte ou mudar o plano; não mover os segredos para `public_html` como solução. Os bloqueios `.htaccess` são defesa adicional, não substituem esse isolamento. Confirmar que as diretivas incluídas são aceitas pelo servidor do plano, inclusive LiteSpeed/Apache; erro 500 exige conferir o log sem expor segredos.

## 4. Banco, configuração e autenticação

Na seção de bancos MySQL, criar banco e usuário exclusivos e importar `sql/schema.sql` pelo phpMyAdmin. A aplicação usa apenas SELECT/INSERT/UPDATE/DELETE; administrar schema com usuário separado quando o plano permitir. Usar host, nomes e prefixos exibidos no hPanel, sem presumir os prefixos de cPanel. Criar as empresas com os IDs confiáveis do sistema Digital4All, não com valores enviados pelo navegador.

Na pasta privada, copiar `settings.example.php` para `settings.php` e preencher banco e credenciais do aplicativo Bling. Gerar duas chaves independentes de 32 bytes em base64 com uma ferramenta local confiável:

```sh
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
```

Executar duas vezes e guardar uma saída em `encryption_key` e a outra em `binding_key`. Não criar página pública para gerar/exibir segredos. Criar `logs/` gravável apenas pelo usuário do PHP e `logs/oauth.jsonl` privado. Preferir diretórios 0700 e arquivos secretos/logs 0600 quando compatíveis com o usuário de execução. Separar backup das chaves do banco; trocar a chave sem migração invalida as cifras existentes.

Como a Digital4All não tem login existente, usar o login próprio (`auth_mode=local`) descrito em [LOGIN.md](LOGIN.md). Importar também `sql/auth.sql` e criar usuários/vínculos pelo phpMyAdmin. O cliente entra por `/bling/login.php`, escolhe uma empresa autorizada e inicia a conexão. Não há contas padrão ou cadastro público. O adaptador externo é opcional para futuras integrações de identidade.

## 5. Bling, logs e homologação

Seguir `BLING.md`: a documentação oficial foi consultada e os endpoints já constam no exemplo privado; o cliente envia `enable-jwt: 1` na troca e renovação. Revisar as regras do aplicativo público e homologação em https://developer.bling.com.br/, escolher permissões mínimas e manter o contrato registrado em `BLING.md`. Cadastrar o callback exato acima. Não substituir os endpoints por URLs supostas.

No PHP, desativar `display_errors`, manter erros sem argumentos sensíveis e nunca habilitar debug de requisições OAuth. Solicitar ao suporte Hostinger confirmação de que access logs, CDN/WAF/APM e ferramentas de suporte não registram query strings de `/bling/callback.php`. `%r` do Apache, por exemplo, inclui a query e pode expor `code` antes de executar PHP. Um plano compartilhado pode não permitir personalizar esse log: se o provedor não garantir a omissão/redação, esse requisito de segurança permanece bloqueado. O logger da aplicação não controla esses registros.

Executar `TESTING.md` no ambiente de homologação: login/autorizações, CSRF, consentimento e recusa, replay, expiração, duas empresas, renovação, TLS e ausência de segredos em todas as camadas de log. Começar pelo formulário `/bling/connect.php` com login real. Não há chamadas de negócio ou operações de escrita no Bling nesta versão. Antes de produção, criar os acessos, confirmar logs/recursos da hospedagem e testar consentimento real. A conferência documental dos endpoints e os testes locais do login já foram realizados.
