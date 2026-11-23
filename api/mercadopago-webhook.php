<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function elc_receive_fraud_alert(array $config, array $body): void
{
    $queryType = (string) ($_GET['type'] ?? $_GET['topic'] ?? '');
    $queryDataId = trim((string) ($_GET['data_id'] ?? $_GET['data.id'] ?? ''));
    $bodyId = trim((string) ($body['id'] ?? ''));
    $paymentId = trim((string) ($body['data']['payment_id'] ?? ''));
    $merchantOrderId = trim((string) ($body['data']['merchant_order'] ?? ''));
    $requestId = trim((string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
    $signatureHeader = trim((string) ($_SERVER['HTTP_X_SIGNATURE'] ?? ''));
    $webhookSecret = trim((string) ($config['webhook_secret'] ?? ''));

    if ($queryType !== 'stop_delivery_op_wh'
        || $queryDataId === ''
        || !preg_match('/^[0-9]+$/', $queryDataId)
        || $bodyId === ''
        || !hash_equals($queryDataId, $bodyId)
        || $paymentId === ''
        || !hash_equals($queryDataId, $paymentId)
        || !preg_match('/^[0-9]+$/', $merchantOrderId)
        || !is_string($body['date_created'] ?? null)
        || trim((string) $body['date_created']) === ''
        || strlen((string) $body['date_created']) > 64) {
        elc_json_response(400, ['ok' => false, 'message' => 'Dados do alerta de fraude inválidos.']);
    }

    if (!elc_verify_webhook_signature($signatureHeader, $requestId, $queryDataId, $webhookSecret)) {
        elc_json_response(401, ['ok' => false, 'message' => 'Assinatura inválida.']);
    }

    $expectedLiveMode = (string) ($config['environment'] ?? 'test') === 'production';
    if (!is_bool($body['live_mode'] ?? null) || $body['live_mode'] !== $expectedLiveMode) {
        elc_json_response(200, ['ok' => true, 'ignored' => true]);
    }

    $sellerUserId = trim((string) ($config['seller_user_id'] ?? ''));
    $eventUserId = trim((string) ($body['user_id'] ?? ''));
    if ($expectedLiveMode && $sellerUserId === '') {
        throw new RuntimeException('ID da conta vendedora não configurado para alertas de fraude em produção.');
    }
    if ($eventUserId === '' || !preg_match('/^[0-9]+$/', $eventUserId)
        || ($sellerUserId !== '' && !hash_equals($sellerUserId, $eventUserId))) {
        elc_json_response(200, ['ok' => true, 'ignored' => true]);
    }

    $createdAt = (string) $body['date_created'];
    if (strtotime($createdAt) === false) {
        elc_json_response(400, ['ok' => false, 'message' => 'Data do alerta de fraude inválida.']);
    }

    // O alerta é persistido antes de qualquer chamada externa. O Mercado Pago não
    // reenvia automaticamente este tópico se o servidor não confirmar o recebimento.
    $pdo = elc_database($config);
    $eventKey = hash('sha256', 'stop_delivery_op_wh|' . $paymentId . '|' . $merchantOrderId . '|' . $createdAt);
    $insert = $pdo->prepare(
        'INSERT OR IGNORE INTO fraud_alerts
         (event_key, payment_id, merchant_order_id, live_mode, status, attempts, next_attempt_at, created_at, updated_at)
         VALUES (:event_key, :payment_id, :merchant_order_id, :live_mode, "queued", 0, 0, :created_at, :updated_at)'
    );
    $now = gmdate('c');
    $insert->execute([
        ':event_key' => $eventKey,
        ':payment_id' => $paymentId,
        ':merchant_order_id' => $merchantOrderId,
        ':live_mode' => $expectedLiveMode ? 1 : 0,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    elc_json_response(200, ['ok' => true, 'queued' => true]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    elc_json_response(405, ['ok' => false, 'message' => 'Método não permitido.']);
}

try {
    $config = elc_load_config();
    if (($config['payments_enabled'] ?? false) !== true) {
        elc_json_response(200, ['ok' => true, 'ignored' => true]);
    }

    $accessToken = trim((string) ($config['access_token'] ?? ''));
    $webhookSecret = trim((string) ($config['webhook_secret'] ?? ''));
    if ($accessToken === '' || $webhookSecret === '') {
        throw new RuntimeException('Credenciais privadas do Mercado Pago ainda não configuradas.');
    }

    $rawBody = file_get_contents('php://input');
    if (!is_string($rawBody) || strlen($rawBody) > 65536) {
        elc_json_response(413, ['ok' => false, 'message' => 'Requisição muito grande.']);
    }
    $body = json_decode($rawBody, true);
    if (!is_array($body)) {
        elc_json_response(400, ['ok' => false, 'message' => 'JSON inválido.']);
    }

    $type = (string) ($body['type'] ?? $_GET['type'] ?? $_GET['topic'] ?? '');
    if ($type === 'stop_delivery_op_wh') {
        elc_receive_fraud_alert($config, $body);
    }
    if ($type !== 'payment') {
        elc_json_response(200, ['ok' => true, 'ignored' => true]);
    }

    // A assinatura do Mercado Pago usa o data.id recebido na query string.
    // Não aceitar o ID somente do corpo, pois isso alteraria o manifesto assinado.
    $queryType = (string) ($_GET['type'] ?? $_GET['topic'] ?? '');
    $paymentId = trim((string) ($_GET['data_id'] ?? $_GET['data.id'] ?? ''));
    $bodyPaymentId = trim((string) ($body['data']['id'] ?? ''));
    $requestId = trim((string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
    $signatureHeader = trim((string) ($_SERVER['HTTP_X_SIGNATURE'] ?? ''));
    if ($queryType !== 'payment'
        || $paymentId === ''
        || !preg_match('/^[0-9]+$/', $paymentId)
        || $bodyPaymentId === ''
        || !hash_equals($paymentId, $bodyPaymentId)
        || $requestId === ''
        || strlen($requestId) > 200
        || preg_match('/[\\x00-\\x1F\\x7F]/', $requestId)
        || $signatureHeader === ''
        || strlen($signatureHeader) > 512) {
        elc_json_response(400, ['ok' => false, 'message' => 'Cabeçalhos ou identificador ausentes ou inválidos.']);
    }

    if (!elc_verify_webhook_signature($signatureHeader, $requestId, $paymentId, $webhookSecret)) {
        elc_json_response(401, ['ok' => false, 'message' => 'Assinatura inválida.']);
    }

    $payment = elc_mp_request($config, 'GET', '/v1/payments/' . rawurlencode($paymentId));
    if ((string) ($payment['id'] ?? '') !== $paymentId) {
        elc_json_response(200, ['ok' => true, 'ignored' => true]);
    }

    // Impedir que uma notificação de teste atualize pedido de produção, ou vice-versa.
    $expectedLiveMode = (string) ($config['environment'] ?? 'test') === 'production';
    if (!array_key_exists('live_mode', $payment)
        || !is_bool($payment['live_mode'])
        || $payment['live_mode'] !== $expectedLiveMode) {
        error_log('Estudio Luz e Cor: ambiente do pagamento não corresponde ao ambiente configurado.');
        elc_json_response(200, ['ok' => true, 'ignored' => true]);
    }

    // Em produção, exigir que o pagamento tenha sido recebido pela conta vendedora esperada.
    $expectedCollectorId = trim((string) ($config['seller_user_id'] ?? ''));
    if ($expectedLiveMode && $expectedCollectorId === '') {
        throw new RuntimeException('ID da conta vendedora não configurado para produção.');
    }
    if ($expectedCollectorId !== ''
        && (!preg_match('/^[0-9]+$/', $expectedCollectorId)
            || (string) ($payment['collector_id'] ?? '') !== $expectedCollectorId)) {
        error_log('Estudio Luz e Cor: pagamento direcionado a uma conta vendedora diferente da configurada.');
        elc_json_response(200, ['ok' => true, 'ignored' => true]);
    }

    $externalReference = (string) ($payment['external_reference'] ?? '');
    $paymentStatus = (string) ($payment['status'] ?? '');
    if ($externalReference === '') {
        elc_json_response(200, ['ok' => true, 'ignored' => true]);
    }

    $pdo = elc_database($config);
    $find = $pdo->prepare('SELECT * FROM orders WHERE external_reference = :reference LIMIT 1');
    $find->execute([':reference' => $externalReference]);
    $order = $find->fetch();
    if (!$order) {
        error_log('Estudio Luz e Cor: notificação recebida sem pedido correspondente.');
        elc_json_response(200, ['ok' => true, 'ignored' => true]);
    }

    if (!elc_verify_preference_binding($config, $order, $payment)) {
        error_log('Estudio Luz e Cor: pagamento não corresponde à preferência salva no pedido.');
        elc_json_response(200, ['ok' => true, 'ignored' => true]);
    }

    $receivedCents = elc_amount_to_cents($payment['transaction_amount'] ?? null);
    $currency = (string) ($payment['currency_id'] ?? '');
    $amountMatches = $receivedCents !== null
        && $receivedCents === (int) $order['amount_cents']
        && $currency === 'BRL';

    $allowedStatuses = ['pending', 'in_process', 'approved', 'rejected', 'cancelled', 'refunded', 'charged_back'];
    $finalStatus = $amountMatches && in_array($paymentStatus, $allowedStatuses, true)
        ? $paymentStatus
        : 'review';

    $paymentUpdatedAt = strtotime((string) ($payment['date_last_updated'] ?? ''));
    if ($paymentUpdatedAt === false) {
        throw new RuntimeException('Data de atualização do pagamento ausente ou inválida.');
    }

    $eventKey = hash('sha256', $paymentId . '|' . $paymentStatus . '|' . (string) ($payment['date_last_updated'] ?? '') . '|' . $externalReference);
    $pdo->beginTransaction();
    try {
        $eventInsert = $pdo->prepare(
            'INSERT OR IGNORE INTO webhook_events (event_key, payment_id, payment_status, received_at)
             VALUES (:event_key, :payment_id, :payment_status, :received_at)'
        );
        $eventInsert->execute([
            ':event_key' => $eventKey,
            ':payment_id' => $paymentId,
            ':payment_status' => $finalStatus,
            ':received_at' => gmdate('c'),
        ]);

        // Uma notificação repetida não reaplica a atualização do pedido.
        if ($eventInsert->rowCount() === 1) {
            $refresh = $pdo->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
            $refresh->execute([':id' => $order['id']]);
            $currentOrder = $refresh->fetch();

            if ($currentOrder && elc_should_apply_payment_update($currentOrder, $paymentId, $finalStatus, (int) $paymentUpdatedAt)) {
                $orderUpdate = $pdo->prepare(
                    'UPDATE orders
                     SET payment_id = :payment_id,
                         status = :status,
                         payment_updated_at = :payment_updated_at,
                         updated_at = :updated_at
                     WHERE id = :id'
                );
                $orderUpdate->execute([
                    ':payment_id' => $paymentId,
                    ':status' => $finalStatus,
                    ':payment_updated_at' => (int) $paymentUpdatedAt,
                    ':updated_at' => gmdate('c'),
                    ':id' => $currentOrder['id'],
                ]);
            }
        }

        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }

    elc_json_response(200, ['ok' => true]);
} catch (Throwable $error) {
    error_log('Estudio Luz e Cor: webhook não processado: ' . $error->getMessage());
    elc_json_response(503, ['ok' => false, 'message' => 'Notificação temporariamente indisponível.']);
}
