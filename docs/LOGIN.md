# Login próprio da Digital4All

Como a empresa ainda não tem um sistema de identidade, o projeto inclui login local em `/bling/login.php`, painel de empresas em `/bling/account.php` e logout por POST. A sessão do login e do OAuth usa o mesmo subdomínio e cookie host-only. Não é necessário compartilhar cookies com o site principal.

## Instalação no Hostinger Premium / hPanel

1. Confirmar PHP 8.2+, PDO MySQL, Sodium, cURL, MySQL/InnoDB e pasta privada conforme HOSTINGER.md. O plano Premium informado não foi inspecionado na conta; disponibilidade e limitações devem ser confirmadas no hPanel/suporte.
2. Importar `sql/schema.sql` para uma instalação nova, depois `sql/auth.sql`. Se as tabelas OAuth já existem, importar **somente `auth.sql`**. Fazer backup privado antes de qualquer alteração de banco. Não executar novamente CREATE TABLE sobre instalação ativa.
3. Publicar também `login.php`, `account.php` e `logout.php` da pasta pública e `src/LocalAuth.php` na pasta privada. Usar a versão atualizada de `private/bootstrap.php`.
4. Em `private/settings.php`, usar `'auth_mode' => 'local'`. O exemplo atual já usa esse modo. `auth_adapter` continua como caminho no formato de configuração por compatibilidade, mas seu arquivo não é executado no modo local. Não criar bypass ou login fixo no adaptador. Bling e banco ainda precisam das configurações privadas descritas em HOSTINGER.md.
5. Criar o primeiro usuário e seu vínculo no phpMyAdmin, conforme abaixo. Não há usuário, senha ou conta administrativa padrão.

## Criar o primeiro cliente com phpMyAdmin

A administração de contas nesta versão é manual e fica restrita a quem administra o banco pelo hPanel. Não existe painel de administração público nem autocadastro. Quem administra o hPanel não deve compartilhar esse acesso com clientes.

Escolher uma senha única com 16 a 72 bytes (preferir gerenciador de senhas). Para gerar seu hash sem enviar a senha a sites externos, usar **PHP local** e o utilitário `tools/password-hash.php`:

```sh
php tools/password-hash.php /caminho/privado/senha.txt
```

O arquivo deve conter a senha em uma linha, fora da pasta pública e do repositório, protegido contra leitura de outros usuários. O comando imprime apenas o hash, para uso privado no cadastro. Não colocar a senha diretamente como argumento, em chat ou em SQL. Não enviar esse arquivo à hospedagem. Remover a cópia plaintext quando não for mais necessária; manter a senha no gerenciador. Se não houver PHP local ou meio confiável de gerar o hash, preparar esse passo em uma máquina confiável; não criar gerador de hash público no site.

No phpMyAdmin, usar as telas **Inserir** (evita interpolar manualmente valores em SQL):

- `companies`: inserir o ID interno escolhido pela Digital4All e o nome da empresa, se ainda não existir.
- `app_users`: deixar `id` automático, preencher email ASCII em minúsculas e `password_hash` com o hash gerado; `active=1`, `session_version=1`.
- `company_users`: preencher `user_id` gerado, `company_id` da empresa e `can_manage_bling=1`.

Acessar `https://api.digital4all.com.br/bling/login.php`, entrar, selecionar a empresa e iniciar o consentimento Bling. Entregar acesso ao cliente por canal seguro e previamente verificado. Nunca fornecer credenciais ou acesso ao hPanel/DB ao cliente. Um usuário pode ter vários vínculos; cada vínculo deve ser criado expressamente pela Digital4All após conferir legitimidade e poderes sobre a empresa.

## Segurança e operação

- Senhas são armazenadas com `password_hash(PASSWORD_DEFAULT)` e validadas com `password_verify`, não criptografia reversível. A política de provisionamento exige 16–72 bytes e evita truncamento do bcrypt. Não existem senhas padrão.
- Login e seleção de empresa exigem CSRF. O ID da sessão muda no login e na seleção da empresa; a mudança invalida um consentimento OAuth em andamento. O logout exige POST/CSRF e destrói a sessão.
- Cookie Secure, HttpOnly, SameSite=Lax. Inatividade máxima de 30 minutos e duração absoluta máxima de 8 horas. Conta ativa, versão da sessão e permissão de administrar a integração são consultadas no banco a cada rota protegida, inclusive callback.
- Até 8 tentativas por email e 40 por IP em janela de 15 minutos, inclusive tentativas válidas. O bloqueio persiste entre sessões/processos. Mensagem de falha é genérica, sem confirmar existência do email ou causa da recusa. Os buckets guardam HMAC, sem email ou IP em texto. Não se confia em `X-Forwarded-For`; se houver proxy, avaliar o limite agregado com o provedor.
- A tabela de limites pode crescer com emails/IPs distintos. Manutenção privada pode apagar linhas com `window_start` mais antigo que 24 horas; nunca apagar a janela ativa para contornar bloqueios. Não há cron obrigatório para o funcionamento, mas definir retenção e monitoramento antes de escalar.
- Para desativar: `active=0` e incrementar `session_version` no usuário. Para revogar só uma empresa: remover vínculo ou definir `can_manage_bling=0`. Isso é verificado na próxima requisição. Revogar login não revoga o token Bling já autorizado; tratar a revogação da integração separadamente.
- Recuperação de senha é administrativa: verificar a identidade fora do sistema, gerar um novo hash e substituir `password_hash`, incrementando `session_version`. A versão incrementada invalida sessões anteriores. Não enviar senhas por email automático nem usar perguntas de segurança. Não há envio de email, link de convite/reset, MFA ou troca de senha pelo cliente nesta versão. Planejar essas funcionalidades se forem necessárias antes do lançamento público.
- Não registrar senha, cookie, corpo do POST ou email em logs/APM. As restrições de query OAuth nos logs da Hostinger continuam valendo. O login local não resolve logs da infraestrutura ou indisponibilidade de recursos do plano.

## Testes

A suíte inclui verificação de senha, usuário inexistente/inativo, bloqueio por tentativas, expiração, versão de sessão, revogação de vínculo, CSRF, troca do ID no login, seleção autorizada, recusa de empresa adulterada e logout. Provedor Bling permanece simulado. O cadastro inicial em produção e a confirmação dos recursos do hPanel ainda não foram executados.
