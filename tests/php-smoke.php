<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/bootstrap.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FALHOU: {$message}\n");
        exit(1);
    }
}

$packages = elc_packages();
$expectedPackages = [
    'fotos-02' => [2, 2990],
    'fotos-05' => [5, 5490],
    'fotos-10' => [10, 8990],
    'fotos-15' => [15, 10990],
    'fotos-20' => [20, 12990],
];
check(count($packages) === count($expectedPackages), 'quantidade de pacotes');
foreach ($expectedPackages as $id => [$quantity, $amountCents]) {
    check(isset($packages[$id]), "pacote {$id} existe");
    check($packages[$id]['quantity'] === $quantity, "quantidade do {$id}");
    check($packages[$id]['amount_cents'] === $amountCents, "valor em centavos do {$id}");
}

check(elc_path_is_within('/home/site/public_html/api', '/home/site/public_html'), 'detecta caminho dentro da raiz pública');
check(!elc_path_is_within('/home/site/public_html_backup', '/home/site/public_html'), 'não confunde diretórios com prefixo semelhante');

$secret = 'segredo-de-teste-nao-real';
$requestId = 'request-123';
$dataId = '123456789';
$timestamp = '1700000000';
$manifest = 'id:' . $dataId . ';request-id:' . $requestId . ';ts:' . $timestamp . ';';
$signature = 'ts=' . $timestamp . ',v1=' . hash_hmac('sha256', $manifest, $secret);
check(elc_verify_webhook_signature($signature, $requestId, $dataId, $secret), 'aceita assinatura HMAC válida');
check(!elc_verify_webhook_signature($signature, $requestId, '123456788', $secret), 'rejeita ID diferente');
check(!elc_verify_webhook_signature($signature, 'request-456', $dataId, $secret), 'rejeita request ID diferente');
check(!elc_verify_webhook_signature($signature . ',ts=' . $timestamp, $requestId, $dataId, $secret), 'rejeita timestamp duplicado');
check(!elc_verify_webhook_signature($signature, $requestId, $dataId, ''), 'rejeita segredo vazio');

$codes = elc_allowed_model_codes();
check(count($codes) === 60, 'catálogo deve permitir 60 códigos');
check(count(array_unique($codes)) === 60, 'códigos permitidos devem ser únicos');
check(elc_normalize_model_codes(['ELEG-01', 'FASH-03']) === ['ELEG-01', 'FASH-03'], 'aceita referências misturadas entre coleções');
check(elc_normalize_model_codes(['ELEG-01', 'ELEG-01']) === ['ELEG-01'], 'remove referências repetidas');
check(elc_normalize_model_codes(['ELEG-01', 'INVALID-99']) === null, 'rejeita referência fora do catálogo');
check(elc_normalize_model_codes([]) === null, 'rejeita seleção vazia');
check(elc_normalize_model_codes(array_fill(0, 61, 'ELEG-01')) === null, 'rejeita mais de 60 entradas');

$preferenceOrder = ['preference_id' => 'pref-test-1', 'external_reference' => 'ELC-TEST-1'];
$matchingPayment = ['id' => '1001', 'preference_id' => 'pref-test-1'];
$wrongPreferencePayment = ['id' => '1001', 'preference_id' => 'pref-test-2'];
check(elc_verify_preference_binding([], $preferenceOrder, $matchingPayment), 'aceita vínculo direto com a preferência correta');
check(!elc_verify_preference_binding([], $preferenceOrder, $wrongPreferencePayment), 'rejeita preferência diferente');
check(elc_amount_to_cents('29.90') === 2990, 'conversão monetária para centavos');
check(elc_amount_to_cents('129.9') === 12990, 'conversão com uma casa decimal');
check(elc_amount_to_cents('29.901') === null, 'rejeita mais de duas casas decimais');
check(elc_amount_to_cents('1e2') === null, 'rejeita notação científica');
check(elc_amount_to_cents('-1.00') === null, 'rejeita valor negativo');

$pending = ['status' => 'pending', 'payment_id' => '', 'payment_updated_at' => null];
check(elc_should_apply_payment_update($pending, '1001', 'approved', 100), 'pendente pode virar aprovado');

$approved = ['status' => 'approved', 'payment_id' => '1001', 'payment_updated_at' => 100];
check(!elc_should_apply_payment_update($approved, '1001', 'pending', 101), 'pendente não rebaixa aprovado');
check(elc_should_apply_payment_update($approved, '1001', 'refunded', 102), 'aprovado pode virar reembolsado');
check(!elc_should_apply_payment_update($approved, '1002', 'approved', 103), 'outro pagamento não substitui pagamento aprovado');
check(!elc_should_apply_payment_update($approved, '1001', 'refunded', 99), 'evento antigo não substitui estado atual');
check(!elc_should_apply_payment_update($approved, '1001', 'pending', 100), 'empate de data não altera estado');

