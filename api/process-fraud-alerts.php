<?php
declare(strict_types=1);

// Worker exclusivamente CLI. Nunca expor este arquivo como endpoint HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/bootstrap.php';

function elc_finish_fraud_alert(
    PDO $pdo,
    array $alert,
    string $status,
    ?string $externalReference = null,
    ?string $orderId = null,
    ?string $refundId = null,
    ?string $errorMessage = null,
    int $nextAttemptAt = 0
): void {
    $update = $pdo->prepare(
        'UPDATE fraud_alerts
         SET status = :status,
             locked_until = NULL,
             next_attempt_at = :next_attempt_at,
             external_reference = COALESCE(:external_reference, external_reference),
             order_id = COALESCE(:order_id, order_id),
             refund_id = COALESCE(:refund_id, refund_id),
             last_error = :last_error,
             updated_at = :updated_at
         WHERE event_key = :event_key'
    );
    $update->execute([
        ':status' => $status,
        ':next_attempt_at' => $nextAttemptAt,
        ':external_reference' => $externalReference,
        ':order_id' => $orderId,
        ':refund_id' => $refundId,
        ':last_error' => $errorMessage !== null ? substr($errorMessage, 0, 500) : null,
        ':updated_at' => gmdate('c'),
        ':event_key' => $alert['event_key'],
    ]);
}

function elc_mark_fraud_order(PDO $pdo, array $order, string $paymentId, string $status, int $updatedAt): void
{
    $currentStatus = (string) ($order['status'] ?? '');
    if ($currentStatus === 'charged_back') {
        return;
    }

    $update = $pdo->prepare(
        'UPDATE orders
         SET payment_id = CASE
                 WHEN payment_id IS NULL OR payment_id = '' THEN :payment_id
                 ELSE payment_id
             END,
             status = :status,
             payment_updated_at = :payment_updated_at,
             updated_at = :updated_at
         WHERE id = :id'
    );
    $update->execute([
        ':payment_id' => $paymentId,
        ':status' => $status,
        ':payment_updated_at' => $updatedAt,
        ':updated_at' => gmdate('c'),
        ':id' => $order['id'],
    ]);
}

