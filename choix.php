<?php
// choix.php — Enable Banking: ASPSP selection + start authorization
session_start();

$cfgPath = __DIR__ . '/mon-site/config/database.php';
$config = require $cfgPath;

$env = strtoupper($config['enable_environment'] ?? 'PRODUCTION');
$isSandbox = ($env === 'SANDBOX');
$base = rtrim($config['enable_api_base'] ?? '', '/');
if (!$base) {
    $base = $isSandbox ? 'https://api.sandbox.enablebanking.com' : 'https://api.enablebanking.com';
}

// Build callback URL
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$dir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$callbackUrl = $scheme . '://' . $host . $dir . '/choix_callback.php';

// Handle POST: user selected a bank → call POST /auth server-side
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['aspsp_name']) && !empty($_POST['aspsp_country'])) {
    require_once __DIR__ . '/mon-site/api/EnableBankingClient.php';

    $state = bin2hex(random_bytes(16));
    $_SESSION['eb_state'] = $state;

    $client = new EnableBankingClient($config);
    $authRequest = [
        'access' => [
            'valid_until' => date('c', strtotime('+90 days'))
        ],
        'aspsp' => [
            'name' => $_POST['aspsp_name'],
            'country' => $_POST['aspsp_country']
        ],
        'state' => $state,
        'redirect_url' => $callbackUrl,
        'psu_type' => $_POST['psu_type'] ?? 'personal',
        'language' => 'fr'
    ];

    $res = $client->startAuth($authRequest);

    if (isset($res['error'])) {
        $error = $res['error'];
    } elseif ($res['status'] >= 200 && $res['status'] < 300 && !empty($res['body']['url'])) {
        // Redirect user to Enable Banking / ASPSP
        $_SESSION['eb_authorization_id'] = $res['body']['authorization_id'] ?? null;
        header('Location: ' . $res['body']['url']);
        exit;
    } else {
        $error = 'Erreur API (' . $res['status'] . '): ' . json_encode($res['body'] ?? $res['raw'] ?? 'unknown');
    }
}

$country = $config['enable_country'] ?? 'FR';
?><!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Choix de banque — bkTool</title>
  <link rel="stylesheet" href="assets/css/style.css">
  <script src="https://tilisy.enablebanking.com/lib/widgets.umd.min.js"></script>
  <link href="https://tilisy.enablebanking.com/lib/widgets.css" rel="stylesheet">
</head>
<body>
<div class="site-header">
  <div class="site-title">bkTool</div>
  <nav class="tabs">
    <a href="index.php">Dashboard</a>
    <a href="transactions.php">Transactions</a>
    <a href="categories.php">Paramètres</a>
    <a href="choix.php" class="active">Connecter banque</a>
  </nav>
</div>
<main>
  <h1>Choisir une banque</h1>

  <?php if (!empty($error)): ?>
    <p style="color:red"><strong>Erreur :</strong> <?= htmlspecialchars($error) ?></p>
    <?php if (!empty($_POST['aspsp_name'])): ?>
      <p><small>Banque envoyée : <?= htmlspecialchars($_POST['aspsp_name']) ?> (<?= htmlspecialchars($_POST['aspsp_country'] ?? '') ?>), env=<?= htmlspecialchars($env) ?></small></p>
    <?php endif; ?>
  <?php endif; ?>

  <p>Sélectionnez votre banque ci-dessous :</p>

  <enablebanking-aspsp-list
    id="aspsp-list"
    country="<?= htmlspecialchars($country) ?>"
    psu-type="personal"
    service="AIS"
    <?= $isSandbox ? 'sandbox' : '' ?>
  ></enablebanking-aspsp-list>

  <!-- Hidden form submitted when user picks a bank -->
  <form id="authForm" method="POST" action="choix.php" style="display:none">
    <input type="hidden" name="aspsp_name" id="aspsp_name">
    <input type="hidden" name="aspsp_country" id="aspsp_country">
    <input type="hidden" name="psu_type" id="psu_type" value="personal">
  </form>

  <p id="status"></p>
  <p><a href="index.php">Retour au tableau de bord</a></p>
</main>

<script>
document.getElementById('aspsp-list').addEventListener('selected', function(e) {
  var d = e.detail;
  document.getElementById('aspsp_name').value = d.name;
  document.getElementById('aspsp_country').value = d.country;
  document.getElementById('psu_type').value = d.psuType || 'personal';
  document.getElementById('status').textContent = 'Redirection vers ' + d.name + '…';
  document.getElementById('authForm').submit();
});
</script>
</body>
</html>
