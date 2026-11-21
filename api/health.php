<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$configPath = elc_private_config_path();
$checks = [
    'php' => PHP_VERSION_ID >= 70400,
    'curl' => function_exists('curl_init'),
    'pdo_sqlite' => extension_loaded('pdo_sqlite'),
    'private_config_installed' => is_file($configPath) && is_readable($configPath),
];
$ready = $checks['php'] && $checks['curl'] && $checks['pdo_sqlite'];
http_response_code($ready ? 200 : 503);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
echo json_encode([
    'service' => 'estudio-luz-e-cor-checkout-preparation',
    'status' => $ready ? 'backend-capable' : 'requirements-missing',
    'checks' => $checks,
    'payments_enabled' => false,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
