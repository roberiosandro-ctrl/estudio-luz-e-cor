# Atendente inteligente do Estúdio Luz e Cor — preparação

Este módulo adiciona um atendente de vendas com IA à integração WhatsApp Cloud API. Ele foi preparado na branch `ai-sales-assistant-prep`; não está instalado na Hostinger, não está ativo e não envia mensagens.

## O que o atendente fará

- Receber mensagens do WhatsApp por Webhook.
- Responder em português brasileiro com linguagem natural usando a OpenAI Responses API.
- Consultar os cinco pacotes e as 60 referências diretamente do catálogo atual do projeto.
- Ajudar o cliente a escolher pacote e referências, sem inventar preços, prazos ou políticas.
- Quando o cliente pedir explicitamente o link, validar a quantidade de referências e, somente se `payments_enabled` estiver habilitado, criar/recuperar um checkout do Mercado Pago com chave de idempotência.
- Guardar o histórico recente da conversa no SQLite privado.

## Arquivos adicionados

- `api/assistant-bootstrap.php`: schema das conversas, validação do webhook Meta, catálogo, chamada à IA, envio de mensagens e criação protegida de checkout.
- `api/assistant-webhook.php`: verificação GET do webhook e recepção POST autenticada por `X-Hub-Signature-256`.
- `api/process-assistant-queue.php`: worker exclusivamente CLI que processa a fila, chama a IA e responde ao cliente.
- `tests/assistant-smoke.php`: testes de assinatura, extração de mensagens, catálogo e pacotes.

## Configuração privada necessária

Não colocar valores reais no GitHub, em commits, no frontend ou no chat. Acrescentar ao arquivo privado já existente:

`/home/u643738877/domains/rsmtrafegopago.online/private/mercadopago-config.php`

Exemplo de chaves (valores vazios/desativados até a configuração aprovada):

~~~php
'whatsapp_assistant_enabled' => false,
'whatsapp_app_secret' => '',
'whatsapp_webhook_verify_token' => '',
'whatsapp_graph_api_version' => 'v27.0',
'whatsapp_phone_number_id' => '',
'whatsapp_access_token' => '',
'openai_api_key' => '',
'openai_model' => 'gpt-6.2-astra',
~~~

A configuração existente deve ser preservada. Não substituir o arquivo privado inteiro por esse fragmento. O campo `payments_enabled` continua `false` até os testes de Mercado Pago serem concluídos. O campo `whatsapp_notifications_enabled` é independente e não deve ser alterado por este módulo.

### Onde obter os segredos

- `whatsapp_app_secret`: App Secret do aplicativo Estúdio Luz e Cor no painel Meta Developers.
- `whatsapp_webhook_verify_token`: segredo aleatório criado pelo responsável, diferente do App Secret e do Access Token.
- `whatsapp_phone_number_id`: Phone Number ID do número que será registrado na Cloud API; não é o número de telefone visível.
- `whatsapp_access_token`: token de acesso da Meta com permissões necessárias para mensagens.
- `openai_api_key`: chave de API da plataforma OpenAI. A assinatura do ChatGPT não inclui automaticamente créditos de API; o uso da API é cobrado separadamente conforme modelo e consumo.

Não enviar nenhum desses valores por mensagem ou commit.

## Webhook da Meta

Depois de instalar os arquivos na pasta de API do subdomínio de teste, configurar no painel Meta:

- Callback URL: `https://SEU-DOMINIO-DO-PROJETO/api/assistant-webhook.php`
- Verify Token: o mesmo valor privado de `whatsapp_webhook_verify_token`.
- Assinar o campo `messages` do objeto `whatsapp_business_account`.

O endpoint GET valida o token de verificação e devolve o challenge. O POST valida o HMAC-SHA256 do corpo bruto usando o App Secret. Eventos duplicados são descartados pelo identificador `wamid`. Eventos de status sem mensagem são confirmados sem entrar na fila.

## Worker / Cron

O Webhook persiste a mensagem e responde rapidamente. Um cron deve executar o worker a cada minuto, por exemplo:

~~~sh
/usr/bin/php /CAMINHO-ABSOLUTO-DO-PROJETO/api/process-assistant-queue.php
~~~

O caminho absoluto real e o binário PHP devem ser confirmados no hPanel antes de cadastrar o cron. O worker recusa execução via navegador. Não cadastrar cron até a instalação e a revisão de segurança serem autorizadas.

## Limites da primeira versão

- Processa texto, respostas de botão e respostas de lista. Para áudio, imagem, vídeo e documentos, pede ao cliente que descreva o pedido em texto; não baixa nem interpreta mídia nesta primeira versão.
- O atendente usa as 18 mensagens mais recentes para contexto. O armazenamento fica no SQLite privado; aplicar política de retenção e aviso de privacidade antes da operação comercial.
- A resposta é enviada como mensagem livre em resposta ao contato recebido. O worker deve rodar rapidamente para manter a conversa dentro da janela de atendimento da Meta. Mensagens fora da janela de atendimento podem exigir template aprovado.
- A IA não confirma pagamento. A confirmação permanece responsabilidade do Webhook oficial do Mercado Pago.
- A primeira versão cria link de checkout somente quando o cliente pede explicitamente para pagar, escolheu pacote e selecionou exatamente a quantidade correspondente de referências.
- O robô não afirma que uma pessoa foi notificada nem promete prazo de entrega, reembolso ou políticas não documentadas.
- O fluxo de confirmação automática ao comprador após o Mercado Pago aprovar, envio de mídia, painel humano e handoff com notificação ao responsável são etapas futuras.

## Segurança e estado

- `whatsapp_assistant_enabled` deve permanecer `false` até webhook, credenciais e cron serem configurados e testados.
- `payments_enabled` continua `false`; nenhum checkout é criado enquanto estiver desativado.
- Nenhuma chave ou token é versionado.
- O worker não reenvia automaticamente quando o resultado de envio da Meta é incerto, para evitar mensagens duplicadas.
- Nenhum deploy, alteração na Hostinger, alteração de DNS, registro de número ou ativação de pagamento foi feito.
