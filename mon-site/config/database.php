<?php
// config/database.php — doit rester hors du public web
// Charge les secrets depuis un emplacement relatif ou via variable d'environnement

$secrets = null;

// Allow overriding the secrets path with an environment variable (useful on hosting)
$envPath = getenv('BKTOOL_SECRETS_PATH');
$candidates = [];
if ($envPath) {
    $candidates[] = $envPath;
}

// Common relative locations (from mon-site/config)
$candidates[] = __DIR__ . '/../../secrets/BKT.php';    // bkTool/secrets/BKT.php
$candidates[] = __DIR__ . '/../../../secrets/BKT.php'; // parent parent of bkTool
$candidates[] = __DIR__ . '/../secrets/BKT.php';       // mon-site/secrets/BKT.php

foreach ($candidates as $p) {
    if ($p && file_exists($p)) {
        $secrets = include $p;
        break;
    }
}

if (!is_array($secrets)) {
    // Valeurs par défaut - remplacer dans le fichier de secrets sur votre serveur
    $secrets = [
        'db_host' => '127.0.0.1',
        'db_name' => 'bktool',
        'db_user' => 'root',
        'db_pass' => '',
        'enable_client_id' => 'YOUR_CLIENT_ID',
        'enable_client_secret' => 'YOUR_CLIENT_SECRET',
        'enable_api_base' => 'https://api.enablebanking.com'
    ];
}

return $secrets;

