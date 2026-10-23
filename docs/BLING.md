# Contrato Bling conferido em 23/10/2026

Fontes oficiais consultadas por HTTPS após resolução do bloqueio inicial do proxy:

- https://developer.bling.com.br/aplicativos#authorization-code
- https://developer.bling.com.br/aplicativos#tokens-de-acesso
- https://developer.bling.com.br/aplicativos#refresh-token
- https://developer.bling.com.br/migracao-jwt
- https://developer.bling.com.br/mcp-server

## Aplicativo público OAuth para várias empresas

| Item | Contrato documentado e implementação |
|---|---|
| Autorização | `https://www.bling.com.br/Api/v3/oauth/authorize`, GET com `response_type=code`, `client_id`, `state`. |
| Tokens / renovação | `https://api.bling.com.br/Api/v3/oauth/token`, POST form-urlencoded, conforme página Aplicativos. |
| Autenticação do aplicativo | `Authorization: Basic base64(client_id:client_secret)` exclusivamente no servidor, não no body. |
| Headers | `Content-Type: application/x-www-form-urlencoded`, `Accept: 1.0` conforme exemplos oficiais e `enable-jwt: 1` na obtenção **e** renovação. |
| Troca | Body `grant_type=authorization_code` e `code`. O código expira em **1 minuto** e não pode ser reutilizado. |
| Renovação | Body `grant_type=refresh_token` e `refresh_token`; salvar o novo par retornado. O guia informa validade de refresh de 30 dias. |
| Resposta | `access_token`, `refresh_token`, `token_type=Bearer`, `expires_in` em segundos e `scope`. O prazo do access token é calculado da resposta, sem fixar 6 horas. |
| Redirect | Registrar exatamente `https://api.digital4all.com.br/bling/callback.php` no cadastro do aplicativo. O Bling informa que redirect_uri/scope da requisição não substituem o cadastro. |
| Escopos | Configurados no aplicativo; selecionar os mínimos de leitura necessários. Alterá-los revoga usuários e exige nova autorização, conforme documentação. |
| Visibilidade | Aplicativo público para operar outras contas exige processo de homologação do Bling. |
| PKCE | A página Aplicativos descreve Authorization Code de cliente confidencial com HTTP Basic, sem exigir parâmetros PKCE nesse fluxo. O fluxo MCP é distinto e usa OAuth 2.1/PKCE. |

A página de migração JWT orienta o header `enable-jwt: 1` em todas as trocas/renovações e nas futuras chamadas de API. Seus exemplos cURL também mostram `/oauth/token` sem o prefixo, enquanto a página Aplicativos e os exemplos HTTP exibem `/Api/v3/oauth/token`. O projeto usa a URL completa explicitamente documentada em Aplicativos; não alterna URLs nem segue redirects automaticamente. Confirmar aceitação em homologação real com o aplicativo da Digital4All.

JWTs podem ter cerca de 1.500–3.000 caracteres; o banco usa TEXT para as cifras e o cliente aceita tokens maiores. O JWT é tratado como credencial opaca pelo nosso sistema: não se extraem claims sem validação de assinatura para decidir empresa, usuário ou permissões. Não é necessário validar localmente sua assinatura apenas para armazenar o token e encaminhá-lo ao provedor; nenhuma decisão de identidade local usa suas claims.

`state` da aplicação dura 10 minutos para permitir consentimento; isso não estende o prazo de 1 minuto do `code`. Se o código expirar durante processamento/espera, a pessoa precisa iniciar nova autorização. Não se repete a troca automaticamente. A página de erros alerta que reutilizar um code pode revogar o usuário, reforçando o consumo único no banco.

O exemplo privado já contém os endpoints e `endpoints_verified=true` referentes à conferência documental. Isso **não** significa que credenciais, login, aprovação do aplicativo, infraestrutura ou consentimento real foram validados. O modo padrão agora oferece login próprio, testado localmente, sem usuários de produção criados. O adaptador externo alternativo continua negando acesso por padrão. Não foram chamadas operações de negócio, escrita ou revogação no Bling.

## Codex conectado diretamente: MCP oficial

O Bling documenta um servidor MCP hospedado em `https://mcp.bling.com.br/mcp`, usando Streamable HTTP e OAuth 2.1 com PKCE. A configuração e o suporte OAuth dependem da versão/cliente do Codex. Um cliente compatível pode conectar-se ao servidor oficial sem hospedar um MCP próprio na Hostinger. Nenhuma conexão MCP foi instalada ou autorizada nesta tarefa.

Essa opção não substitui automaticamente o aplicativo público multiempresa: segundo o FAQ, cada cliente MCP autoriza uma conta Bling por vez. O catálogo MCP inclui operações de escrita; nesta etapa somente consultas podem ser consideradas, com ferramentas/permissões de escrita desabilitadas no cliente compatível. A conexão só deve ser habilitada após escolher o cliente e conferir seus controles; não basta orientar o assistente a não escrever se o controle técnico exigido não existir.

Não usar client_secret da aplicação PHP como configuração pública do Codex. Autorizar o MCP pelo fluxo próprio do Bling no navegador. Não copiar tokens para arquivos versionados ou para o chat. Não houve acesso a dados de clientes nesta tarefa.
