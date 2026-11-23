<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    elc_json_response(405, ['ok' => false, 'message' => 'Método não permitido.']);
}

try {
    $config = elc_load_config();
    $healthToken = trim((string) ($config['health_token'] ?? ''));
    $providedToken = trim((string) ($_SERVER['HTTP_X_HEALTH_TOKEN'] ?? ''));

    // Endpoint de diagnóstico não fica público por padrão.
    if ($healthToken === '' || $providedToken === '' || !hash_equals($healthToken, $providedToken)) {
        http_response_code(404);
        exit;
    }

    // Este endpoint é estritamente somente leitura: não chama elc_database(),
    // pois essa função cria tabelas, aplica migrações e ativa WAL.
    $checks = [
        'php' => PHP_VERSION_ID >= 70400,
        'curl' => function_exists('curl_init'),
        'pdo_sqlite' => extension_loaded('pdo_sqlite'),
        'private_config_installed' => true,
        'sqlite_database_configured' => false,
        'sqlite_database_file_exists' => false,
        'sqlite_database_readable' => false,
        'sqlite_database_writable' => false,
        'sqlite_directory_writable' => false,
        'sqlite_outside_document_root' => false,
    ];

    $databasePath = $config['database_path'] ?? '';
    $checks['sqlite_database_configured'] = is_string($databasePath) && $databasePath !== '';

    if ($checks['sqlite_database_configured']) {
        $databaseDirectory = realpath(dirname($databasePath));
        $databaseRealPath = is_file($databasePath) ? realpath($databasePath) : false;
        $documentRoot = isset($_SERVER['DOCUMENT_ROOT'])
            ? realpath((string) $_SERVER['DOCUMENT_ROOT'])
            : false;

        $checks['sqlite_outside_document_root'] = !is_link($databasePath)
            && $databaseDirectory !== false
            && ($documentRoot === false
                || !elc_path_is_within($databaseDirectory, $documentRoot))
            && ($databaseRealPath === false
                || $documentRoot === false
                || !elc_path_is_within($databaseRealPath, $documentRoot));

        $checks['sqlite_database_file_exists'] = is_file($databasePath);
        $checks['sqlite_database_readable'] = $checks['sqlite_database_file_exists'] && is_readable($databasePath);
        $checks['sqlite_database_writable'] = $checks['sqlite_database_file_exists'] && is_writable($databasePath);
        $checks['sqlite_directory_writable'] = $databaseDirectory !== false && is_writable($databaseDirectory);
    }

    $ready = $checks['php']
        && $checks['curl']
        && $checks['pdo_sqlite']
        && $checks['private_config_installed']
        && $checks['sqlite_database_configured']
        && $checks['sqlite_database_file_exists']
        && $checks['sqlite_database_readable']
        && $checks['sqlite_database_writable']
        && $checks['sqlite_directory_writable']
        && $checks['sqlite_outside_document_root'];

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
