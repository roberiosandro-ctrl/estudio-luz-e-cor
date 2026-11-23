<?php
declare(strict_types=1);

/**
 * Camada do atendente inteligente do Estúdio Luz e Cor.
 * Segredos ficam exclusivamente no arquivo privado de configuração da Hostinger.
 */
require_once __DIR__ . '/bootstrap.php';

function elc_assistant_database(array $config): PDO
{
    $pdo = elc_database($config);
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS assistant_inbound (
            wamid TEXT PRIMARY KEY,
            wa_id TEXT NOT NULL,
            phone_number_id TEXT NOT NULL,
            message_type TEXT NOT NULL,
            message_text TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT "queued",
            attempts INTEGER NOT NULL DEFAULT 0,
            next_attempt_at INTEGER NOT NULL DEFAULT 0,
            locked_until INTEGER,
            last_error TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_assistant_inbound_queue ON assistant_inbound(status, next_attempt_at)');
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS assistant_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            wa_id TEXT NOT NULL,
            role TEXT NOT NULL,
            body TEXT NOT NULL,
            provider_message_id TEXT,
            created_at TEXT NOT NULL,
            UNIQUE(provider_message_id)
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_assistant_messages_conversation ON assistant_messages(wa_id, id)');
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS assistant_conversations (
            wa_id TEXT PRIMARY KEY,
            state_json TEXT NOT NULL DEFAULT "{}",
            last_inbound_at INTEGER,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );

    // Mantém o telefone do comprador junto ao pedido criado pelo atendente.
    $columns = $pdo->query('PRAGMA table_info(orders)')->fetchAll(PDO::FETCH_ASSOC);
    $hasCustomerPhone = false;
    foreach ($columns as $column) {
        if (($column['name'] ?? '') === 'customer_whatsapp') {
            $hasCustomerPhone = true;
            break;
        }
    }
    if (!$hasCustomerPhone) {
        $pdo->exec('ALTER TABLE orders ADD COLUMN customer_whatsapp TEXT');
    }

    return $pdo;
}

function elc_assistant_verify_signature(string $rawBody, string $signatureHeader, string $appSecret): bool
{
    if ($rawBody === '' || $signatureHeader === '' || $appSecret === ''
        || strlen($rawBody) > 262144
        || !preg_match('/^sha256=[a-fA-F0-9]{64}$/', $signatureHeader)) {
        return false;
    }
    $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $appSecret);
    return hash_equals($expected, strtolower($signatureHeader));
}

/** @return array<int,array{wamid:string,wa_id:string,phone_number_id:string,type:string,text:string}> */
function elc_assistant_extract_inbound_messages(array $payload): array
{
    $messages = [];
    foreach (($payload['entry'] ?? []) as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        foreach (($entry['changes'] ?? []) as $change) {
            if (!is_array($change)) {
                continue;
            }
            $value = $change['value'] ?? [];
            if (!is_array($value)) {
                continue;
            }
            $phoneNumberId = trim((string) ($value['metadata']['phone_number_id'] ?? ''));
            if ($phoneNumberId === '' || !preg_match('/^[0-9]+$/', $phoneNumberId)) {
                continue;
            }
            foreach (($value['messages'] ?? []) as $message) {
                if (!is_array($message)) {
                    continue;
                }
                $wamid = trim((string) ($message['id'] ?? ''));
                $waId = trim((string) ($message['from'] ?? ''));
                $type = trim((string) ($message['type'] ?? 'unknown'));
                if ($wamid === '' || strlen($wamid) > 200
                    || !preg_match('/^[1-9][0-9]{9,14}$/', $waId)) {
                    continue;
                }

                $text = '';
                if ($type === 'text') {
                    $text = trim((string) ($message['text']['body'] ?? ''));
                } elseif ($type === 'interactive') {
                    $interactive = $message['interactive'] ?? [];
                    $text = trim((string) (
                        $interactive['button_reply']['title']
                        ?? $interactive['list_reply']['title']
                        ?? ''
                    ));
                } elseif ($type === 'button') {
                    $text = trim((string) ($message['button']['text'] ?? ''));
                }

                if ($text === '') {
                    $text = '[O cliente enviou uma mensagem do tipo ' . preg_replace('/[^a-z_]/', '', $type) . '. Peça que descreva em texto o que precisa.]';
                }
                if (strlen($text) > 8000) {
                    $text = substr($text, 0, 8000);
                }

                $messages[] = [
                    'wamid' => $wamid,
                    'wa_id' => $waId,
                    'phone_number_id' => $phoneNumberId,
                    'type' => $type,
                    'text' => $text,
                ];
            }
        }
    }
    return $messages;
}

