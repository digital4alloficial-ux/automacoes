# Instalação no cPanel

Referência anterior para outros provedores. Para o plano Hostinger Premium informado, siga HOSTINGER.md e LOGIN.md. A seção de adaptador abaixo só se aplica a `auth_mode=adapter`; o projeto agora oferece login local padrão.

## 1. Validar a hospedagem

No MultiPHP Manager, selecionar PHP 8.2 ou superior para `api.digital4all.com.br`. No seletor de extensões, habilitar `curl`, `pdo_mysql`, `sodium`, `openssl`. Pedir ao suporte se não estiverem disponíveis. O código depende de inteiros de 64 bits (padrão em servidores atuais).

Confirmar InnoDB, MySQL 8.0+ / MariaDB 10.6+ e permissão para `GET_LOCK`/`RELEASE_LOCK`. Confirmar saída HTTPS ao host exato dos endpoints após consulta oficial, CA bundle funcional e timeout de execução PHP de pelo menos 60 segundos (espera de lock até 30 s mais requisição externa até 20 s). Se o serviço limitar requisições a menos disso, ajustar os limites em conjunto, preservando a serialização e testando concorrência.

**Logs da infraestrutura:** solicitar ao suporte que não armazene query strings de `/bling/callback.php` em access logs, CDN, WAF, analytics/APM, traces e ferramentas de suporte. A linha padrão `%r` do Apache inclui a query. Não basta remover `%q` se `%r` continuar presente. É necessário usar caminho sem query, por exemplo `%m %U %H`, com o suporte administrando o formato/condição correta. Não habilitar debug HTTP. Não enviar capturas de tela contendo a URL completa do callback.

## 2. DNS, TLS e diretórios

Criar o DNS de `api.digital4all.com.br` apontando para a hospedagem e instalar AutoSSL válido antes de registrar/testar o callback.

Estrutura de exemplo (substituir `USUARIO` pelo usuário real):

```text
/home/USUARIO/digital4all-bling/
    private/
        config.php
        bootstrap.php
        oauth.php
        settings.php       ← criado manualmente a partir do exemplo
        auth.php           ← integração real de identidade
        src/
        logs/              ← 0700
    public/                ← ÚNICO document root do subdomínio
        .htaccess
        bling/
            connect.php
            callback.php
            result.php
    sql/schema.sql         ← não publicar
    docs/                  ← não publicar
```

No cPanel → Domínios, definir o document root do subdomínio como `/home/USUARIO/digital4all-bling/public`. Não publicar a raiz do repositório. `private/` também deve ficar fora do document root de **todos os outros domínios**, incluindo `/home/USUARIO/public_html`. Não criar aliases ou symlinks públicos para essas pastas. O caminho relativo dos includes presume essa estrutura; não copiar apenas `public/bling` para outro lugar sem adaptar e revisar os includes.

Se seu plano só permitir document roots dentro de `public_html`, solicitar ao suporte uma disposição equivalente com includes privados fora dele antes de implantar. Não usar `.htaccess` como única proteção de segredos. Os arquivos de negação incluídos são uma defesa adicional, dependente de Apache/AllowOverride, e não substituem a separação física.

Enviar arquivos pelo gerenciador de arquivos/SFTP preservando `.htaccess`. Não enviar `.git`, testes, Dockerfile, credenciais de teste ou documentação para a pasta pública. Nenhuma instalação Composer é necessária. Desativar exibição de erros também no MultiPHP INI Editor: `display_errors=Off`, `log_errors=On`; evitar logs de stack traces com argumentos e APM de captura de variáveis. Recomenda-se `zend.exception_ignore_args=On`.

## 3. Banco e chaves

Criar um banco e usuário exclusivos pelo assistente MySQL do cPanel. Importar `sql/schema.sql` no phpMyAdmin usando o usuário administrador de instalação. Para a aplicação, conceder apenas SELECT/INSERT/UPDATE/DELETE nas três tabelas; remover privilégios de schema após a importação. Não usar root. Manter o banco local; banco remoto exige TLS do PDO configurado e validado separadamente.

Provisionar cada empresa com ID estável do seu back office:

```sql
-- Exemplo de dados NÃO reais: usar os IDs do sistema de identidade existente.
INSERT INTO companies (id, name) VALUES (123, 'Empresa de homologação');
```

OAuth não cria empresas nem usuários a partir de parâmetros HTTP. Estabelecer processo autorizado para cadastro, exclusão e mudança de titularidade. Restringir quem possui acesso direto ao banco: isolamento da aplicação não protege contra administradores de banco ou da conta de hospedagem.

Copiar `private/settings.example.php` para `private/settings.php`. Preencher DSN, usuário, senha, client ID e client secret do aplicativo público usando o editor privado, nunca em JavaScript ou em arquivos públicos. Não registrar senhas no histórico do terminal. Confirmar permissões 0600 nos dois arquivos privados com credenciais/identidade e 0700 em `private/logs` (processo PHP deve ser o proprietário ou ter acesso estritamente necessário).

