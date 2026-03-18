<?php
// config/database.php — doit rester hors du public web
// Charge les secrets depuis C:\\perso\\LWS\\secrets\\BKT.php si present

$secretsPath = 'C:\\perso\\LWS\\secrets\\BKT.php';

if (file_exists($secretsPath)) {
    $secrets = include $secretsPath;
} else {
    // Valeurs par défaut - remplacer dans le fichier de secrets
    $secrets = [
        'db_host' => '127.0.0.1',
        'db_name' => 'bktool',
        'db_user' => 'root',
        'db_pass' => '',
        'enable_client_id' => 'YOUR_CLIENT_ID',
        'enable_client_secret' => 'YOUR_CLIENT_SECRET',
        'enable_api_base' => 'https://api.sandbox.enablebanking.com'
    ];
}

return $secrets;
<?php
// config/database.php — doit rester hors du public web
// Charge les secrets depuis C:\\perso\\LWS\\secrets\\BKT.php si présent

$secretsPath = 'C:\\perso\\LWS\\secrets\\BKT.php';

if (file_exists($secretsPath)) {
    $secrets = include $secretsPath;
} else {
    // Valeurs par défaut - remplacer dans le fichier de secrets
    $secrets = [
        'db_host' => '127.0.0.1',
        'db_name' => 'bktool',
        'db_user' => 'root',
        'db_pass' => '',
        'enable_client_id' => 'YOUR_CLIENT_ID',
        'enable_client_secret' => 'YOUR_CLIENT_SECRET',
        'enable_api_base' => 'https://api.sandbox.enablebanking.com'
    ];
}

return $secrets;