$refunded = ['status' => 'refunded', 'payment_id' => '1001', 'payment_updated_at' => 102];
check(elc_should_apply_payment_update($refunded, '1001', 'charged_back', 103), 'reembolsado pode virar contestado');
check(!elc_should_apply_payment_update($refunded, '1001', 'approved', 104), 'reembolso não pode voltar a aprovado');

$chargedBack = ['status' => 'charged_back', 'payment_id' => '1001', 'payment_updated_at' => 103];
check(!elc_should_apply_payment_update($chargedBack, '1001', 'approved', 104), 'contestado é estado terminal');

$fraudPending = ['status' => 'fraud_alert_pending', 'payment_id' => '1001', 'payment_updated_at' => 103];
check(!elc_should_apply_payment_update($fraudPending, '1001', 'approved', 105), 'alerta de fraude bloqueia atualizações normais');
check(elc_fraud_action_for_payment_status('pending') === 'cancel', 'alerta cancela pagamento pendente');
check(elc_fraud_action_for_payment_status('in_process') === 'cancel', 'alerta cancela pagamento em processamento');
check(elc_fraud_action_for_payment_status('authorized') === 'cancel', 'alerta cancela pagamento autorizado');
check(elc_fraud_action_for_payment_status('approved') === 'refund', 'alerta reembolsa pagamento aprovado');
check(elc_fraud_action_for_payment_status('refunded') === 'already_resolved', 'pagamento reembolsado já está resolvido');
check(elc_fraud_action_for_payment_status('charged_back') === 'already_resolved', 'pagamento contestado já está resolvido');
check(elc_fraud_action_for_payment_status('in_mediation') === 'manual_review', 'status não previsto exige análise manual');
$validRefund = ['id' => '2001', 'payment_id' => '1001', 'status' => 'approved', 'amount' => 54.90];
$partialRefund = ['id' => '2002', 'payment_id' => '1001', 'status' => 'approved', 'amount' => 20.00];
$wrongPaymentRefund = ['id' => '2003', 'payment_id' => '9999', 'status' => 'approved', 'amount' => 54.90];
check(elc_refund_matches_payment($validRefund, '1001', 5490), 'aceita reembolso integral do pagamento correto');
check(!elc_refund_matches_payment($partialRefund, '1001', 5490), 'rejeita reembolso parcial como integral');
check(!elc_refund_matches_payment($wrongPaymentRefund, '1001', 5490), 'rejeita reembolso de outro pagamento');

$whatsappMessage = elc_whatsapp_template_message(
    '5548998336779',
    'elc_payment_approved',
    'pt_BR',
    'ELC-ABC123',
    'Pacote 5 fotos',
    5490
);
check($whatsappMessage['messaging_product'] === 'whatsapp', 'payload WhatsApp usa produto correto');
check($whatsappMessage['to'] === '5548998336779', 'payload WhatsApp usa destinatário configurado');
check($whatsappMessage['template']['language']['code'] === 'pt_BR', 'payload WhatsApp usa idioma do template');
check($whatsappMessage['template']['components'][0]['parameters'][2]['text'] === 'R$ 54,90', 'payload WhatsApp formata valor em reais');
check(elc_format_brl_cents(12990) === 'R$ 129,90', 'formatação de valor em reais');

$invalidRecipientRejected = false;
try {
    elc_whatsapp_template_message('123', 'elc_payment_approved', 'pt_BR', 'ELC-ABC123', 'Pacote', 5490);
} catch (InvalidArgumentException $error) {
    $invalidRecipientRejected = true;
}
check($invalidRecipientRejected, 'rejeita destinatário WhatsApp inválido');

$tempDirectory = sys_get_temp_dir() . '/elc-smoke-' . bin2hex(random_bytes(6));
check(mkdir($tempDirectory, 0700), 'cria diretório temporário de teste');
$databasePath = $tempDirectory . '/orders.sqlite';
try {
    $pdo = elc_database(['database_path' => $databasePath]);
    $tableNames = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
    check(in_array('orders', $tableNames, true), 'SQLite cria tabela de pedidos');
    check(in_array('webhook_events', $tableNames, true), 'SQLite cria tabela de eventos');
    check(in_array('fraud_alerts', $tableNames, true), 'SQLite cria fila de alertas de fraude');
    check(in_array('notification_outbox', $tableNames, true), 'SQLite cria fila de notificações WhatsApp');
    $pdo = null;
} finally {
    foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $temporaryFile) {
        if (is_file($temporaryFile)) {
            unlink($temporaryFile);
        }
    }
    if (is_dir($tempDirectory)) {
        rmdir($tempDirectory);
    }
}

echo "PHP smoke tests: OK\n";