/** @return array<int,array{code:string,title:string,description:string}> */
function elc_assistant_catalog(): array
{
    $path = dirname(__DIR__) . '/catalog-data-v2.js';
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('Catálogo do Estúdio Luz e Cor não encontrado.');
    }
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Não foi possível ler o catálogo.');
    }
    preg_match_all(
        "/\\{\\s*code:\\s*'([A-Z]+-[0-9]{2})',\\s*title:\\s*'([^']*)',\\s*description:\\s*'([^']*)'/",
        $source,
        $matches,
        PREG_SET_ORDER
    );
    $catalog = [];
    foreach ($matches as $match) {
        $catalog[] = [
            'code' => $match[1],
            'title' => $match[2],
            'description' => $match[3],
        ];
    }
    if (count($catalog) !== 60) {
        throw new RuntimeException('O catálogo precisa conter exatamente 60 referências válidas.');
    }
    return $catalog;
}

function elc_assistant_system_instructions(): string
{
    $packages = [];
    foreach (elc_packages() as $id => $package) {
        $packages[] = $id . ': ' . $package['quantity'] . ' fotos por ' . elc_format_brl_cents((int) $package['amount_cents']);
    }
    $models = [];
    foreach (elc_assistant_catalog() as $model) {
        $models[] = $model['code'] . ' — ' . $model['title'] . ': ' . $model['description'];
    }

    return implode("\n", [
        'Você é o atendente virtual de vendas do Estúdio Luz e Cor, em português brasileiro.',
        'Seu trabalho é conversar com naturalidade, entender o que a pessoa procura, explicar os ensaios fotográficos com inteligência artificial, orientar a escolha de pacote e referências e conduzir a compra sem pressionar.',
        'Seja cordial, claro e objetivo. Faça uma pergunta por vez. Não use linguagem robótica nem respostas longas desnecessárias.',
        'INFORMAÇÕES CONFIRMADAS:',
        'O Estúdio oferece fotos digitais personalizadas com inteligência artificial. O cliente escolhe um pacote e exatamente a mesma quantidade de referências do catálogo. É permitido misturar referências de coleções diferentes.',
        'Pacotes: ' . implode('; ', $packages) . '.',
        'As imagens do catálogo são referências demonstrativas. Os códigos abaixo são as opções disponíveis:',
        implode("\n", $models),
        'REGRAS OBRIGATÓRIAS:',
        'Nunca invente prazo de entrega, política de reembolso, garantias, formas de pagamento, características técnicas, promoções ou serviços não descritos aqui.',
        'Nunca diga que um pagamento foi aprovado. A confirmação depende do sistema e do retorno oficial do Mercado Pago.',
        'Nunca peça senha, código SMS, token, dados completos de cartão ou documentos pelo WhatsApp.',
        'Só inclua um código em model_codes quando o cliente tiver escolhido claramente aquela referência pelo código ou pelo nome. Nunca escolha modelos pelo cliente.',
        'package_id deve ser preenchido somente quando o cliente tiver escolhido claramente um pacote. Se ainda estiver decidindo, use null.',
        'checkout_intent deve ser true somente quando o cliente pedir explicitamente para pagar, comprar agora ou receber o link, e já houver pacote e a quantidade exata de referências selecionadas. Caso contrário, false.',
        'Quando faltarem referências, pergunte quais códigos ou nomes o cliente deseja. A quantidade precisa corresponder exatamente ao pacote.',
        'Se o cliente perguntar como enviar fotos pessoais, explique apenas que, depois de alinhar o pedido, o estúdio orienta o envio pelo canal combinado. Não prometa que o robô processa imagens recebidas no WhatsApp.',
        'Se não souber algo, diga com transparência e ofereça encaminhar a dúvida ao estúdio, sem afirmar que alguém já foi notificado.',
        'Responda com uma única mensagem curta e útil. Não inclua JSON visível; os campos estruturados serão processados pelo sistema.',
    ]);
}

