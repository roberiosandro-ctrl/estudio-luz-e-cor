# Estúdio Luz e Cor V2 — preparação Mercado Pago

Esta pasta contém a camada de backend do checkout e os workers de processamento. A branch também prepara a integração do frontend V2 com `create-preference.php`, mas isso não significa que esteja publicado ou habilitado: `payments_enabled` permanece `false`.

## Arquivos

- `api/bootstrap.php`: configuração privada, SQLite, validações e comunicação com a API Mercado Pago.
- `api/create-preference.php`: endpoint POST para criar uma preferência Checkout Pro, somente quando o servidor for explicitamente habilitado.
- `api/mercadopago-webhook.php`: valida a assinatura do Webhook, consulta o pagamento e confere o vínculo com a preferência salva.
- `api/health.php`: diagnóstico protegido por token privado; somente leitura, sem criar banco, tabelas, migrações ou arquivos.
- `api/process-fraud-alerts.php`: worker exclusivamente CLI para processar a fila persistente de alertas de fraude; não deve ser chamado por HTTP.
- `api/process-whatsapp-notifications.php`: worker exclusivamente CLI para enviar notificações de pagamento aprovado ao número do responsável, usando template aprovado da WhatsApp Business Platform.

## Endpoints

- `GET /api/health.php`: exige o cabeçalho HTTP `X-Health-Token`.
- `POST /api/create-preference.php`: recebe JSON como `{"package_id":"fotos-05","model_codes":["ELEG-01","ROM-02"]}`.
- `POST /api/mercadopago-webhook.php`: recebe notificações do Mercado Pago.

O Access Token nunca é enviado ao navegador. A URL de retorno do checkout não aprova pagamentos; a confirmação depende do Webhook validado e da consulta à API. Comprovantes enviados pelo comprador nunca confirmam pagamento.

## Pacotes validados no servidor

| ID interno | Pacote | Valor |
|---|---|---:|
| fotos-02 | 2 fotos | R$ 29,90 |
| fotos-05 | 5 fotos | R$ 54,90 |
| fotos-10 | 10 fotos | R$ 89,90 |
| fotos-15 | 15 fotos | R$ 109,90 |
| fotos-20 | 20 fotos | R$ 129,90 |

Os códigos permitidos correspondem às 10 coleções do catálogo versionado, com seis referências cada: ELEG, PROF, ROM, LUX, ANIV, GEST, CAS, FASH, FAM e FANT (01 a 06).

## Configuração privada — não versionar

Criar no servidor Hostinger, fora de `public_html`:

`/home/u643738877/domains/rsmtrafegopago.online/private/mercadopago-config.php`

Estrutura inicial, com pagamentos desativados e credenciais vazias:

~~~php
<?php
declare(strict_types=1);

return [
    'environment' => 'test',
    'payments_enabled' => false,
    'access_token' => '',
    'webhook_secret' => '',
    'seller_user_id' => '', // ID numérico da conta vendedora; obrigatório em produção
    'fraud_auto_refund_enabled' => false, // manter false até concluir testes sandbox do worker
    'whatsapp_notifications_enabled' => false,
    'whatsapp_graph_api_version' => 'v27.0',
    'whatsapp_phone_number_id' => '',
    'whatsapp_access_token' => '',
    'whatsapp_recipient' => '', // número do responsável em E.164, somente dígitos
    'whatsapp_template_name' => 'elc_payment_approved',
    'whatsapp_template_language' => 'pt_BR',
    'health_token' => '', // gerar valor longo e aleatório, somente no servidor
    'api_base_url' => 'https://api.mercadopago.com',
    'public_base_url' => 'https://estudioluzecor-teste1111.rsmtrafegopago.online/estudioluzecor-teste1111/estudio-luz-e-cor-identidade-agencia-flow-v2',
    'return_url' => 'https://estudioluzecor-teste1111.rsmtrafegopago.online/estudioluzecor-teste1111/estudio-luz-e-cor-identidade-agencia-flow-v2/index-v2.html',
    'webhook_url' => 'https://estudioluzecor-teste1111.rsmtrafegopago.online/estudioluzecor-teste1111/estudio-luz-e-cor-identidade-agencia-flow-v2/api/mercadopago-webhook.php',
    'database_path' => __DIR__ . '/orders.sqlite',
];
~~~

O bootstrap usa esse caminho absoluto por padrão. A pasta e o arquivo de configuração devem ter permissões restritas, ajustadas ao usuário PHP da hospedagem. O banco SQLite também deve permanecer fora de `public_html`. O arquivo `orders.sqlite` será criado pelo backend somente quando o primeiro acesso legítimo ao banco ocorrer; o endpoint de diagnóstico não o cria.

Não enviar a configuração, o banco, Access Token, segredo do Webhook ou token de diagnóstico ao GitHub, ao navegador ou ao chat.

## Proteções implementadas

