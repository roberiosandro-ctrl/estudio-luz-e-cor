<?php
declare(strict_types=1);

// Worker exclusivamente CLI. Não chamar pelo navegador.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/assistant-bootstrap.php';

try {
    $config = elc_load_config();
    if (($config['whatsapp_assistant_enabled'] ?? false) !== true) {
        throw new RuntimeException('Atendente inteligente desativado na configuração privada.');
    }
    if (trim((string) ($config['openai_api_key'] ?? '')) === ''
        || trim((string) ($config['whatsapp_access_token'] ?? '')) === ''
        || trim((string) ($config['whatsapp_phone_number_id'] ?? '')) === '') {
        throw new RuntimeException('Credenciais privadas do atendente ainda não configuradas.');
    }

    $pdo = elc_assistant_database($config);
    $now = time();

    // Um worker interrompido durante a chamada externa pode ter enviado a resposta.
    // Resultado incerto não é reenviado automaticamente para evitar mensagens duplicadas.
    $pdo->prepare(
        'UPDATE assistant_inbound
         SET status = "uncertain", locked_until = NULL,
             last_error = "Worker interrompido durante processamento; conferir antes de reenviar.",
             updated_at = :updated_at
         WHERE status = "processing" AND locked_until < :now'
    )->execute([':updated_at' => gmdate('c'), ':now' => $now]);

    $select = $pdo->prepare(
        'SELECT * FROM assistant_inbound
         WHERE status IN ("queued", "retry") AND next_attempt_at <= :now
         ORDER BY created_at ASC LIMIT 10'
    );
    $select->execute([':now' => $now]);
    $pending = $select->fetchAll();

    $processed = 0;
    foreach ($pending as $inbound) {
        $pdo->beginTransaction();
        try {
            $claim = $pdo->prepare(
                'UPDATE assistant_inbound
                 SET status = "processing", attempts = attempts + 1,
                     locked_until = :locked_until, updated_at = :updated_at
                 WHERE wamid = :wamid AND status IN ("queued", "retry") AND next_attempt_at <= :now'
            );
            $claim->execute([
                ':locked_until' => $now + 120,
                ':updated_at' => gmdate('c'),
                ':wamid' => $inbound['wamid'],
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
        $attempts = (int) $inbound['attempts'] + 1;
        $sendingStarted = false;

        try {
            $userMessage = $pdo->prepare(
                'INSERT OR IGNORE INTO assistant_messages (wa_id, role, body, provider_message_id, created_at)
                 VALUES (:wa_id, "user", :body, :provider_message_id, :created_at)'
            );
            $userMessage->execute([
                ':wa_id' => $inbound['wa_id'],
                ':body' => $inbound['message_text'],
                ':provider_message_id' => $inbound['wamid'],
                ':created_at' => $inbound['created_at'],
            ]);

            $historyQuery = $pdo->prepare(
                'SELECT role, body FROM assistant_messages
                 WHERE wa_id = :wa_id ORDER BY id DESC LIMIT 18'
            );
            $historyQuery->execute([':wa_id' => $inbound['wa_id']]);
            $history = array_reverse($historyQuery->fetchAll());

            $answer = elc_assistant_request_ai($config, $history);
            // Links externos gerados livremente pela IA são removidos; somente o checkout
            // criado pelo backend pode ser enviado ao cliente.
            $reply = preg_replace('~https?://[^\\s]+~iu', '[link removido]', $answer['reply']);
            $packageId = $answer['package_id'];
            $modelCodes = $answer['model_codes'];
            $customerAuthorizedCheckout = elc_assistant_customer_requested_checkout(
                (string) $inbound['message_text'],
                $history
            );

            if ($answer['checkout_intent'] && !$customerAuthorizedCheckout) {
                $reply .= "\\n\\nPara sua segurança, só vou gerar o link quando você confirmar que deseja finalizar a compra. Se quiser, responda: Pode gerar meu link de pagamento.";
            }

            if ($answer['checkout_intent'] && $customerAuthorizedCheckout) {
                $package = is_string($packageId) ? (elc_packages()[$packageId] ?? null) : null;
                $validSelection = is_array($package)
                    && count($modelCodes) === (int) $package['quantity'];

                if (!$validSelection) {
                    $reply .= "\n\nPara gerar seu link, preciso confirmar o pacote e a quantidade exata de referências. Você pode me informar quais modelos escolheu?";
                } elseif (($config['payments_enabled'] ?? false) !== true) {
                    $reply .= "\n\nO checkout automático está em preparação e ainda não pode gerar cobranças. Sua seleção não foi enviada nem cobrada. Assim que estiver liberado, poderemos gerar o link por aqui.";
                } else {
                    $checkout = elc_assistant_create_checkout(
                        $config,
                        $pdo,
                        (string) $inbound['wamid'],
                        (string) $inbound['wa_id'],
                        (string) $packageId,
                        $modelCodes
                    );
                    $reply .= "\n\nAqui está seu link seguro para concluir o pagamento: " . $checkout['checkout_url'];
                    $reply .= "\nReferência do pedido: " . $checkout['order_reference'];
                }
            }

            $sendingStarted = true;
            $providerMessageId = elc_assistant_send_text(
                $config,
                (string) $inbound['wa_id'],
                $reply,
                (string) $inbound['wamid']
            );

            $assistantMessage = $pdo->prepare(
                'INSERT OR IGNORE INTO assistant_messages (wa_id, role, body, provider_message_id, created_at)
                 VALUES (:wa_id, "assistant", :body, :provider_message_id, :created_at)'
            );
            $assistantMessage->execute([
                ':wa_id' => $inbound['wa_id'],
                ':body' => $reply,
                ':provider_message_id' => $providerMessageId,
                ':created_at' => gmdate('c'),
            ]);

            $conversation = $pdo->prepare(
                'INSERT INTO assistant_conversations (wa_id, state_json, last_inbound_at, created_at, updated_at)
                 VALUES (:wa_id, "{}", :last_inbound_at, :created_at, :updated_at)
                 ON CONFLICT(wa_id) DO UPDATE SET last_inbound_at = excluded.last_inbound_at, updated_at = excluded.updated_at'
            );
            $conversation->execute([
                ':wa_id' => $inbound['wa_id'],
                ':last_inbound_at' => time(),
                ':created_at' => gmdate('c'),
                ':updated_at' => gmdate('c'),
            ]);

            $done = $pdo->prepare(
                'UPDATE assistant_inbound
                 SET status = "sent", locked_until = NULL, last_error = NULL, updated_at = :updated_at
                 WHERE wamid = :wamid'
            );
            $done->execute([':updated_at' => gmdate('c'), ':wamid' => $inbound['wamid']]);
        } catch (Throwable $error) {
            $code = (int) $error->getCode();
            if ($code === 429 && $attempts < 6) {
                $status = 'retry';
                $nextAttempt = time() + min(3600, 60 * (2 ** min($attempts - 1, 5)));
            } elseif (!$sendingStarted && ($code === 0 || $code >= 500) && $attempts < 5) {
                $status = 'retry';
                $nextAttempt = time() + min(1800, 30 * (2 ** min($attempts - 1, 5)));
            } elseif ($sendingStarted && $code >= 500) {
                $status = 'uncertain';
                $nextAttempt = 0;
            } elseif ($sendingStarted && $code === 0) {
                $status = 'uncertain';
                $nextAttempt = 0;
            } else {
                $status = 'failed';
                $nextAttempt = 0;
            }

            $update = $pdo->prepare(
                'UPDATE assistant_inbound
                 SET status = :status, locked_until = NULL, next_attempt_at = :next_attempt_at,
                     last_error = :last_error, updated_at = :updated_at
                 WHERE wamid = :wamid'
            );
            $update->execute([
                ':status' => $status,
                ':next_attempt_at' => $nextAttempt,
                ':last_error' => substr($error->getMessage(), 0, 500),
                ':updated_at' => gmdate('c'),
                ':wamid' => $inbound['wamid'],
            ]);
            error_log('Estudio Luz e Cor: atendimento WhatsApp ' . $status . ': ' . $error->getMessage());
        }
    }

    echo 'Mensagens processadas: ' . $processed . PHP_EOL;
} catch (Throwable $error) {
    error_log('Estudio Luz e Cor: worker do atendente não executado: ' . $error->getMessage());
    fwrite(STDERR, 'Worker não executado: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
