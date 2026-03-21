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
<?php include __DIR__ . '/header.php'; ?>
<main>
  <h1>Banque</h1>

  <?php if (!empty($error)): ?>
    <p style="color:red"><strong>Erreur :</strong> <?= htmlspecialchars($error) ?></p>
    <?php if (!empty($_POST['aspsp_name'])): ?>
      <p><small>Banque envoyée : <?= htmlspecialchars($_POST['aspsp_name']) ?> (<?= htmlspecialchars($_POST['aspsp_country'] ?? '') ?>), env=<?= htmlspecialchars($env) ?></small></p>
    <?php endif; ?>
  <?php endif; ?>

  <?php $pane = $_GET['pane'] ?? 'connect'; ?>
  <div style="display:flex;gap:18px;align-items:flex-start">
    <div style="min-width:220px">
      <form method="get">
        <label><strong>Choix :</strong>
          <select name="pane" onchange="this.form.submit()">
            <option value="sync" <?php echo ($pane === 'sync') ? 'selected' : ''; ?>>Synchroniser banque</option>
            <option value="connect" <?php echo ($pane === 'connect') ? 'selected' : ''; ?>>Connecter banque</option>
          </select>
        </label>
      </form>
    </div>

    <div style="flex:1">
      <?php if ($pane === 'sync'): ?>
        <h2>Synchronisation</h2>
        <p>Lancer la synchronisation et afficher les statistiques ci-dessous.</p>
        <p>
          <button id="startSync">Lancer synchronisation</button>
          <span id="syncResult" style="margin-left:12px"></span>
        </p>
        <div id="syncStats" style="margin-top:12px;background:#fafafa;padding:10px;border:1px solid #eee;display:none">
          <pre id="syncJson" style="white-space:pre-wrap;font-size:.95rem"></pre>
        </div>

      <?php else: ?>
        <h2>Connecter une banque</h2>
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
      <?php endif; ?>
    </div>
  </div>
</main>

<script>
// Attach ASPSP selection handler only when present (pane=connect)
var aspsp = document.getElementById('aspsp-list');
if (aspsp) {
  aspsp.addEventListener('selected', function(e) {
    var d = e.detail;
    var n = document.getElementById('aspsp_name');
    var c = document.getElementById('aspsp_country');
    var p = document.getElementById('psu_type');
    if (n) n.value = d.name;
    if (c) c.value = d.country;
    if (p) p.value = d.psuType || 'personal';
    var st = document.getElementById('status'); if (st) st.textContent = 'Redirection vers ' + d.name + '…';
    var f = document.getElementById('authForm'); if (f) f.submit();
  });
}

// Sync button handler (pane=sync)
var syncBtn = document.getElementById('startSync');
if (syncBtn) {
  syncBtn.addEventListener('click', function() {
    var resultEl = document.getElementById('syncResult');
    var statsEl = document.getElementById('syncStats');
    var jsonEl = document.getElementById('syncJson');
    resultEl.textContent = '… synchronisation en cours';
    statsEl.style.display = 'none';
    // Optionally ask for token if configured
    var token = prompt('Token de synchronisation (laisser vide si non configuré)');
    var headers = {};
    if (token && token.trim() !== '') headers['X-Sync-Token'] = token.trim();
    fetch('sync.php', { method: 'GET', headers: headers })
      .then(function(r) { return r.json().catch(function(){ return { status: 'error', message: 'Invalid JSON response' }; }); })
      .then(function(j) {
        if (!j) { resultEl.textContent = 'Erreur: réponse vide'; return; }
        if (j.status === 'ok') {
          var res = j.result || {};
          var msg = 'OK — comptes: ' + (res.accounts || 0) + ' (' + (res.accounts_insert || 0) + ' ins., ' + (res.accounts_update || 0) + ' maj.)' +
                    ' ; lignes: ' + (res.transactions || 0) + ' (' + (res.transactions_insert || 0) + ' ins., ' + (res.transactions_update || 0) + ' maj.)';
          resultEl.textContent = msg;
          jsonEl.textContent = JSON.stringify(res, null, 2);
          statsEl.style.display = 'block';
        } else {
          resultEl.textContent = 'Erreur: ' + (j.message || JSON.stringify(j));
          if (j.result) { jsonEl.textContent = JSON.stringify(j.result, null, 2); statsEl.style.display = 'block'; }
        }
      }).catch(function(e){ resultEl.textContent = 'Erreur: '+e; });
  });
}
</script>
</body>
</html>
