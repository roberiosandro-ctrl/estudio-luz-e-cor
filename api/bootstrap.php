<?php
declare(strict_types=1);

/**
 * Estúdio Luz e Cor V2 — bootstrap privado da integração Mercado Pago.
 * Não contém credenciais. A configuração real deve ficar fora de public_html.
 */

function elc_json_response(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function elc_private_config_path(): string
{
    $configured = getenv('ELC_MP_CONFIG_PATH') ?: getenv('RSM_MP_CONFIG_PATH');
    if (is_string($configured) && $configured !== '') {
        return $configured;
    }

    // Caminho absoluto conhecido da home da conta Hostinger.
    // Mantido fora de public_html; pode ser sobrescrito por variável de ambiente.
    return '/home/u643738877/domains/rsmtrafegopago.online/private/mercadopago-config.php';
}

function elc_path_is_within(string $path, string $directory): bool
{
    return $path === $directory
        || strpos($path, rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) === 0;
}

function elc_load_config(): array
{
    $path = elc_private_config_path();
    $realConfigPath = realpath($path);
    $documentRoot = isset($_SERVER['DOCUMENT_ROOT'])
        ? realpath((string) $_SERVER['DOCUMENT_ROOT'])
        : false;

    if (is_link($path) || $realConfigPath === false || !is_file($realConfigPath) || !is_readable($realConfigPath)) {
        throw new RuntimeException('Configuração privada ainda não instalada ou caminho inválido.');
    }
    if ($documentRoot !== false && elc_path_is_within($realConfigPath, $documentRoot)) {
        throw new RuntimeException('A configuração privada não pode ficar dentro da pasta pública.');
    }

    $config = require $realConfigPath;
    if (!is_array($config)) {
        throw new RuntimeException('Configuração privada inválida.');
    }

    $environment = $config['environment'] ?? 'test';
    if (!in_array($environment, ['test', 'production'], true)) {
        throw new RuntimeException('Ambiente de pagamento inválido.');
    }

    return $config;
}

function elc_database(array $config): PDO
{
    $databasePath = $config['database_path'] ?? '';
    if (!is_string($databasePath) || $databasePath === '' || $databasePath[0] !== DIRECTORY_SEPARATOR) {
        throw new RuntimeException('Caminho absoluto privado do banco não configurado.');
    }
    if (is_link($databasePath)) {
        throw new RuntimeException('O arquivo do banco não pode ser um link simbólico.');
    }

    $privateDirectory = realpath(dirname($databasePath));
    $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath((string) $_SERVER['DOCUMENT_ROOT']) : false;
    if ($privateDirectory === false || !is_dir($privateDirectory)) {
        throw new RuntimeException('Diretório privado do banco não encontrado.');
    }
    if ($documentRoot !== false && elc_path_is_within($privateDirectory, $documentRoot)) {
        throw new RuntimeException('O banco de dados não pode ficar dentro da pasta pública.');
    }

    if (is_file($databasePath)) {
        $realDatabasePath = realpath($databasePath);
        if ($realDatabasePath === false
            || ($documentRoot !== false && elc_path_is_within($realDatabasePath, $documentRoot))) {
            throw new RuntimeException('O arquivo do banco não está em um caminho privado válido.');
        }
    }

    $pdo = new PDO('sqlite:' . $databasePath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS orders (
            id TEXT PRIMARY KEY,
            external_reference TEXT NOT NULL UNIQUE,
            package_id TEXT NOT NULL,
            package_name TEXT NOT NULL,
            amount_cents INTEGER NOT NULL,
            currency TEXT NOT NULL DEFAULT "BRL",
            selected_model_codes TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT "created",
            preference_id TEXT,
            payment_id TEXT,
            checkout_url TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );
    // Migração compatível com bancos já criados antes da proteção de ordem dos eventos.
    $orderColumns = $pdo->query('PRAGMA table_info(orders)')->fetchAll(PDO::FETCH_ASSOC);
    $hasPaymentUpdatedAt = false;
    foreach ($orderColumns as $column) {
        if (($column['name'] ?? '') === 'payment_updated_at') {
            $hasPaymentUpdatedAt = true;
            break;
        }
    }
    if (!$hasPaymentUpdatedAt) {
        $pdo->exec('ALTER TABLE orders ADD COLUMN payment_updated_at INTEGER');
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS webhook_events (
            event_key TEXT PRIMARY KEY,
            payment_id TEXT NOT NULL,
            payment_status TEXT,
            received_at TEXT NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS rate_limits (
            ip_hash TEXT NOT NULL,
            bucket_start INTEGER NOT NULL,
            hits INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (ip_hash, bucket_start)
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS fraud_alerts (
            event_key TEXT PRIMARY KEY,
            payment_id TEXT NOT NULL,
            merchant_order_id TEXT NOT NULL,
            live_mode INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT "queued",
            attempts INTEGER NOT NULL DEFAULT 0,
            next_attempt_at INTEGER NOT NULL DEFAULT 0,
            locked_until INTEGER,
            external_reference TEXT,
            order_id TEXT,
            refund_id TEXT,
            last_error TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_fraud_alerts_queue ON fraud_alerts(status, next_attempt_at)');
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS notification_outbox (
            id TEXT PRIMARY KEY,
            order_id TEXT NOT NULL,
            channel TEXT NOT NULL,
            event_type TEXT NOT NULL,
            recipient TEXT NOT NULL,
            payload_json TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT "queued",
            attempts INTEGER NOT NULL DEFAULT 0,
            next_attempt_at INTEGER NOT NULL DEFAULT 0,
            locked_until INTEGER,
            provider_message_id TEXT,
            last_error TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            UNIQUE(order_id, channel, event_type)
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_notification_outbox_queue ON notification_outbox(status, next_attempt_at)');

    return $pdo;
}

function elc_verify_webhook_signature(string $signatureHeader, string $requestId, string $dataId, string $secret): bool
{
    if ($secret === ''
        || $signatureHeader === ''
        || strlen($signatureHeader) > 512
        || $requestId === ''
        || strlen($requestId) > 200
        || preg_match('/[\\x00-\\x1F\\x7F]/', $requestId)
        || !preg_match('/^[0-9]+$/', $dataId)) {
        return false;
    }

    $signatureParts = [];
    foreach (explode(',', $signatureHeader) as $part) {
        $pair = explode('=', trim($part), 2);
        if (count($pair) !== 2) {
            continue;
        }
        $key = trim($pair[0]);
        if (in_array($key, ['ts', 'v1'], true) && array_key_exists($key, $signatureParts)) {
            return false;
        }
        $signatureParts[$key] = trim($pair[1]);
    }

    $timestamp = $signatureParts['ts'] ?? '';
    $receivedSignature = $signatureParts['v1'] ?? '';
    if ($timestamp === '' || !preg_match('/^[0-9]+$/', $timestamp)
        || $receivedSignature === '' || !preg_match('/^[a-fA-F0-9]{64}$/', $receivedSignature)) {
        return false;
    }

    // Não impor janela curta de idade: o Mercado Pago pode reenviar notificações.
    // A autenticidade é verificada pela assinatura e a repetição pela idempotência.
    $manifest = 'id:' . strtolower($dataId) . ';request-id:' . $requestId . ';ts:' . $timestamp . ';';
    $expectedSignature = hash_hmac('sha256', $manifest, $secret);
    return hash_equals($expectedSignature, strtolower($receivedSignature));
}

function elc_packages(): array
{
    return [
        'fotos-02' => ['name' => 'Pacote 2 fotos', 'quantity' => 2, 'amount_cents' => 2990],
        'fotos-05' => ['name' => 'Pacote 5 fotos', 'quantity' => 5, 'amount_cents' => 5490],
        'fotos-10' => ['name' => 'Pacote 10 fotos', 'quantity' => 10, 'amount_cents' => 8990],
        'fotos-15' => ['name' => 'Pacote 15 fotos', 'quantity' => 15, 'amount_cents' => 10990],
        'fotos-20' => ['name' => 'Pacote 20 fotos', 'quantity' => 20, 'amount_cents' => 12990],
    ];
}

function elc_allowed_model_codes(): array
{
    $prefixes = ['ELEG', 'PROF', 'ROM', 'LUX', 'ANIV', 'GEST', 'CAS', 'FASH', 'FAM', 'FANT'];
    $codes = [];
    foreach ($prefixes as $prefix) {
        for ($number = 1; $number <= 6; $number++) {
            $codes[] = sprintf('%s-%02d', $prefix, $number);
        }
    }
    return $codes;
}

function elc_normalize_model_codes($modelCodes): ?array
{
    if (!is_array($modelCodes) || count($modelCodes) < 1 || count($modelCodes) > 60) {
        return null;
    }

    $allowedCodes = array_flip(elc_allowed_model_codes());
    $validatedCodes = [];
    foreach ($modelCodes as $code) {
        if (!is_string($code) || !isset($allowedCodes[$code])) {
            return null;
        }
        $validatedCodes[$code] = true;
    }

    return array_keys($validatedCodes);
}

function elc_amount_to_cents($amount): ?int
{
    if (!is_string($amount) && !is_int($amount) && !is_float($amount)) {
        return null;
    }
    $value = trim((string) $amount);
    if (!preg_match('/^(?:0|[1-9][0-9]*)(?:\\.[0-9]{1,2})?$/', $value)) {
        return null;
    }
    $parts = explode('.', $value, 2);
    $whole = (int) $parts[0];
    $fraction = isset($parts[1]) ? str_pad($parts[1], 2, '0') : '00';
    return ($whole * 100) + (int) $fraction;
}

function elc_verify_preference_binding(array $config, array $order, array $payment): bool
{
    $preferenceId = trim((string) ($order['preference_id'] ?? ''));
    $externalReference = (string) ($order['external_reference'] ?? '');
    $paymentId = (string) ($payment['id'] ?? '');
    if ($preferenceId === '' || $externalReference === '' || $paymentId === '') {
        return false;
    }

    // Uma preferência explicitamente informada pelo recurso Payment deve coincidir
    // exatamente com a preferência salva no pedido.
    $paymentPreferenceId = trim((string) ($payment['preference_id'] ?? ''));
    if ($paymentPreferenceId !== '') {
        return hash_equals($preferenceId, $paymentPreferenceId);
    }

    // Se Payment não trouxer preference_id, exigir vínculo verificável por merchant order.
    // Não basta consultar a preferência isoladamente: isso não prova que este pagamento
    // específico pertence a ela.
    $merchantOrderId = $payment['order']['id'] ?? null;
    if (!is_scalar($merchantOrderId) || !preg_match('/^[0-9]+$/', (string) $merchantOrderId)) {
        return false;
    }

    $merchantOrder = elc_mp_request($config, 'GET', '/merchant_orders/' . rawurlencode((string) $merchantOrderId));
    if ((string) ($merchantOrder['external_reference'] ?? '') !== $externalReference
        || (string) ($merchantOrder['preference_id'] ?? '') !== $preferenceId) {
        return false;
    }

    $merchantPayments = $merchantOrder['payments'] ?? [];
    if (!is_array($merchantPayments)) {
        return false;
    }
    foreach ($merchantPayments as $merchantPayment) {
        if ((string) ($merchantPayment['id'] ?? '') === $paymentId) {
            return true;
        }
    }
    return false;
}

function elc_format_brl_cents(int $amountCents): string
{
    return 'R$ ' . number_format($amountCents / 100, 2, ',', '.');
}

function elc_whatsapp_template_message(
    string $recipient,
    string $templateName,
    string $language,
    string $orderReference,
    string $packageName,
    int $amountCents
): array {
    if (!preg_match('/^[1-9][0-9]{9,14}$/', $recipient)
        || !preg_match('/^[a-z0-9_]{1,512}$/', $templateName)
        || !preg_match('/^[a-z]{2}_[A-Z]{2}$/', $language)
        || $orderReference === ''
        || $packageName === ''
        || $amountCents < 1) {
        throw new InvalidArgumentException('Configuração ou dados do template WhatsApp inválidos.');
    }

    return [
        'messaging_product' => 'whatsapp',
        'recipient_type' => 'individual',
        'to' => $recipient,
        'type' => 'template',
        'template' => [
            'name' => $templateName,
            'language' => ['code' => $language],
            'components' => [[
                'type' => 'body',
                'parameters' => [
                    ['type' => 'text', 'text' => $orderReference],
                    ['type' => 'text', 'text' => $packageName],
                    ['type' => 'text', 'text' => elc_format_brl_cents($amountCents)],
                ],
            ]],
        ],
    ];
}

function elc_fraud_action_for_payment_status(string $status): string
{
    if (in_array($status, ['pending', 'in_process', 'authorized'], true)) {
        return 'cancel';
    }
    if ($status === 'approved') {
        return 'refund';
    }
    if (in_array($status, ['cancelled', 'refunded', 'charged_back'], true)) {
        return 'already_resolved';
    }
    return 'manual_review';
}

function elc_should_apply_payment_update(array $order, string $paymentId, string $newStatus, int $newUpdatedAt): bool
{
    $currentStatus = (string) ($order['status'] ?? '');
    $currentPaymentId = (string) ($order['payment_id'] ?? '');
    $samePayment = $currentPaymentId === '' || $currentPaymentId === $paymentId;

    // Um alerta de fraude bloqueia qualquer atualização normal de pagamento.
    if (strpos($currentStatus, 'fraud_alert_') === 0) {
        return false;
    }

    if ($currentStatus === 'charged_back') {
        return false;
    }
    if ($currentStatus === 'refunded') {
        return $newStatus === 'charged_back' && $samePayment;
    }
    if ($currentStatus === 'approved' && !in_array($newStatus, ['refunded', 'charged_back'], true)) {
        return false;
    }
    if (!$samePayment && in_array($currentStatus, ['approved', 'refunded', 'charged_back'], true)) {
        return false;
    }

    $currentUpdatedAt = isset($order['payment_updated_at']) ? (int) $order['payment_updated_at'] : 0;
    if ($newUpdatedAt < $currentUpdatedAt) {
        return false;
    }
    if ($newUpdatedAt === $currentUpdatedAt && $currentPaymentId === $paymentId && $currentStatus !== $newStatus) {
        // Empate de timestamp não permite substituir um estado já gravado.
        return false;
    }
    return true;
}

function elc_rate_limit(PDO $pdo, int $limit = 12, int $windowSeconds = 600): void
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $ipHash = hash('sha256', $ip . '|' . (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $bucket = (int) (floor(time() / $windowSeconds) * $windowSeconds);

    $pdo->beginTransaction();
    try {
        $select = $pdo->prepare('SELECT hits FROM rate_limits WHERE ip_hash = :ip AND bucket_start = :bucket');
        $select->execute([':ip' => $ipHash, ':bucket' => $bucket]);
        $row = $select->fetch();
        if ($row && (int) $row['hits'] >= $limit) {
            $pdo->rollBack();
            elc_json_response(429, ['ok' => false, 'message' => 'Muitas tentativas. Aguarde alguns minutos e tente novamente.']);
        }

        // Remover buckets antigos periodicamente para impedir crescimento ilimitado da tabela.
        if ($bucket % ($windowSeconds * 6) === 0) {
            $cleanup = $pdo->prepare('DELETE FROM rate_limits WHERE bucket_start < :cutoff');
            $cleanup->execute([':cutoff' => $bucket - ($windowSeconds * 6)]);
        }

        $upsert = $pdo->prepare(
            'INSERT INTO rate_limits (ip_hash, bucket_start, hits) VALUES (:ip, :bucket, 1)
             ON CONFLICT(ip_hash, bucket_start) DO UPDATE SET hits = hits + 1'
        );
        $upsert->execute([':ip' => $ipHash, ':bucket' => $bucket]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function elc_require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        elc_json_response(405, ['ok' => false, 'message' => 'Método não permitido.']);
    }
}

function elc_read_json_body(int $maxBytes = 32768): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || strlen($raw) > $maxBytes) {
        elc_json_response(413, ['ok' => false, 'message' => 'Requisição muito grande.']);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        elc_json_response(400, ['ok' => false, 'message' => 'Corpo JSON inválido.']);
    }
    return $data;
}

function elc_mp_request(array $config, string $method, string $path, ?array $payload = null, ?string $idempotencyKey = null): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('A extensão cURL não está disponível.');
    }

    $token = trim((string) ($config['access_token'] ?? ''));
    if ($token === '') {
        throw new RuntimeException('Credencial privada do Mercado Pago ainda não configurada.');
    }

    $method = strtoupper($method);
    if (!in_array($method, ['GET', 'POST', 'PUT'], true)) {
        throw new RuntimeException('Método HTTP não permitido para a API Mercado Pago.');
    }
    if ($idempotencyKey !== null
        && ($idempotencyKey === '' || strlen($idempotencyKey) > 128
            || !preg_match('/^[A-Za-z0-9._:-]+$/', $idempotencyKey))) {
        throw new RuntimeException('Chave de idempotência inválida.');
    }

    $baseUrl = rtrim((string) ($config['api_base_url'] ?? 'https://api.mercadopago.com'), '/');
    $baseParts = parse_url($baseUrl);
    if (!is_array($baseParts)
        || ($baseParts['scheme'] ?? '') !== 'https'
        || strtolower((string) ($baseParts['host'] ?? '')) !== 'api.mercadopago.com'
        || isset($baseParts['user'])
        || isset($baseParts['pass'])
        || isset($baseParts['port'])
        || $path === ''
        || $path[0] !== '/') {
        throw new RuntimeException('Destino da API Mercado Pago inválido.');
    }
    $url = $baseUrl . $path;
    $headers = [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    if ($idempotencyKey !== null) {
        $headers[] = 'X-Idempotency-Key: ' . $idempotencyKey;
    }

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 9,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($payload !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    $responseBody = curl_exec($curl);
    $curlError = curl_error($curl);
    $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($responseBody === false) {
        error_log('Estudio Luz e Cor: falha de comunicação com o Mercado Pago: ' . $curlError);
        throw new RuntimeException('Não foi possível comunicar com o Mercado Pago.');
    }

    $decoded = json_decode((string) $responseBody, true);
    if ($httpStatus < 200 || $httpStatus >= 300 || !is_array($decoded)) {
        error_log('Estudio Luz e Cor: Mercado Pago respondeu HTTP ' . $httpStatus . '.');
        throw new RuntimeException('O Mercado Pago não aceitou a operação.');
    }

    return $decoded;
}
