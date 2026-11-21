# Instalação isolada na Hostinger — Estúdio Luz e Cor V2

Este guia é para a branch \`mp-checkout-backend-prep\`. Não publicar a branch inteira por cima do site existente.

## 1. Destino exato

Instalar os arquivos PHP somente nesta pasta do subdomínio de teste:

\`public_html/estudioluzecor-teste1111/estudioluzecor-teste1111/estudio-luz-e-cor-identidade-agencia-flow-v2/\`

Criar dentro dela a pasta \`api/\` e copiar apenas:

- \`api/bootstrap.php\`
- \`api/create-preference.php\`
- \`api/mercadopago-webhook.php\`
- \`api/health.php\`

O arquivo \`api/README.md\` é documentação para o repositório; não é necessário publicá-lo.

**Não copiar nem substituir** \`index-v2.html\`, \`script-v2.js\`, \`catalog-data-v2.js\`, CSS, imagens ou quaisquer outros arquivos atuais. A correção de WhatsApp feita diretamente na Hostinger deve ser preservada.

## 2. Diretório privado

Criar fora de \`public_html\`, na home da conta Hostinger:

\`/home/u643738877/estudio-luz-e-cor-v2-private/\`

Permissões recomendadas: diretório 0700 e arquivos privados 0600, ajustadas ao usuário PHP da hospedagem.

Não criar o banco nem arquivo de configuração dentro da pasta pública.

## 3. Configuração privada inicial

Criar \`mercadopago-config.php\` no diretório privado com esta estrutura, mantendo credenciais vazias nesta fase:

~~~php
<?php
return [
    'environment' => 'test',
    'access_token' => '',
    'webhook_secret' => '',
    'api_base_url' => 'https://api.mercadopago.com',
    'public_base_url' => 'https://estudioluzecor-teste1111.rsmtrafegopago.online/estudioluzecor-teste1111/estudio-luz-e-cor-identidade-agencia-flow-v2',
    'return_url' => 'https://estudioluzecor-teste1111.rsmtrafegopago.online/estudioluzecor-teste1111/estudio-luz-e-cor-identidade-agencia-flow-v2/index-v2.html',
    'webhook_url' => 'https://estudioluzecor-teste1111.rsmtrafegopago.online/estudioluzecor-teste1111/estudio-luz-e-cor-identidade-agencia-flow-v2/api/mercadopago-webhook.php',
    'database_path' => __DIR__ . '/orders.sqlite',
];
~~~

A configuração não deve ser commitada, enviada ao GitHub ou exposta ao navegador.

## 4. Primeira verificação

Abrir:

\`https://estudioluzecor-teste1111.rsmtrafegopago.online/estudioluzecor-teste1111/estudio-luz-e-cor-identidade-agencia-flow-v2/api/health.php\`

A resposta deve ser JSON. Nesta etapa, é esperado que \`private_config_installed\` seja \`true\`, mas \`payments_enabled\` deve continuar \`false\`. O endpoint de saúde não confirma a operação do banco; isso será validado em teste controlado.

Testar também que:

- GET em \`create-preference.php\` responde 405.
- POST sem configuração/credenciais válidas não cria cobrança e não expõe detalhes internos.
- GET em \`mercadopago-webhook.php\` responde 405.
- Nenhuma chave ou segredo aparece na resposta.

## 5. Não executar ainda

Até nova autorização explícita:

- não inserir Access Token ou segredo de Webhook;
- não criar preferências reais;
- não conectar o botão de compra do frontend;
- não habilitar pagamentos de produção;
- não alterar o JavaScript atual;
- não iniciar geração de imagens por IA;
- não publicar nada no domínio principal.

## 6. Relatório necessário

Depois da instalação, informar os caminhos exatos, permissões, resultados HTTP e sintaxe PHP. Não incluir valores de credenciais no relatório. Parar e aguardar autorização antes da próxima etapa.
