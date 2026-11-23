<?php
declare(strict_types=1);

// Worker exclusivamente CLI. Nunca expor este arquivo como endpoint HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/bootstrap.php';

function elc_send_whatsapp_template(array $config, array $message): string
{
    $apiVersion = trim((string) ($config['whatsapp_graph_api_version'] ?? 'v27.0'));
    $phoneNumberId = trim((string) ($config['whatsapp_phone_number_id'] ?? ''));
    $accessToken = trim((string) ($config['whatsapp_access_token'] ?? ''));

    if (!preg_match('/^v[0-9]+\.[0-9]+$/', $apiVersion)
        || !preg_match('/^[0-9]+$/', $phoneNumberId)
        || $accessToken === '') {
        throw new RuntimeException('Credenciais da WhatsApp Business Platform não configuradas.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('A extensão cURL não está disponível.');
    }

    $url = 'https://graph.facebook.com/' . $apiVersion . '/' . rawurlencode($phoneNumberId) . '/messages';
    $encoded = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        throw new RuntimeException('Não foi possível preparar a mensagem WhatsApp.');
    }

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $encoded,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    $responseBody = curl_exec($curl);
    $curlErrorNumber = curl_errno($curl);
    $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($responseBody === false) {
        // Resultado de transporte é ambíguo: não reenviar automaticamente para
        // evitar uma segunda mensagem caso a Meta já tenha aceitado a primeira.
        throw new RuntimeException('Falha de transporte WhatsApp; resultado incerto.', $curlErrorNumber ?: 500);
    }

    $decoded = json_decode((string) $responseBody, true);
    if ($httpStatus === 429) {
        throw new RuntimeException('Limite temporário da WhatsApp Business Platform.', 429);
    }
    if ($httpStatus < 200 || $httpStatus >= 300) {
        throw new RuntimeException(
            $httpStatus >= 500
                ? 'A WhatsApp Business Platform retornou erro de servidor; resultado incerto.'
                : 'A WhatsApp Business Platform rejeitou a mensagem.',
            $httpStatus
        );
    }

    $messageId = is_array($decoded) ? (string) ($decoded['messages'][0]['id'] ?? '') : '';
    if ($messageId === '' || strlen($messageId) > 128) {
        throw new RuntimeException('A resposta WhatsApp não confirmou o identificador da mensagem.', 500);
    }

    return $messageId;
}

try {
    $config = elc_load_config();
    if (($config['whatsapp_notifications_enabled'] ?? false) !== true) {
        throw new RuntimeException('Notificações WhatsApp desativadas.');
    }

    $recipient = trim((string) ($config['whatsapp_recipient'] ?? ''));
    $templateName = trim((string) ($config['whatsapp_template_name'] ?? ''));
    $templateLanguage = trim((string) ($config['whatsapp_template_language'] ?? 'pt_BR'));
    if (!preg_match('/^[1-9][0-9]{9,14}$/', $recipient)
        || !preg_match('/^[a-z0-9_]{1,512}$/', $templateName)
        || !preg_match('/^[a-z]{2}_[A-Z]{2}$/', $templateLanguage)) {
        throw new RuntimeException('Destinatário ou template WhatsApp inválido.');
    }

    $pdo = elc_database($config);
    $now = time();

    // Um processo interrompido durante o envio tem resultado incerto.
    // Não reenviar automaticamente para evitar mensagens duplicadas.
    $pdo->prepare(
        'UPDATE notification_outbox
         SET status = "uncertain",
             locked_until = NULL,
             last_error = "Worker interrompido durante o envio; verificar antes de reenviar.",
             updated_at = :updated_at
         WHERE channel = "whatsapp"
           AND status = "processing"
           AND locked_until < :now'
    )->execute([':updated_at' => gmdate('c'), ':now' => $now]);

    $select = $pdo->prepare(
        'SELECT * FROM notification_outbox
         WHERE channel = "whatsapp"
           AND status IN ("queued", "retry")
           AND next_attempt_at <= :now
         ORDER BY created_at ASC
         LIMIT 10'
    );
    $select->execute([':now' => $now]);
    $notifications = $select->fetchAll();

    $processed = 0;
    foreach ($notifications as $notification) {
        $pdo->beginTransaction();
        try {
            $claim = $pdo->prepare(
                'UPDATE notification_outbox
                 SET status = "processing",
                     attempts = attempts + 1,
                     locked_until = :locked_until,
                     updated_at = :updated_at
                 WHERE id = :id
                   AND channel = "whatsapp"
                   AND status IN ("queued", "retry")
                   AND next_attempt_at <= :now'
            );
            $claim->execute([
                ':locked_until' => $now + 90,
                ':updated_at' => gmdate('c'),
                ':id' => $notification['id'],
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
        $attempts = (int) $notification['attempts'] + 1;
        try {
            if (!hash_equals($recipient, (string) $notification['recipient'])) {
                throw new RuntimeException('Destinatário da fila diverge da configuração atual.', 400);
            }

            $payload = json_decode((string) $notification['payload_json'], true);
            if (!is_array($payload)) {
                throw new RuntimeException('Dados da notificação armazenada são inválidos.', 400);
            }

            $message = elc_whatsapp_template_message(
                $recipient,
                $templateName,
                $templateLanguage,
                (string) ($payload['order_reference'] ?? ''),
                (string) ($payload['package_name'] ?? ''),
                (int) ($payload['amount_cents'] ?? 0)
            );
            $providerMessageId = elc_send_whatsapp_template($config, $message);

            $update = $pdo->prepare(
                'UPDATE notification_outbox
                 SET status = "sent",
                     provider_message_id = :provider_message_id,
                     locked_until = NULL,
                     last_error = NULL,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $update->execute([
                ':provider_message_id' => $providerMessageId,
                ':updated_at' => gmdate('c'),
                ':id' => $notification['id'],
            ]);
        } catch (Throwable $error) {
            $errorCode = (int) $error->getCode();
            if ($errorCode === 429 && $attempts < 5) {
                $delay = min(3600, 60 * (2 ** min($attempts - 1, 6)));
                $status = 'retry';
                $nextAttemptAt = time() + $delay;
            } elseif ($errorCode >= 400 && $errorCode < 500) {
                $status = 'failed';
                $nextAttemptAt = 0;
            } else {
                $status = 'uncertain';
                $nextAttemptAt = 0;
            }

            $update = $pdo->prepare(
                'UPDATE notification_outbox
                 SET status = :status,
                     locked_until = NULL,
                     next_attempt_at = :next_attempt_at,
                     last_error = :last_error,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $update->execute([
                ':status' => $status,
                ':next_attempt_at' => $nextAttemptAt,
                ':last_error' => substr($error->getMessage(), 0, 500),
                ':updated_at' => gmdate('c'),
                ':id' => $notification['id'],
            ]);
            error_log('Estudio Luz e Cor: notificação WhatsApp ' . $status . '.');
        }
    }

    fwrite(STDOUT, 'Notificações WhatsApp processadas: ' . $processed . PHP_EOL);
} catch (Throwable $error) {
    error_log('Estudio Luz e Cor: worker WhatsApp não executado: ' . $error->getMessage());
    fwrite(STDERR, 'Worker WhatsApp não executado. Verifique a configuração privada e os logs.' . PHP_EOL);
    exit(1);
}
