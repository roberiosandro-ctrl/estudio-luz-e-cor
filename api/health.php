<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$checks = [
    'php' => PHP_VERSION_ID >= 70400,
    'curl' => function_exists('curl_init'),
    'pdo_sqlite' => extension_loaded('pdo_sqlite'),
    'private_config_installed' => false,
    'sqlite_database_configured' => false,
    'sqlite_database_open' => false,
    'sqlite_database_writable' => false,
];

try {
    $configPath = elc_private_config_path();
    $checks['private_config_installed'] = is_file($configPath) && is_readable($configPath);

    if ($checks['private_config_installed'] && $checks['pdo_sqlite']) {
        $config = elc_load_config();
        $databasePath = $config['database_path'] ?? '';
        $checks['sqlite_database_configured'] = is_string($databasePath) && $databasePath !== '';

        if ($checks['sqlite_database_configured']) {
            $pdo = elc_database($config);
            $checks['sqlite_database_open'] = $pdo instanceof PDO;

            // Teste de escrita temporário: cria e insere em tabela TEMP,
            // confirma a operação e desfaz tudo antes de responder.
            $pdo->beginTransaction();
            try {
                $pdo->exec('CREATE TEMP TABLE IF NOT EXISTS elc_health_probe (id INTEGER PRIMARY KEY, checked_at TEXT NOT NULL)');
                $probe = $pdo->prepare('INSERT INTO elc_health_probe (checked_at) VALUES (:checked_at)');
                $probe->execute([':checked_at' => gmdate('c')]);
                $checks['sqlite_database_writable'] = $probe->rowCount() === 1;
                $pdo->rollBack();
            } catch (Throwable $probeError) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $probeError;
            }
        }
    }
} catch (Throwable $error) {
    // Detalhes técnicos ficam somente no log do servidor; nunca na resposta pública.
    error_log('Estudio Luz e Cor: verificação de saúde não concluída: ' . $error->getMessage());
}

$ready = $checks['php']
    && $checks['curl']
    && $checks['pdo_sqlite']
    && $checks['private_config_installed']
    && $checks['sqlite_database_configured']
    && $checks['sqlite_database_open']
    && $checks['sqlite_database_writable'];

http_response_code($ready ? 200 : 503);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

echo json_encode([
    'service' => 'estudio-luz-e-cor-checkout-preparation',
    'status' => $ready ? 'backend-ready-but-disabled' : 'requirements-missing',
    'checks' => $checks,
    'payments_enabled' => false,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
