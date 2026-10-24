# Estado do projeto — 24/10/2026

## Concluído

- Aplicação PHP com login local, permissões por empresa, OAuth Bling, tokens criptografados e renovação concorrente.
- Testes locais registrados: 139 checks PHP/MariaDB aprovados após adicionar produtos/estoque, com provedor simulado. As 2 verificações no Chromium da correção de CSP foram aprovadas anteriormente e não foram alteradas nesta etapa.
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

## Produtos e estoque implementados, aguardando implantação

Após escolher Produtos e estoque, o usuário autorizou seguir com consultas de leitura. Implementados listagem paginada de produtos ativos e saldos totais por lote de IDs. Endpoints/formatos confirmados na OpenAPI oficial, sem escrita. O painel reutiliza autenticação e tokens por empresa, é desabilitado por padrão e exige habilitação explícita na configuração privada de homologação.

Ver [CATALOG.md](CATALOG.md) para os quatro arquivos a publicar e a configuração necessária. Não exige migração SQL. A funcionalidade foi validada localmente com transporte simulado; não foi implantada pelo Codex e não houve consulta real de dados de produtos/estoque. A homologação real do catálogo é o próximo passo. A SEC-001 continua aberta.
