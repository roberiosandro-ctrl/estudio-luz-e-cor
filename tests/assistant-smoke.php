<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/assistant-bootstrap.php';

function assistant_check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FALHOU: {$message}\n");
        exit(1);
    }
}

$appSecret = 'segredo-falso-de-teste';
$rawBody = '{"object":"whatsapp_business_account","entry":[]}';
$signature = 'sha256=' . hash_hmac('sha256', $rawBody, $appSecret);
assistant_check(elc_assistant_verify_signature($rawBody, $signature, $appSecret), 'aceita assinatura Meta válida');
assistant_check(!elc_assistant_verify_signature($rawBody, $signature, 'outro-segredo'), 'rejeita segredo Meta diferente');
assistant_check(!elc_assistant_verify_signature($rawBody, 'sha256=inválido', $appSecret), 'rejeita assinatura malformada');
assistant_check(!elc_assistant_verify_signature('', $signature, $appSecret), 'rejeita corpo vazio');

$payload = [
    'object' => 'whatsapp_business_account',
    'entry' => [[
        'changes' => [[
            'value' => [
                'metadata' => ['phone_number_id' => '123456789012345'],
                'messages' => [
                    [
                        'id' => 'wamid.test.001',
                        'from' => '5548999999999',
                        'type' => 'text',
                        'text' => ['body' => 'Quero conhecer os pacotes.'],
                    ],
                    [
                        'id' => 'wamid.test.002',
                        'from' => '5548999999999',
                        'type' => 'interactive',
                        'interactive' => ['button_reply' => ['title' => 'Ver pacotes']],
                    ],
                    [
                        'id' => 'wamid.test.003',
                        'from' => '5548999999999',
                        'type' => 'image',
                        'image' => ['id' => 'media-id'],
                    ],
                ],
            ],
        ]],
    ]],
];
$messages = elc_assistant_extract_inbound_messages($payload);
assistant_check(count($messages) === 3, 'extrai mensagens e ignora eventos sem mensagem');
assistant_check($messages[0]['text'] === 'Quero conhecer os pacotes.', 'extrai texto recebido');
assistant_check($messages[1]['text'] === 'Ver pacotes', 'extrai resposta de botão');
assistant_check(strpos($messages[2]['text'], 'imagem') !== false, 'orienta mídia ainda não suportada');
assistant_check($messages[0]['phone_number_id'] === '123456789012345', 'preserva Phone Number ID');

$catalog = elc_assistant_catalog();
assistant_check(count($catalog) === 60, 'carrega as 60 referências do catálogo existente');
assistant_check($catalog[0]['code'] === 'ELEG-01', 'primeira referência do catálogo');
assistant_check(count(elc_packages()) === 5, 'carrega os cinco pacotes comerciais');
assistant_check(elc_normalize_model_codes(['ELEG-01', 'FASH-02']) === ['ELEG-01', 'FASH-02'], 'aceita referências de coleções diferentes');
assistant_check(elc_normalize_model_codes(['ELEG-01', 'INVALID-99']) === null, 'rejeita código que não existe');
assistant_check(strpos(elc_assistant_system_instructions(), 'R$ 54,90') !== false, 'inclui preços reais no contexto do atendente');

echo "Assistant smoke tests: OK\n";
