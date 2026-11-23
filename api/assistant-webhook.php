<?php
declare(strict_types=1);

require_once __DIR__ . '/assistant-bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    try {
        $config = elc_load_config();
        $mode = (string) ($_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '');
        $token = (string) ($_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '');
        $challenge = (string) ($_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '');
        $expectedToken = trim((string) ($config['whatsapp_webhook_verify_token'] ?? ''));

        if ($mode === 'subscribe' && $expectedToken !== '' && hash_equals($expectedToken, $token)) {
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store');
            echo $challenge;
            exit;
        }
        http_response_code(403);
        exit;
    } catch (Throwable $error) {
        error_log('Estudio Luz e Cor: falha na verificação do webhook WhatsApp: ' . $error->getMessage());
        http_response_code(404);
        exit;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: GET, POST');
    elc_json_response(405, ['ok' => false, 'message' => 'Método não permitido.']);
}

try {
    $config = elc_load_config();
    $rawBody = file_get_contents('php://input');
    if (!is_string($rawBody) || strlen($rawBody) > 262144) {
        elc_json_response(413, ['ok' => false, 'message' => 'Requisição muito grande.']);
    }

    $appSecret = trim((string) ($config['whatsapp_app_secret'] ?? ''));
    $signature = trim((string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? ''));
    if (!elc_assistant_verify_signature($rawBody, $signature, $appSecret)) {
        elc_json_response(401, ['ok' => false, 'message' => 'Assinatura inválida.']);
    }

    $payload = json_decode($rawBody, true);
    if (!is_array($payload) || ($payload['object'] ?? '') !== 'whatsapp_business_account') {
        elc_json_response(400, ['ok' => false, 'message' => 'Evento WhatsApp inválido.']);
    }

    if (($config['whatsapp_assistant_enabled'] ?? false) !== true) {
        elc_json_response(200, ['ok' => true, 'ignored' => true, 'reason' => 'assistant_disabled']);
    }

    $expectedPhoneNumberId = trim((string) ($config['whatsapp_phone_number_id'] ?? ''));
    if (!preg_match('/^[0-9]+$/', $expectedPhoneNumberId)) {
        throw new RuntimeException('Phone Number ID da Cloud API não configurado.');
    }

    $messages = elc_assistant_extract_inbound_messages($payload);
    if ($messages === []) {
        elc_json_response(200, ['ok' => true, 'queued' => 0]);
    }

    $pdo = elc_assistant_database($config);
    $insert = $pdo->prepare(
        'INSERT OR IGNORE INTO assistant_inbound
         (wamid, wa_id, phone_number_id, message_type, message_text, status, attempts, next_attempt_at, created_at, updated_at)
         VALUES (:wamid, :wa_id, :phone_number_id, :message_type, :message_text, "queued", 0, 0, :created_at, :updated_at)'
    );

    $queued = 0;
    $now = gmdate('c');
    foreach ($messages as $message) {
        if (!hash_equals($expectedPhoneNumberId, $message['phone_number_id'])) {
            continue;
        }
        $insert->execute([
            ':wamid' => $message['wamid'],
            ':wa_id' => $message['wa_id'],
            ':phone_number_id' => $message['phone_number_id'],
            ':message_type' => $message['type'],
            ':message_text' => $message['text'],
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $queued += $insert->rowCount();
    }

    // Persistir antes de responder 200 evita perder mensagens se a IA estiver indisponível.
    elc_json_response(200, ['ok' => true, 'queued' => $queued]);
} catch (Throwable $error) {
    error_log('Estudio Luz e Cor: webhook do assistente não processado: ' . $error->getMessage());
    elc_json_response(503, ['ok' => false, 'message' => 'Evento temporariamente indisponível.']);
}
