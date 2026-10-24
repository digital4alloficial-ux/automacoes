# Estado do projeto — 24/10/2026

## Concluído

- Aplicação PHP com login local, permissões por empresa, OAuth Bling, tokens criptografados e renovação concorrente.
- Testes locais registrados: 95 checks PHP/MariaDB e 2 verificações no Chromium para a correção de CSP. Resultados históricos, não reexecutados nesta atualização documental.
- Documentação oficial de endpoints e JWT consultada; correção de CSP enviada ao GitHub para permitir o redirect ao Bling.

## Homologação real informada pelo usuário

Implantação feita manualmente pelo usuário no Hostinger Premium/hPanel. Sem acesso direto do Codex à conta/SSH/credenciais da hospedagem:

- Subdomínio api.digital4all.com.br e SSL ativados; PHP 8.3.35 e extensões informados como disponíveis.
- MySQL/PDO e as seis tabelas da aplicação responderam ao diagnóstico.
- Login local e seleção de empresa realizados.
- Consentimento no Bling concluído com a mensagem “Bling conectado com sucesso”.
- Renovação controlada da empresa ID 1 concluída: status connected, novo prazo de expiração salvo e refresh_in_flight=0.

Esses resultados validam o fluxo funcional reportado; não equivalem à verificação de todas as proteções e permissões da infraestrutura ou à publicação/aprovação do aplicativo público pelo Bling.

## Pendência aberta

[SEC-001 — Query OAuth nos logs da infraestrutura](SECURITY.md). A Hostinger confirmou ao usuário que o plano atual registra os parâmetros do callback e não oferece garantia de redação seletiva. A mitigação no PHP não elimina esse registro.

Desenvolvimento e homologação podem continuar conforme solicitação do usuário. O requisito continua aberto e a liberação de novos clientes de produção permanece pendente.

## Próxima etapa

Escolher o primeiro recurso de **leitura** a integrar (produtos/estoque, pedidos ou financeiro) e confirmar os endpoints e escopos correspondentes na documentação oficial. Ainda não há operação de leitura de negócio implementada nem requisições desse tipo executadas pelo Codex. Nenhuma operação de escrita está autorizada nesta etapa.

A próxima implementação deve reutilizar a identidade autorizada e o gerenciador de tokens, limitar consultas por empresa, validar paginação/filtros e não expor tokens, dados de outras empresas ou corpos de resposta nos logs. Consultas reais devem permanecer controladas e seus dados não devem ser publicados no repositório.
