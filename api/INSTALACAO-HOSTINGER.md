# Instalação isolada na Hostinger — Estúdio Luz e Cor V2

Este guia se refere exclusivamente à branch `mp-checkout-backend-prep`. Não publicar a branch inteira por cima do site existente.

## 1. Destino exato

Manter o frontend existente nesta pasta do subdomínio de teste:

`public_html/estudioluzecor-teste1111/estudioluzecor-teste1111/estudio-luz-e-cor-identidade-agencia-flow-v2/`

A API fica em uma pasta irmã do frontend:

`public_html/estudioluzecor-teste1111/estudioluzecor-teste1111/api/`

Copiar os arquivos PHP somente para essa pasta `api/`:

- `api/bootstrap.php`
- `api/create-preference.php`
- `api/mercadopago-webhook.php`
- `api/health.php`
- `api/process-fraud-alerts.php` (executar exclusivamente por PHP CLI/cron, nunca via navegador)
- `api/process-whatsapp-notifications.php` (executar exclusivamente por PHP CLI/cron, nunca via navegador)

Não copiar documentação. **Não copiar nem substituir** `index-v2.html`, `script-v2.js`, `catalog-data-v2.js`, CSS, imagens ou quaisquer outros arquivos atuais. A correção de WhatsApp feita diretamente na Hostinger deve ser preservada.

Observação: como o frontend fica dentro de `estudio-luz-e-cor-identidade-agencia-flow-v2/` e a API fica na pasta irmã `api/`, o JavaScript deve chamar `../api/create-preference.php`. Não usar `api/create-preference.php`, pois esse caminho procuraria uma pasta API dentro do frontend.

## 2. Diretório privado

A pasta privada já confirmada para este domínio fica ao lado de `public_html`:

`/home/u643738877/domains/rsmtrafegopago.online/private/`

Manter a configuração e o banco SQLite nessa pasta, nunca na pasta pública. Usar permissões restritas, ajustadas ao usuário PHP da hospedagem. Não criar uma segunda pasta privada com caminho alternativo.

## 3. Configuração privada inicial

Criar `mercadopago-config.php` no diretório privado com esta estrutura. Nesta fase, manter pagamentos desativados e credenciais vazias:

~~~php
<?php
declare(strict_types=1);

return [
    'environment' => 'test',
    'payments_enabled' => false,
    'access_token' => '',
    'webhook_secret' => '',
    'seller_user_id' => '', // preencher somente quando houver credencial/conta de teste confirmada
    'fraud_auto_refund_enabled' => false, // não ativar antes dos testes sandbox
    'whatsapp_notifications_enabled' => false,
    'whatsapp_graph_api_version' => 'v27.0',
    'whatsapp_phone_number_id' => '',
    'whatsapp_access_token' => '',
    'whatsapp_recipient' => '', // confirmar o número do responsável antes de preencher
    'whatsapp_template_name' => 'elc_payment_approved',
    'whatsapp_template_language' => 'pt_BR',
    'health_token' => '', // gerar um valor longo e aleatório, somente no servidor
    'api_base_url' => 'https://api.mercadopago.com',
    'public_base_url' => 'https://estudioluzecor-teste1111.rsmtrafegopago.online/estudioluzecor-teste1111',
    'return_url' => 'https://estudioluzecor-teste1111.rsmtrafegopago.online/estudioluzecor-teste1111/estudio-luz-e-cor-identidade-agencia-flow-v2/index-v2.html',
    'webhook_url' => 'https://estudioluzecor-teste1111.rsmtrafegopago.online/estudioluzecor-teste1111/api/mercadopago-webhook.php',
    'database_path' => __DIR__ . '/orders.sqlite',
];
~~~

O bootstrap procura esse caminho absoluto por padrão. Não colar credenciais no chat ou no GitHub.

## 4. Verificação protegida

O endpoint `api/health.php` não é público. Ele exige o token configurado em `health_token`, enviado no cabeçalho HTTP `X-Health-Token`. Sem esse cabeçalho correto, responde 404.

Com o token configurado, consultar a URL de health por uma ferramenta HTTP que permita enviar o cabeçalho. A resposta deve ser JSON. Nesta fase, `payments_enabled` deve continuar `false`.

Testar também:

- GET em `create-preference.php` responde 405.
- GET em `health.php` sem `X-Health-Token` responde 404.
- GET em `mercadopago-webhook.php` responde 405.
- Nenhuma chave ou segredo aparece em respostas HTTP.
- Nenhuma preferência ou cobrança é criada enquanto `payments_enabled` estiver `false`.

## 5. Não executar ainda

Até nova autorização explícita:

- não inserir Access Token nem segredo do Webhook;
- não criar preferências ou cobranças;
- manter o botão de compra conectado ao endpoint `../api/create-preference.php`; com `payments_enabled = false`, a API deve recusar a criação de preferência;
- não habilitar pagamentos de produção;
- não substituir o JavaScript atual por versões antigas;
- não iniciar geração de imagens por IA;
- não publicar nada no domínio principal.

## 6. Relatório

Depois da instalação, informar caminhos, permissões, resultados HTTP e sintaxe PHP. Não incluir valores de credenciais no relatório. Parar e aguardar autorização antes da próxima etapa.