Gerar **duas chaves independentes**, em terminal seguro com PHP:

```sh
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
```

Executar duas vezes: uma para `encryption_key` e outra para `binding_key`. A saída é secreta: inserir diretamente na configuração privada; não colar em chat, Git, logs ou tickets. Com File Manager apenas, gerar via ferramenta confiável local; não criar um gerador público na hospedagem. Criar `private/logs/oauth.jsonl` vazio e gravável pelo PHP, permissão 0600. Evitar diretórios graváveis por outros usuários.

Backup criptografado das chaves separado do backup do banco. Perder a chave exige reconectar todas as contas. Não substituir a chave em uma instalação ativa sem migrar as cifras: esta versão não inclui rotação online de chave. Nunca desabilitar Sodium/TLS para recuperar acesso.

## 4. Integrar o login Digital4All

Copiar `auth.example.php` para `auth.php` e implementar a função retornada usando o sistema real. Ela deve consultar o login e as permissões atuais e retornar exatamente:

```php
return ['principal_id' => $idUsuarioConfiavel, 'company_id' => $idEmpresaAutorizada];
```

`principal_id` é string e `company_id` é inteiro positivo. Esses valores devem vir de registros/autorização do servidor, não de parâmetros GET/POST, cabeçalhos ou cookies do cliente. Um usuário pode ter acesso a várias empresas; a seleção deve ser validada no servidor toda vez. Verificar associação ativa e permissão específica de administrar integrações **no connect e no callback**. Provisionar o mesmo ID em `companies`. Sem permissão, retornar `null`.

O bootstrap inicia sessão host-only `__Host-D4A`, Secure, HttpOnly e SameSite=Lax no subdomínio da API. O login de `digital4all.com.br` não é compartilhado automaticamente com essa sessão. Integrar por um mecanismo existente de SSO autenticado no servidor, ou adaptar cuidadosamente o bootstrap ao armazenamento de sessões do sistema real. Não aumentar o escopo do cookie para todo o domínio apenas para contornar a integração. Regenerar o ID no login/elevação de privilégios; logout deve invalidar a sessão. Renovação/alteração do ID durante o consentimento invalida o state por projeto. Não usar login, empresa, papel ou senha recebidos pela URL como atalho.

Requisitos de negócio ainda a definir com a Digital4All: sistema de login, origem dos IDs, mecanismo de SSO e política de quem pode substituir a conexão de uma empresa. O adaptador de exemplo permanece fechado até isso ser resolvido.

## 5. Configurar aplicativo Bling e endpoints

Seguir [BLING.md](BLING.md) e o manual oficial. Registrar aplicativo público, redirect URI exata, permissões mínimas e cumprir aprovação/publicação exigida. Não compartilhar client secret com clientes. Preencher `authorize_url`, `token_url` e habilitar `endpoints_verified` apenas após confirmar o contrato inteiro. Os endpoints já estão preenchidos no exemplo conforme conferência documental; credenciais e autenticação reais continuam obrigatórias.

## 6. Homologar e operar

Executar [TESTING.md](TESTING.md). O formulário inicial é `https://api.digital4all.com.br/bling/connect.php`; exige login e permissão, não usar links diretos à autorização com state estático. A página de resultado não prova, por si só, que os dados da conta Bling correspondem à empresa correta. Antes de uso de dados em uma etapa futura, verificar a identidade da conta via endpoint de leitura oficialmente documentado e implementar confirmação empresarial. Nenhuma rota de dados foi implementada agora.

Não é necessário cron para renovar: o método interno `OAuth::accessToken($principal)` faz isso quando o backend precisar do token. Nunca expor seu retorno ao navegador, nunca instanciar Principal com empresa da requisição, nunca usar o secret no frontend. Esta versão não contém operações de dados ou testes de escrita no Bling.

Rotacionar logs por tamanho/prazo em local privado (ex.: ferramenta do provedor ou cron) sem mover arquivos para `public/`. O logger só registra data UTC, evento permitido e ID aleatório de referência, sem IDs de usuários/empresas, IPs, parâmetros, códigos, tokens ou mensagens do provedor. Monitorar `reauthorization_required` e falhas com o suporte; mensagens ao cliente são genéricas.

Para desconectar nesta etapa, revogar a autorização no painel do Bling e, mediante procedimento administrativo autorizado, limpar as cifras e marcar a conexão como `disconnected`; invalidar estados pendentes. Não há endpoint público de revogação e nenhuma URL de revogação foi presumida. Revogação remota não é detectada por um access token ainda não utilizado; uma futura camada de API deverá tratar 401/403 sem ciclos de refresh.