1. Pagamentos ficam bloqueados enquanto `payments_enabled` não for exatamente `true`.
2. A API de destino é limitada a HTTPS em `api.mercadopago.com`.
3. O Webhook exige `data.id` na query e no corpo, com valores idênticos, além de `x-request-id` e `x-signature`; a assinatura HMAC-SHA256 é conferida com comparação em tempo constante.
4. O servidor consulta o pagamento diretamente na API oficial; não confia em comprovantes, prints, PDFs, redirecionamentos ou dados enviados pelo navegador.
5. A referência externa, a preferência e o pagamento precisam estar vinculados; se a API não fornecer um vínculo direto verificável, o evento é recusado.
6. O ambiente `live_mode` do pagamento precisa coincidir com `environment` configurado. Em produção, `seller_user_id` é obrigatório e `collector_id` precisa corresponder à conta vendedora configurada.
7. Valor é comparado em centavos inteiros e moeda deve ser BRL.
8. Eventos repetidos não reaplicam atualizações. Eventos antigos não rebaixam um pagamento aprovado, reembolsado ou contestado.
9. Não se aplica uma janela curta de idade ao timestamp da assinatura: o Mercado Pago pode reenviar Webhooks em tentativas posteriores; a autenticidade é verificada pela assinatura e a repetição é controlada por idempotência e data de atualização do pagamento.
10. O SQLite inclui migração da coluna de data de atualização do pagamento.
11. O endpoint de diagnóstico aceita somente GET, exige `X-Health-Token` e responde 404 sem token válido. A verificação é somente leitura e confere existência, permissões e isolamento do SQLite, sem abrir o banco para escrita.
12. Configuração privada e banco rejeitam caminhos dentro da raiz pública e links simbólicos para evitar exposição indireta.

## Limites desta etapa

- Nenhum pagamento está habilitado.
- O frontend V2 envia `package_id` e os códigos selecionados para `create-preference.php`. A interface só libera o checkout quando a quantidade de referências é exatamente a do pacote; o backend repete a validação no servidor. Com `payments_enabled` em `false`, a API responde sem criar preferência ou cobrança.
- Não há geração de imagens por IA nem entrega automática.
- A automação do GitHub passou em `php -l`, testes PHP de pacotes/referências/HMAC/SQLite/fraude/WhatsApp, `node --check` e testes estáticos do catálogo, imagens e integração do checkout.
- Ainda faltam teste visual em navegador, chamadas reais ao Mercado Pago Sandbox, simulação de Webhook pelo painel, teste de cancelamento/reembolso, envio real pela WhatsApp Business Platform e teste de permissões/execução na Hostinger. Nenhum desses testes externos foi executado.
- O tópico opcional `stop_delivery_op_wh` já é validado por assinatura e gravado em uma fila SQLite antes de responder 200. O worker `process-fraud-alerts.php` deve ser executado somente por CLI/cron. Para pagamento pendente, em processamento ou autorizado, ele tenta cancelar; para pagamento aprovado, só solicita reembolso integral quando `fraud_auto_refund_enabled` estiver exatamente `true`. Status desconhecidos, divergências ou reembolsos parciais vão para análise manual.
- **Não habilitar `stop_delivery_op_wh` ainda.** Primeiro é necessário instalar e testar a fila e o worker no sandbox, configurar cron confiável e confirmar o funcionamento. O Mercado Pago informa que esse tópico não segue o reenvio padrão; se a fila não persistir o evento, a notificação pode ser perdida.
- A notificação WhatsApp é enfileirada somente quando `whatsapp_notifications_enabled` está exatamente `true` e o destinatário/template estão válidos. O worker é CLI/cron; não é chamado pelo navegador. O token, o Phone Number ID e o destinatário ficam somente no arquivo privado.
- A mensagem usa um template `UTILITY` aprovado no WhatsApp Manager, em português (`pt_BR`), com três variáveis: referência do pedido, pacote e valor. O responsável precisa ter autorizado o recebimento. Fora da janela de atendimento, a plataforma exige template aprovado.
- Template sugerido: nome `elc_payment_approved`, idioma `pt_BR`, categoria `UTILITY`, corpo: `Pagamento aprovado! Pedido {{1}}. Pacote: {{2}}. Valor recebido: {{3}}. A confirmação foi verificada pelo sistema do Mercado Pago.` O template precisa ser aprovado antes do envio.
- O worker registra `sent`, `failed`, `retry` ou `uncertain`. Resultados de envio incertos não são reenviados automaticamente, para evitar mensagens duplicadas.
- As notificações do aplicativo Mercado Pago são independentes desta integração e precisam ser habilitadas nas configurações do próprio aplicativo. O backend não consegue ativá-las.
- Um pagamento adicional feito após uma aprovação exige reconciliação manual; esta etapa não automatiza reembolsos nem tratamento de disputas.

## Estado

Preparação de código apenas. Nenhum token, segredo, pedido real, preferência ou pagamento foi criado. Não houve deploy na Hostinger nem alteração no site.
