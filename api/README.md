# Estúdio Luz e Cor V2 — preparação Mercado Pago

Esta pasta contém somente a camada inicial de backend. Ela não altera o catálogo nem ativa pagamentos. O frontend atual ainda não foi conectado.

## Arquivos

- `api/bootstrap.php`: configuração privada, SQLite, validações e comunicação com a API Mercado Pago.
- `api/create-preference.php`: endpoint POST para criar uma preferência Checkout Pro, somente quando o servidor for explicitamente habilitado.
- `api/mercadopago-webhook.php`: valida a assinatura do Webhook, consulta o pagamento e confere o vínculo com a preferência salva.
- `api/health.php`: diagnóstico protegido por token privado; somente leitura, sem criar banco, tabelas, migrações ou arquivos.

## Endpoints

- `GET /api/health.php`: exige o cabeçalho HTTP `X-Health-Token`.
- `POST /api/create-preference.php`: recebe JSON como `{"package_id":"fotos-05","model_codes":["ELEG-01","ROM-02"]}`.
- `POST /api/mercadopago-webhook.php`: recebe notificações do Mercado Pago.

O Access Token nunca é enviado ao navegador. A URL de retorno do checkout não aprova pagamentos; a confirmação depende do Webhook validado e da consulta à API.

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
3. O Webhook usa o `data.id` da query string na validação HMAC SHA-256; em PHP, esse parâmetro pode aparecer normalizado como `data_id`.
4. O servidor consulta o pagamento e a preferência pelo ID salvo, conferindo a referência externa; quando a API informa o pedido comercial, confere também preferência e pagamento associados.
5. Valor é comparado em centavos inteiros e moeda deve ser BRL.
6. Eventos repetidos não reaplicam atualizações. Eventos antigos não rebaixam um pagamento aprovado, reembolsado ou contestado.
7. O SQLite inclui migração da coluna de data de atualização do pagamento.
8. O endpoint de diagnóstico exige `X-Health-Token`; sem token privado configurado responde 404. A verificação é somente leitura e apenas confere a existência e as permissões do arquivo SQLite, sem abrir o banco para escrita.

## Limites desta etapa

- Nenhum pagamento está habilitado.
- O frontend não chama `create-preference.php`.
- Não há geração de imagens por IA nem entrega automática.
- Ainda é necessário testar PHP, permissões, SQLite, Webhook de teste e fluxo completo no subdomínio antes de qualquer ativação.
- Um pagamento adicional feito após uma aprovação exige reconciliação manual; esta etapa não automatiza reembolsos nem tratamento de disputas.

## Estado

Preparação de código apenas. Nenhum token, segredo, pedido real, preferência ou pagamento foi criado. Não houve deploy na Hostinger nem alteração no site.
