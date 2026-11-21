<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

try {
    $config = elc_load_config();
    $healthToken = trim((string) ($config['health_token'] ?? ''));
    $providedToken = trim((string) ($_SERVER['HTTP_X_HEALTH_TOKEN'] ?? ''));

    // Endpoint de diagnóstico não fica público por padrão.
    if ($healthToken === '' || $providedToken === '' || !hash_equals($healthToken, $providedToken)) {
        http_response_code(404);
        exit;
    }

    $checks = [
        'php' => PHP_VERSION_ID >= 70400,
        'curl' => function_exists('curl_init'),
        'pdo_sqlite' => extension_loaded('pdo_sqlite'),
        'private_config_installed' => true,
        'sqlite_database_configured' => false,
        'sqlite_database_open' => false,
        'sqlite_database_writable' => false,
    ];

    $databasePath = $config['database_path'] ?? '';
    $checks['sqlite_database_configured'] = is_string($databasePath) && $databasePath !== '';

    if ($checks['sqlite_database_configured'] && $checks['pdo_sqlite']) {
        $pdo = elc_database($config);
        $checks['sqlite_database_open'] = $pdo instanceof PDO;

        // Escrita temporária e reversível; nenhuma linha permanente é adicionada.
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
        'status' => $ready
            ? ((($config['payments_enabled'] ?? false) === true) ? 'backend-ready' : 'backend-ready-but-disabled')
            : 'requirements-missing',
        'checks' => $checks,
        'payments_enabled' => (($config['payments_enabled'] ?? false) === true),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    error_log('Estudio Luz e Cor: verificação de saúde não concluída: ' . $error->getMessage());
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode([
        'service' => 'estudio-luz-e-cor-checkout-preparation',
        'status' => 'requirements-missing',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
