# Estúdio Luz e Cor V2 — preparação Mercado Pago

Esta pasta contém apenas a camada inicial de backend. Ela não altera o catálogo nem ativa pagamentos. O frontend atual não foi conectado nesta etapa.

## Arquivos

- api/bootstrap.php: funções comuns, validação da configuração privada, SQLite, lista confiável de pacotes/modelos e cliente HTTP do Mercado Pago.
- api/create-preference.php: endpoint POST para criar uma preferência Checkout Pro depois de validar pacote e referências no servidor.
- api/mercadopago-webhook.php: endpoint POST para validar a assinatura do Webhook, consultar o pagamento pela API e atualizar o pedido de forma idempotente.
- api/health.php: verificação de requisitos protegida por token privado; não responde a consultas públicas.

## Endpoints previstos

- GET /api/health.php (somente com o cabeçalho privado X-Health-Token)
- POST /api/create-preference.php com JSON {"package_id":"fotos-05","model_codes":["ELEG-01","ROM-02"]}
- POST /api/mercadopago-webhook.php (chamado pelo Mercado Pago)

O endpoint de criação responde com checkout_url somente depois que o Mercado Pago devolver uma preferência válida. O Access Token nunca é enviado ao navegador.

## Pacotes validados no servidor

| ID interno | Pacote | Valor |
|---|---:|---:|
| fotos-02 | 2 fotos | R$ 29,90 |
| fotos-05 | 5 fotos | R$ 54,90 |
| fotos-10 | 10 fotos | R$ 89,90 |
| fotos-15 | 15 fotos | R$ 109,90 |
| fotos-20 | 20 fotos | R$ 129,90 |

Os 60 códigos de referência são aceitos em uma lista fechada: ELEG, PROF, ROM, LUX, ANIV, GEST, CAS, FASH, FAM e FANT, cada coleção de 01 a 06.

## Configuração privada — não versionar

Criar manualmente, somente no servidor Hostinger, uma pasta privada fora de public_html:

/home/CONTA_HOSTINGER/estudio-luz-e-cor-v2-private/

Dentro dela, criar mercadopago-config.php com o seguinte formato (preencher somente no servidor, quando houver autorização):

~~~php
<?php
return [
    'environment' => 'test', // usar 'production' somente após homologação e autorização
    'access_token' => '',
    'webhook_secret' => '',
    'api_base_url' => 'https://api.mercadopago.com',
    'public_base_url' => 'https://estudioluzecor-teste1111.rsmtrafegopago.online/estudioluzecor-teste1111/estudio-luz-e-cor-identidade-agencia-flow-v2',
    'return_url' => 'https://estudioluzecor-teste1111.rsmtrafegopago.online/estudioluzecor-teste1111/estudio-luz-e-cor-identidade-agencia-flow-v2/index-v2.html',
    'webhook_url' => '', // vazio para usar public_base_url + /api/mercadopago-webhook.php
    'health_token' => '', // gerar uma senha longa e aleatória, somente no servidor
    'database_path' => __DIR__ . '/orders.sqlite',
];
~~~

Por padrão, o bootstrap procura esse arquivo cinco níveis acima da pasta api, na home da conta Hostinger. Se o caminho real for diferente, definir ELC_MP_CONFIG_PATH no ambiente do PHP. A pasta privada deve ficar fora do document root e ter permissões restritas (diretório 0700 e arquivo 0600, ajustando conforme o usuário PHP da hospedagem).

O arquivo de configuração, o banco SQLite e qualquer segredo não podem ser enviados ao GitHub nem colocados dentro de public_html.

## Segurança e pendências antes de produção

1. O ambiente inicial é test; credenciais ainda não foram configuradas.
2. O Webhook valida x-signature usando o data.id da query string, consulta o pagamento e confirma a preferência vinculada antes de atualizar o pedido.\n3. Eventos repetidos não reaplicam atualizações; estados antigos não rebaixam um pagamento aprovado, reembolsado ou contestado.
3. O status aprovado só deve ser considerado válido quando referência, valor e moeda conferirem com o pedido salvo.
4. A confirmação do navegador/URL de retorno não aprova pagamento.
5. O frontend ainda não chama create-preference.php; a ligação do botão é uma etapa separada e requer backup e autorização.
6. O fluxo de upload de fotos e geração de imagens por IA não está incluído nesta etapa.
7. Antes de produção, validar o caminho privado real, a entrega do Webhook, o comportamento de reenvios, os estados de pagamento e os requisitos de segurança/rate limiting da hospedagem.

O endpoint health.php exige o cabeçalho HTTP X-Health-Token. Sem um health_token longo e aleatório configurado no arquivo privado, responde 404. Não exponha esse token no navegador nem o envie ao GitHub.\n\n## Estado desta entrega

Preparação de código apenas. Nenhum token, segredo, pedido real, preferência ou pagamento foi criado. Não houve deploy na Hostinger.
