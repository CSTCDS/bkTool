<?php
// api/db.php — create a PDO instance

// Try to load configuration from expected config file
$configPath = __DIR__ . '/../config/database.php'; 
if (file_exists($configPath)) {
    $config = require $configPath;
} else {
    // Fallback: try secrets file used on local dev (Windows path), then env vars
    $secretsPath = 'C:\\perso\\LWS\\secrets\\BKT.php';
    if (file_exists($secretsPath)) {
        $config = include $secretsPath;
    } else {
        // Try environment variables
        $env = [
            'db_host' => getenv('BKTOOL_DB_HOST') ?: getenv('DB_HOST'),
            'db_name' => getenv('BKTOOL_DB_NAME') ?: getenv('DB_NAME'),
            'db_user' => getenv('BKTOOL_DB_USER') ?: getenv('DB_USER'),
            'db_pass' => getenv('BKTOOL_DB_PASS') ?: getenv('DB_PASS'),
            'enable_client_id' => getenv('BKTOOL_CLIENT_ID'),
            'enable_client_secret' => getenv('BKTOOL_CLIENT_SECRET'),
            'enable_api_base' => getenv('BKTOOL_API_BASE') ?: 'https://api.sandbox.enablebanking.com'
        ];

        if ($env['db_host'] && $env['db_name']) {
            $config = $env;
        } else {
            throw new RuntimeException("Configuration not found. Expected $configPath or $secretsPath, or set BKTOOL_DB_* environment variables.");
        }
    }
}

if (!is_array($config)) {
    throw new RuntimeException('Invalid configuration loaded - expected array.');
}

$dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $config['db_host'], $config['db_name']);

try {
    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    error_log('DB connection error: ' . $e->getMessage());
    throw $e;
}

return $pdo;

