<?php
// choix.php - lance le widget d'authentification Enable Banking
session_start();

// Load configuration (may come from secrets or env)
$cfgPath = __DIR__ . '/mon-site/config/database.php';
if (!file_exists($cfgPath)) {
    throw new RuntimeException('Configuration introuvable: ' . $cfgPath);
}
$config = require $cfgPath;

$clientId = $config['enable_client_id'] ?? '';
$base = rtrim($config['enable_api_base'] ?? '', '/');
$widgetBase = $config['enable_widget_url'] ?? ($base . '/widget');

// build absolute redirect URI to choix_callback.php
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$redirect = $scheme . '://' . $host . rtrim(dirname($_SERVER['PHP_SELF']), '\/') . '/choix_callback.php';

// generate state and store in session
if (empty($_SESSION['eb_state'])) {
    $_SESSION['eb_state'] = bin2hex(random_bytes(16));
}
$state = $_SESSION['eb_state'];

// widget URL params (response_type=code per widget auth flow)
$params = http_build_query([
    'client_id' => $clientId,
    'redirect_uri' => $redirect,
    'response_type' => 'code',
    'state' => $state
]);
$widgetUrl = $widgetBase . '?' . $params;
// Use a server-side proxy so we can send Authorization header without exposing secrets
$proxyUrl = 'widget_proxy.php?' . $params;

?><!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Choix banque — bkTool</title>
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<main>
  <h1>Choisir une banque (Enable Banking)</h1>
  <p>Cliquer pour ouvrir le widget d'authentification Enable Banking dans une nouvelle fenêtre.</p>

  <p>
    <button id="openWidget">Ouvrir le widget</button>
  </p>

  <p>Redirect URI utilisée: <strong><?php echo htmlspecialchars($redirect); ?></strong></p>
  <p>Widget URL (debug): <small><?php echo htmlspecialchars($widgetUrl); ?></small></p>

  <p><a href="/">Retour au tableau de bord</a></p>
</main>

<script>
document.getElementById('openWidget').addEventListener('click', function(){
  // open the server-side proxy so Authorization header is added server-side
  window.open('<?php echo addslashes($proxyUrl); ?>', 'eb_widget', 'width=600,height=800');
});
</script>
</body>
</html>
