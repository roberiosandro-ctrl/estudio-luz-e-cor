<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

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
    if ($type !== 'payment') {
        elc_json_response(200, ['ok' => true, 'ignored' => true]);
    }

    $paymentId = (string) ($body['data']['id'] ?? $_GET['data.id'] ?? $_GET['id'] ?? '');
    $requestId = trim((string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
    $signatureHeader = trim((string) ($_SERVER['HTTP_X_SIGNATURE'] ?? ''));
    if ($paymentId === '' || $requestId === '' || $signatureHeader === '') {
        elc_json_response(400, ['ok' => false, 'message' => 'Cabeçalhos ou identificador ausentes.']);
    }

    $signatureParts = [];
    foreach (explode(',', $signatureHeader) as $part) {
        $pair = explode('=', trim($part), 2);
        if (count($pair) === 2) {
            $signatureParts[trim($pair[0])] = trim($pair[1]);
        }
    }
    $timestamp = $signatureParts['ts'] ?? '';
    $receivedSignature = $signatureParts['v1'] ?? '';
    if ($timestamp === '' || $receivedSignature === '') {
        elc_json_response(401, ['ok' => false, 'message' => 'Assinatura inválida.']);
    }

    $manifest = 'id:' . strtolower($paymentId) . ';request-id:' . $requestId . ';ts:' . $timestamp . ';';
    $expectedSignature = hash_hmac('sha256', $manifest, $webhookSecret);
    if (!hash_equals($expectedSignature, $receivedSignature)) {
        elc_json_response(401, ['ok' => false, 'message' => 'Assinatura inválida.']);
    }

    $payment = elc_mp_request($config, 'GET', '/v1/payments/' . rawurlencode($paymentId));
    $externalReference = (string) ($payment['external_reference'] ?? '');
    $paymentStatus = (string) ($payment['status'] ?? 'unknown');
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

    $expectedCents = (int) $order['amount_cents'];
    $receivedCents = (int) round(((float) ($payment['transaction_amount'] ?? 0)) * 100);
    $currency = (string) ($payment['currency_id'] ?? '');
    $amountMatches = $receivedCents === $expectedCents && $currency === 'BRL';
    $finalStatus = $amountMatches ? $paymentStatus : 'review';

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

        $orderUpdate = $pdo->prepare(
            'UPDATE orders SET payment_id = :payment_id, status = :status, updated_at = :updated_at WHERE id = :id'
        );
        $orderUpdate->execute([
            ':payment_id' => $paymentId,
            ':status' => $finalStatus,
            ':updated_at' => gmdate('c'),
            ':id' => $order['id'],
        ]);
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