/** @param array<int,array{role:string,body:string}> $history */
function elc_assistant_request_ai(array $config, array $history): array
{
    $apiKey = trim((string) ($config['openai_api_key'] ?? ''));
    $model = trim((string) ($config['openai_model'] ?? 'gpt-6.2-astra'));
    if ($apiKey === '' || !preg_match('/^[a-zA-Z0-9._-]{2,100}$/', $model)) {
        throw new RuntimeException('A chave ou o modelo da inteligência artificial não está configurado.', 400);
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('A extensão cURL não está disponível.', 500);
    }

    $input = [];
    foreach ($history as $item) {
        if (!in_array($item['role'] ?? '', ['user', 'assistant'], true)) {
            continue;
        }
        $body = trim((string) ($item['body'] ?? ''));
        if ($body !== '') {
            $input[] = ['role' => $item['role'], 'content' => $body];
        }
    }
    if ($input === []) {
        throw new RuntimeException('Histórico da conversa vazio.', 400);
    }

    $packageIds = array_keys(elc_packages());
    $modelCodes = elc_allowed_model_codes();
    $payload = [
        'model' => $model,
        'instructions' => elc_assistant_system_instructions(),
        'input' => $input,
        'store' => false,
        'max_output_tokens' => 700,
        'text' => [
            'format' => [
                'type' => 'json_schema',
                'name' => 'elc_sales_reply',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'reply' => ['type' => 'string'],
                        'package_id' => [
                            'type' => ['string', 'null'],
                            'enum' => array_merge($packageIds, [null]),
                        ],
                        'model_codes' => [
                            'type' => 'array',
                            'items' => ['type' => 'string', 'enum' => $modelCodes],
                            'maxItems' => 20,
                        ],
                        'checkout_intent' => ['type' => 'boolean'],
                    ],
                    'required' => ['reply', 'package_id', 'model_codes', 'checkout_intent'],
                ],
            ],
        ],
    ];
    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $curl = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $encoded,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 35,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $body = curl_exec($curl);
    $curlError = curl_errno($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($body === false) {
        throw new RuntimeException('Falha de comunicação com a inteligência artificial.', $curlError ?: 503);
    }
    $decoded = json_decode((string) $body, true);
    if ($status === 429 || $status >= 500) {
        throw new RuntimeException('A inteligência artificial está temporariamente indisponível.', $status);
    }
    if ($status < 200 || $status >= 300 || !is_array($decoded)) {
        throw new RuntimeException('A inteligência artificial recusou a solicitação. Confira a configuração da API.', $status ?: 500);
    }

    $outputText = '';
    foreach (($decoded['output'] ?? []) as $outputItem) {
        if (($outputItem['type'] ?? '') !== 'message') {
            continue;
        }
        foreach (($outputItem['content'] ?? []) as $part) {
            if (($part['type'] ?? '') === 'output_text' && is_string($part['text'] ?? null)) {
                $outputText .= $part['text'];
            }
        }
    }
    $result = json_decode($outputText, true);
    if (!is_array($result)
        || !is_string($result['reply'] ?? null)
        || trim($result['reply']) === ''
        || !is_bool($result['checkout_intent'] ?? null)
        || !is_array($result['model_codes'] ?? null)) {
        throw new RuntimeException('A resposta da inteligência artificial veio em formato inválido.', 502);
    }

    $packageId = $result['package_id'] ?? null;
    if ($packageId !== null && !isset(elc_packages()[$packageId])) {
        $packageId = null;
    }
    $codes = elc_normalize_model_codes($result['model_codes']);
    if ($codes === null) {
        $codes = [];
    }
    return [
        'reply' => trim($result['reply']),
        'package_id' => $packageId,
        'model_codes' => $codes,
        'checkout_intent' => $result['checkout_intent'],
    ];
}

