# Pendências de segurança

## SEC-001 — Query OAuth nos logs da infraestrutura

| Campo | Registro |
|---|---|
| Estado | **Aberta — requisito de segurança não atendido** |
| Registrada em | 24/10/2026 |
| Ambiente | Homologação, Hostinger Premium, api.digital4all.com.br |
| Rota | /bling/callback.php |
| Responsável pela decisão de infraestrutura | Digital4All, com suporte do provedor |
| Prazo e solução escolhida | Ainda não definidos |
| Produção para novos clientes | Não aprovada enquanto este requisito permanecer pendente |

### Evidência e limites da verificação

O usuário trouxe a confirmação do suporte Hostinger de que os access logs registram a linha completa da requisição, incluindo `code` e `state`. Segundo a resposta recebida, o plano Premium não permite garantir a omissão/redação seletiva desses parâmetros nos logs de acesso, CDN ou monitoramento; o WAF estava desativado para o subdomínio no momento da verificação. Essa informação foi recebida na conversa, não por inspeção direta dos logs pelo Codex. Não copiar requisições reais ou valores sensíveis para este registro, GitHub ou tickets.

### Impacto

Pessoas ou sistemas com acesso aos logs podem visualizar o código de autorização e o state durante sua validade. Um código ainda válido e não consumido merece proteção mesmo com autenticação do aplicativo exigida na troca. Códigos já consumidos/expirados não devem ser reutilizados. A presença desses parâmetros nos access logs, isoladamente, não demonstra vazamento de access_token, refresh_token ou client_secret. Não há evidência registrada de comprometimento desses segredos.

O requisito original de não registrar códigos OAuth na infraestrutura permanece descumprido. Continuidade de desenvolvimento/homologação não é comprovação de correção nem autorização de entrada em produção para clientes.

### Mitigações já implementadas

- Troca do código no callback e state vinculado a usuário, empresa e sessão, com expiração/uso único.
- Logger da aplicação com allowlist de eventos e sem códigos, tokens, segredos, URLs completas ou corpos HTTP.
- Redirect para página de resultado sem query após o processamento normal do callback autenticado.
- Cabeçalhos `Cache-Control: no-store` e `Referrer-Policy: no-referrer`; páginas do fluxo sem recursos externos.
- Tokens criptografados, segredos fora da pasta pública e acesso por empresa.

Essas medidas não evitam o log da requisição original feito antes da execução do PHP. Não desabilitar state, CSRF, TLS ou criptografia como contorno.

### Continuidade autorizada

Em 24/10/2026 o usuário solicitou registrar a pendência e continuar o projeto. Prosseguir com desenvolvimento e testes controlados, mantendo a pendência visível. Não interpretar o pedido como aceite de risco para clientes de produção. Preservar as limitações de escopo: nenhuma operação de escrita no Bling nesta etapa.

### Alternativas de resolução

1. Mover apenas o subdomínio/API para infraestrutura em que seja possível configurar e verificar todos os logs relevantes. Pode ser serviço da própria Hostinger, como VPS, desde que o controle de servidor, proxies e monitoramento seja confirmado. O site principal pode permanecer no Premium.
2. Criar receptor intermediário com logs controlados. Encaminhar a autorização à aplicação por canal autenticado e protegido contra replay, sem voltar a incluir code/state em URLs registradas pela hospedagem. Verificar também os logs de corpo, erros, WAF, traces e APM desse canal. Não há receptor implementado ou provisionado.

O pedido de continuidade não escolhe ou contrata uma dessas alternativas. Apenas mudar a URL por redirect com os mesmos parâmetros não resolve.

### Critérios para encerramento

- Identificar a infraestrutura definitiva e mapear acesso, proxy/CDN, WAF, erros, monitoramento/APM e seus operadores.
- Configurar omissão/redação antes da gravação em todas as camadas que recebem a autorização; manter autenticação, state e TLS.
- Testar com marcadores sintéticos sem tokens reais e confirmar ausência dos valores nos registros, inclusive do eventual canal de encaminhamento.
- Registrar evidência sem valores sensíveis e conferir consentimento/renovação após a mudança.
- Revisar acesso, retenção e tratamento dos logs históricos com o provedor; não declarar que logs anteriores foram apagados sem comprovação.
- Atualizar este registro e aprovar separadamente a prontidão de produção.