try {
    $config = elc_load_config();
    if (($config['payments_enabled'] ?? false) !== true) {
        throw new RuntimeException('Pagamentos desativados; fila de fraude não será processada.');
    }
    if (trim((string) ($config['access_token'] ?? '')) === ''
        || trim((string) ($config['webhook_secret'] ?? '')) === '') {
        throw new RuntimeException('Credenciais privadas do Mercado Pago não configuradas.');
    }

    $pdo = elc_database($config);
    $now = time();
    $select = $pdo->prepare(
        'SELECT * FROM fraud_alerts
         WHERE (status IN ("queued", "retry") AND next_attempt_at <= :now)
            OR (status = "processing" AND locked_until < :now)
         ORDER BY created_at ASC
         LIMIT 10'
    );
    $select->execute([':now' => $now]);
    $alerts = $select->fetchAll();

    $processed = 0;
    foreach ($alerts as $alert) {
        $pdo->beginTransaction();
        try {
            $claim = $pdo->prepare(
                'UPDATE fraud_alerts
                 SET status = "processing",
                     attempts = attempts + 1,
                     locked_until = :locked_until,
                     updated_at = :updated_at
                 WHERE event_key = :event_key
                   AND ((status IN ("queued", "retry") AND next_attempt_at <= :now)
                     OR (status = "processing" AND locked_until < :now))'
            );
            $claim->execute([
                ':locked_until' => $now + 90,
                ':updated_at' => gmdate('c'),
                ':event_key' => $alert['event_key'],
                ':now' => $now,
            ]);
            if ($claim->rowCount() !== 1) {
                $pdo->rollBack();
                continue;
            }
            $pdo->commit();
        } catch (Throwable $claimError) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $claimError;
        }

        $processed++;
        $attempts = (int) $alert['attempts'] + 1;
        try {
            $paymentId = (string) $alert['payment_id'];
            $payment = elc_mp_request($config, 'GET', '/v1/payments/' . rawurlencode($paymentId));

            if ((string) ($payment['id'] ?? '') !== $paymentId
                || !is_bool($payment['live_mode'] ?? null)
                || $payment['live_mode'] !== ((string) ($config['environment'] ?? 'test') === 'production')) {
                elc_finish_fraud_alert($pdo, $alert, 'manual_review', null, null, null, 'Pagamento ausente ou ambiente divergente.');
                continue;
            }

            $sellerUserId = trim((string) ($config['seller_user_id'] ?? ''));
            if ($sellerUserId !== ''
                && (!preg_match('/^[0-9]+$/', $sellerUserId)
                    || (string) ($payment['collector_id'] ?? '') !== $sellerUserId)) {
                elc_finish_fraud_alert($pdo, $alert, 'manual_review', null, null, null, 'Conta recebedora divergente.');
                continue;
            }

            $paymentOrderId = (string) ($payment['order']['id'] ?? '');
            if ($paymentOrderId === '' || !hash_equals((string) $alert['merchant_order_id'], $paymentOrderId)) {
                elc_finish_fraud_alert($pdo, $alert, 'manual_review', null, null, null, 'Pedido comercial divergente.');
                continue;
            }

            $externalReference = (string) ($payment['external_reference'] ?? '');
            if ($externalReference === '') {
                elc_finish_fraud_alert($pdo, $alert, 'manual_review', null, null, null, 'Referência externa ausente.');
                continue;
            }

            $findOrder = $pdo->prepare('SELECT * FROM orders WHERE external_reference = :reference LIMIT 1');
            $findOrder->execute([':reference' => $externalReference]);
            $order = $findOrder->fetch();
            if (!$order || !elc_verify_preference_binding($config, $order, $payment)) {
                elc_finish_fraud_alert($pdo, $alert, 'manual_review', $externalReference, $order ? (string) $order['id'] : null, null, 'Pedido não corresponde ao pagamento/preferência.');
                continue;
            }

            $receivedCents = elc_amount_to_cents($payment['transaction_amount'] ?? null);
            if ($receivedCents === null
                || $receivedCents !== (int) $order['amount_cents']
                || (string) ($payment['currency_id'] ?? '') !== 'BRL') {
                elc_mark_fraud_order($pdo, $order, $paymentId, 'fraud_alert_manual_review', time());
                elc_finish_fraud_alert($pdo, $alert, 'manual_review', $externalReference, (string) $order['id'], null, 'Valor ou moeda divergente.');
                continue;
            }

            $paymentUpdatedAt = strtotime((string) ($payment['date_last_updated'] ?? ''));
            if ($paymentUpdatedAt === false) {
                $paymentUpdatedAt = time();
            }

            // Bloqueia a entrega antes de tentar cancelar ou reembolsar.
            elc_mark_fraud_order($pdo, $order, $paymentId, 'fraud_alert_pending', (int) $paymentUpdatedAt);

            $action = elc_fraud_action_for_payment_status((string) ($payment['status'] ?? ''));
            if ($action === 'already_resolved') {
                $status = (string) ($payment['status'] ?? '');
                $orderStatus = $status === 'refunded'
                    ? 'fraud_alert_refunded'
                    : ($status === 'cancelled' ? 'fraud_alert_cancelled' : 'charged_back');
                elc_mark_fraud_order($pdo, $order, $paymentId, $orderStatus, (int) $paymentUpdatedAt);
                elc_finish_fraud_alert($pdo, $alert, 'completed', $externalReference, (string) $order['id']);
                continue;
            }

            if ($action === 'cancel') {
                $result = elc_mp_request(
                    $config,
                    'PUT',
                    '/v1/payments/' . rawurlencode($paymentId),
                    ['status' => 'cancelled'],
                    'elc-fraud-alert-' . substr($alert['event_key'], 0, 48)
                );
                if ((string) ($result['id'] ?? '') !== $paymentId || (string) ($result['status'] ?? '') !== 'cancelled') {
                    throw new RuntimeException('O Mercado Pago não confirmou o cancelamento do pagamento.');
                }
                elc_mark_fraud_order($pdo, $order, $paymentId, 'fraud_alert_cancelled', (int) (strtotime((string) ($result['date_last_updated'] ?? '')) ?: time()));
                elc_finish_fraud_alert($pdo, $alert, 'completed', $externalReference, (string) $order['id']);
                continue;
            }

            if ($action === 'refund') {
                if (($config['fraud_auto_refund_enabled'] ?? false) !== true) {
                    elc_mark_fraud_order($pdo, $order, $paymentId, 'fraud_alert_manual_review', (int) $paymentUpdatedAt);
                    elc_finish_fraud_alert($pdo, $alert, 'manual_review', $externalReference, (string) $order['id'], null, 'Reembolso automático permanece desativado.');
                    continue;
                }

                $refundedCents = elc_amount_to_cents($payment['transaction_amount_refunded'] ?? 0);
                if ($refundedCents === null || $refundedCents > 0) {
                    elc_mark_fraud_order($pdo, $order, $paymentId, 'fraud_alert_manual_review', (int) $paymentUpdatedAt);
                    elc_finish_fraud_alert($pdo, $alert, 'manual_review', $externalReference, (string) $order['id'], null, 'Pagamento já possui reembolso parcial ou valor reembolsado não verificável.');
                    continue;
                }

                $refund = elc_mp_request(
                    $config,
                    'POST',
                    '/v1/payments/' . rawurlencode($paymentId) . '/refunds',
                    [],
                    'elc-fraud-alert-' . substr($alert['event_key'], 0, 48)
                );
                $refundId = (string) ($refund['id'] ?? '');
                if ($refundId === ''
                    || (string) ($refund['payment_id'] ?? '') !== $paymentId
                    || !in_array((string) ($refund['status'] ?? ''), ['approved', 'refunded'], true)) {
                    throw new RuntimeException('O Mercado Pago não confirmou o reembolso integral.');
                }

                elc_mark_fraud_order($pdo, $order, $paymentId, 'fraud_alert_refunded', (int) $paymentUpdatedAt);
                elc_finish_fraud_alert($pdo, $alert, 'completed', $externalReference, (string) $order['id'], $refundId);
                continue;
            }

            elc_mark_fraud_order($pdo, $order, $paymentId, 'fraud_alert_manual_review', (int) $paymentUpdatedAt);
            elc_finish_fraud_alert($pdo, $alert, 'manual_review', $externalReference, (string) $order['id'], null, 'Status de pagamento exige análise manual.');
        } catch (Throwable $error) {
            $delay = min(3600, 60 * (2 ** min(max($attempts - 1, 0), 6)));
            $nextStatus = $attempts >= 5 ? 'manual_review' : 'retry';
            elc_finish_fraud_alert(
                $pdo,
                $alert,
                $nextStatus,
                null,
                null,
                null,
                $error->getMessage(),
                $nextStatus === 'retry' ? time() + $delay : 0
            );
            error_log('Estudio Luz e Cor: processamento de alerta de fraude falhou: ' . $error->getMessage());
        }
    }

    fwrite(STDOUT, 'Alertas de fraude processados: ' . $processed . PHP_EOL);
} catch (Throwable $error) {
    error_log('Estudio Luz e Cor: worker de fraude não executado: ' . $error->getMessage());
    fwrite(STDERR, 'Worker de fraude não executado. Verifique a configuração privada e os logs.' . PHP_EOL);
    exit(1);
}