function elc_assistant_send_text(array $config, string $recipient, string $text, ?string $contextMessageId = null): string
{
    $apiVersion = trim((string) ($config['whatsapp_graph_api_version'] ?? 'v27.0'));
    $phoneNumberId = trim((string) ($config['whatsapp_phone_number_id'] ?? ''));
    $accessToken = trim((string) ($config['whatsapp_access_token'] ?? ''));
    if (!preg_match('/^v[0-9]+\\.[0-9]+$/', $apiVersion)
        || !preg_match('/^[0-9]+$/', $phoneNumberId)
        || $accessToken === ''
        || !preg_match('/^[1-9][0-9]{9,14}$/', $recipient)) {
        throw new RuntimeException('Credenciais ou destinatário da WhatsApp Cloud API inválidos.', 400);
    }

    $message = [
        'messaging_product' => 'whatsapp',
        'recipient_type' => 'individual',
        'to' => $recipient,
        'type' => 'text',
        'text' => ['preview_url' => false, 'body' => function_exists('mb_substr') ? mb_substr($text, 0, 4000, 'UTF-8') : substr($text, 0, 4000)],
    ];
    if ($contextMessageId !== null && $contextMessageId !== '') {
        $message['context'] = ['message_id' => $contextMessageId];
    }
    $curl = curl_init('https://graph.facebook.com/' . $apiVersion . '/' . rawurlencode($phoneNumberId) . '/messages');
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $body = curl_exec($curl);
    $curlError = curl_errno($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($body === false) {
        throw new RuntimeException('Envio WhatsApp com resultado incerto; não reenviar automaticamente.', $curlError ?: 503);
    }
    $decoded = json_decode((string) $body, true);
    if ($status === 429) {
        throw new RuntimeException('Limite temporário da WhatsApp Cloud API.', 429);
    }
    if ($status >= 500) {
        throw new RuntimeException('Erro da WhatsApp Cloud API; resultado do envio incerto.', $status);
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('A WhatsApp Cloud API rejeitou a mensagem.', $status ?: 400);
    }
    $messageId = is_array($decoded) ? (string) ($decoded['messages'][0]['id'] ?? '') : '';
    if ($messageId === '' || strlen($messageId) > 200) {
        throw new RuntimeException('A Meta não confirmou o identificador da mensagem.', 503);
    }
    return $messageId;
}

/**
 * Cria ou recupera um checkout do pedido do cliente.
 * O identificador do pedido e a chave de idempotência derivam do wamid recebido.
 */
function elc_assistant_create_checkout(
    array $config,
    PDO $pdo,
    string $wamid,
    string $customerPhone,
    string $packageId,
    array $modelCodes
): array {
    if (($config['payments_enabled'] ?? false) !== true) {
        throw new RuntimeException('O checkout automático ainda está desativado.', 409);
    }
    if (trim((string) ($config['access_token'] ?? '')) === '') {
        throw new RuntimeException('Credencial do Mercado Pago não configurada.', 409);
    }
    $package = elc_packages()[$packageId] ?? null;
    $normalizedCodes = elc_normalize_model_codes($modelCodes);
    if ($package === null || $normalizedCodes === null || count($normalizedCodes) !== (int) $package['quantity']) {
        throw new InvalidArgumentException('O pacote exige a quantidade exata de referências selecionadas.');
    }

    $orderId = substr(hash('sha256', 'elc-assistant-order|' . $wamid), 0, 32);
    $externalReference = 'ELC-' . strtoupper(substr(hash('sha256', 'elc-assistant-ref|' . $wamid), 0, 24));
    $find = $pdo->prepare('SELECT * FROM orders WHERE external_reference = :reference LIMIT 1');
    $find->execute([':reference' => $externalReference]);
    $existing = $find->fetch();
    if (is_array($existing) && trim((string) ($existing['checkout_url'] ?? '')) !== '') {
        return ['checkout_url' => (string) $existing['checkout_url'], 'order_reference' => $externalReference];
    }

    if (!is_array($existing)) {
        $now = gmdate('c');
        $insert = $pdo->prepare(
            'INSERT INTO orders
             (id, external_reference, package_id, package_name, amount_cents, currency, selected_model_codes, status, customer_whatsapp, created_at, updated_at)
             VALUES (:id, :external_reference, :package_id, :package_name, :amount_cents, "BRL", :models, "creating_preference", :customer_whatsapp, :created_at, :updated_at)'
        );
        $insert->execute([
            ':id' => $orderId,
            ':external_reference' => $externalReference,
            ':package_id' => $packageId,
            ':package_name' => $package['name'],
            ':amount_cents' => $package['amount_cents'],
            ':models' => json_encode($normalizedCodes, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ':customer_whatsapp' => $customerPhone,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    } else {
        if ((string) $existing['package_id'] !== $packageId
            || (string) $existing['selected_model_codes'] !== json_encode($normalizedCodes, JSON_UNESCAPED_UNICODE)) {
            throw new RuntimeException('Já existe um pedido diferente vinculado a esta mensagem.', 409);
        }
    }

    $publicBaseUrl = rtrim((string) ($config['public_base_url'] ?? ''), '/');
    $returnUrl = trim((string) ($config['return_url'] ?? ''));
    $webhookUrl = trim((string) ($config['webhook_url'] ?? ''));
    if ($webhookUrl === '') {
        $webhookUrl = $publicBaseUrl . '/api/mercadopago-webhook.php';
    }
    if (strpos($publicBaseUrl, 'https://') !== 0 || strpos($returnUrl, 'https://') !== 0 || strpos($webhookUrl, 'https://') !== 0) {
        throw new RuntimeException('URLs HTTPS do checkout ainda não estão configuradas.', 409);
    }
    $returnWithStatus = static function (string $status) use ($returnUrl, $externalReference): string {
        $separator = strpos($returnUrl, '?') === false ? '?' : '&';
        return $returnUrl . $separator . http_build_query([
            'mp_return' => $status,
            'external_reference' => $externalReference,
        ]);
    };

    $preference = elc_mp_request($config, 'POST', '/checkout/preferences', [
        'items' => [[
            'id' => $packageId,
            'title' => 'Estúdio Luz e Cor — ' . $package['name'],
            'description' => $package['quantity'] . ' fotos digitais personalizadas',
            'quantity' => 1,
            'currency_id' => 'BRL',
            'unit_price' => $package['amount_cents'] / 100,
        ]],
        'external_reference' => $externalReference,
        'notification_url' => $webhookUrl,
        'back_urls' => [
            'success' => $returnWithStatus('success'),
            'pending' => $returnWithStatus('pending'),
            'failure' => $returnWithStatus('failure'),
        ],
        'auto_return' => 'approved',
        'metadata' => [
            'order_id' => $orderId,
            'package_id' => $packageId,
            'model_codes' => $normalizedCodes,
            'customer_whatsapp' => $customerPhone,
        ],
    ], $orderId);

    $environment = (string) ($config['environment'] ?? 'test');
    $checkoutUrl = $environment === 'production'
        ? (string) ($preference['init_point'] ?? '')
        : (string) ($preference['sandbox_init_point'] ?? '');
    if ($checkoutUrl === '' || empty($preference['id'])) {
        throw new RuntimeException('O Mercado Pago não retornou um link de checkout válido.', 502);
    }

    $update = $pdo->prepare(
        'UPDATE orders
         SET preference_id = :preference_id, checkout_url = :checkout_url, status = "pending", updated_at = :updated_at
         WHERE external_reference = :reference'
    );
    $update->execute([
        ':preference_id' => (string) $preference['id'],
        ':checkout_url' => $checkoutUrl,
        ':updated_at' => gmdate('c'),
        ':reference' => $externalReference,
    ]);

    return ['checkout_url' => $checkoutUrl, 'order_reference' => $externalReference];
}
