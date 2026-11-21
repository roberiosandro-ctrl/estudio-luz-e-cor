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

    // O projeto publicado fica cinco níveis abaixo da home da conta Hostinger.
    return dirname(__DIR__, 5) . '/estudio-luz-e-cor-v2-private/mercadopago-config.php';
}

function elc_load_config(): array
{
    $path = elc_private_config_path();
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('Configuração privada ainda não instalada.');
    }

    $config = require $path;
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
    if (!is_string($databasePath) || $databasePath === '') {
        throw new RuntimeException('Caminho privado do banco não configurado.');
    }

    $privateDirectory = realpath(dirname($databasePath));
    $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath((string) $_SERVER['DOCUMENT_ROOT']) : false;
    if ($privateDirectory === false || !is_dir($privateDirectory)) {
        throw new RuntimeException('Diretório privado do banco não encontrado.');
    }
    if ($documentRoot !== false && ($privateDirectory === $documentRoot || strpos($privateDirectory, $documentRoot . DIRECTORY_SEPARATOR) === 0)) {
        throw new RuntimeException('O banco de dados não pode ficar dentro da pasta pública.');
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

    return $pdo;
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

    $baseUrl = rtrim((string) ($config['api_base_url'] ?? 'https://api.mercadopago.com'), '/');
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
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
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
