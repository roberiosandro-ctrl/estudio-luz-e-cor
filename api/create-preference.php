<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
elc_require_post();

try {
    $config = elc_load_config();
    if (($config['payments_enabled'] ?? false) !== true) {
        elc_json_response(503, [
            'ok' => false,
            'message' => 'Os pagamentos ainda estão desativados para este projeto.',
        ]);
    }
    if (trim((string) ($config['access_token'] ?? '')) === '') {
        throw new RuntimeException('Credencial privada do Mercado Pago ainda não configurada.');
    }
    $pdo = elc_database($config);
    elc_rate_limit($pdo);

    $input = elc_read_json_body();
    $packageId = isset($input['package_id']) && is_string($input['package_id']) ? $input['package_id'] : '';
    $package = elc_packages()[$packageId] ?? null;
    if ($package === null) {
        elc_json_response(422, ['ok' => false, 'message' => 'Pacote inválido.']);
    }

    $modelCodes = elc_normalize_model_codes($input['model_codes'] ?? null);
    if ($modelCodes === null) {
        elc_json_response(422, ['ok' => false, 'message' => 'Selecione referências válidas para o pacote escolhido.']);
    }
    if (count($modelCodes) !== (int) $package['quantity']) {
        elc_json_response(422, [
            'ok' => false,
            'message' => 'Este pacote exige exatamente ' . (int) $package['quantity'] . ' referências diferentes. Você selecionou ' . count($modelCodes) . '.',
        ]);
    }

    $now = gmdate('c');
    $orderId = bin2hex(random_bytes(16));
    $externalReference = 'ELC-' . strtoupper(bin2hex(random_bytes(12)));
    $amount = $package['amount_cents'] / 100;

    $insert = $pdo->prepare(
        'INSERT INTO orders (id, external_reference, package_id, package_name, amount_cents, currency, selected_model_codes, status, created_at, updated_at)
         VALUES (:id, :external_reference, :package_id, :package_name, :amount_cents, "BRL", :models, "creating_preference", :created_at, :updated_at)'
    );
    $insert->execute([
        ':id' => $orderId,
        ':external_reference' => $externalReference,
        ':package_id' => $packageId,
        ':package_name' => $package['name'],
        ':amount_cents' => $package['amount_cents'],
        ':models' => json_encode($modelCodes, JSON_UNESCAPED_UNICODE),
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    $publicBaseUrl = rtrim((string) ($config['public_base_url'] ?? ''), '/');
    $returnUrl = trim((string) ($config['return_url'] ?? ''));
    if ($publicBaseUrl === '' || $returnUrl === '' || strpos($publicBaseUrl, 'https://') !== 0 || strpos($returnUrl, 'https://') !== 0) {
        throw new RuntimeException('URLs HTTPS do projeto ainda não foram configuradas.');
    }

    $returnWithStatus = static function (string $status) use ($returnUrl, $externalReference): string {
        $separator = strpos($returnUrl, '?') === false ? '?' : '&';
        return $returnUrl . $separator . http_build_query([
            'mp_return' => $status,
            'external_reference' => $externalReference,
        ]);
    };

    $webhookUrl = trim((string) ($config['webhook_url'] ?? ''));
    if ($webhookUrl === '') {
        $webhookUrl = $publicBaseUrl . '/api/mercadopago-webhook.php';
    }
    if (strpos($webhookUrl, 'https://') !== 0) {
        throw new RuntimeException('A URL do Webhook precisa utilizar HTTPS.');
    }

    $preference = elc_mp_request($config, 'POST', '/checkout/preferences', [
        'items' => [[
            'id' => $packageId,
            'title' => 'Estúdio Luz e Cor — ' . $package['name'],
            'description' => $package['quantity'] . ' fotos digitais personalizadas',
            'quantity' => 1,
            'currency_id' => 'BRL',
            'unit_price' => $amount,
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
            'model_codes' => $modelCodes,
        ],
    ], bin2hex(random_bytes(16)));

    $environment = (string) ($config['environment'] ?? 'test');
    $checkoutUrl = $environment === 'production'
        ? (string) ($preference['init_point'] ?? '')
        : (string) ($preference['sandbox_init_point'] ?? '');
    if ($checkoutUrl === '' || empty($preference['id'])) {
        throw new RuntimeException('O Mercado Pago não retornou um checkout válido para este ambiente.');
    }

    $update = $pdo->prepare(
        'UPDATE orders SET preference_id = :preference_id, checkout_url = :checkout_url, status = "pending", updated_at = :updated_at WHERE id = :id'
    );
    $update->execute([
        ':preference_id' => (string) $preference['id'],
        ':checkout_url' => $checkoutUrl,
        ':updated_at' => gmdate('c'),
        ':id' => $orderId,
    ]);

    elc_json_response(201, [
        'ok' => true,
        'order_reference' => $externalReference,
        'status' => 'pending',
        'checkout_url' => $checkoutUrl,
    ]);
} catch (Throwable $error) {
    if (isset($pdo, $orderId) && $pdo instanceof PDO && is_string($orderId)) {
        try {
            $failed = $pdo->prepare('UPDATE orders SET status = "preference_error", updated_at = :updated_at WHERE id = :id AND status = "creating_preference"');
            $failed->execute([':updated_at' => gmdate('c'), ':id' => $orderId]);
        } catch (Throwable $ignored) {
            // Não expor detalhes internos na resposta HTTP.
        }
    }
    error_log('Estudio Luz e Cor: create-preference falhou: ' . $error->getMessage());
    elc_json_response(503, [
        'ok' => false,
        'message' => 'O pagamento ainda não está configurado. Tente novamente mais tarde ou fale com o estúdio.',
    ]);
}
