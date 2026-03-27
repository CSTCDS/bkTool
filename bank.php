<?php
// bank.php — Enable Banking: ASPSP selection + start authorization (renamed from choix.php)
session_start();

$cfgPath = __DIR__ . '/mon-site/config/database.php';
$config = require $cfgPath;

$env = strtoupper($config['enable_environment'] ?? 'PRODUCTION');
$isSandbox = ($env === 'SANDBOX');
$base = rtrim($config['enable_api_base'] ?? '', '/');
if (!$base) {
    $base = $isSandbox ? 'https://api.sandbox.enablebanking.com' : 'https://api.enablebanking.com';
}

// Build callback URL (callback endpoint kept as choix_callback.php)
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

// Determine requested pane early to avoid undefined variable when rendering
$pane = $_GET['pane'] ?? '';
if (!in_array($pane, ['', 'sync', 'connect', 'testsync', 'logs'], true)) {
  $pane = '';
}

?><!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Banque — bkTool</title>
  <link rel="stylesheet" href="assets/css/style.css">
  <script src="https://tilisy.enablebanking.com/lib/widgets.umd.min.js"></script>
  <link href="https://tilisy.enablebanking.com/lib/widgets.css" rel="stylesheet">
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>
<main<?php echo ($pane === 'logs') ? ' class="full-width"' : ''; ?>>
  <h1>Banque</h1>

  <?php if (!empty($error)): ?>
    <p style="color:red"><strong>Erreur :</strong> <?= htmlspecialchars($error) ?></p>
    <?php if (!empty($_POST['aspsp_name'])): ?>
      <p><small>Banque envoyée : <?= htmlspecialchars($_POST['aspsp_name']) ?> (<?= htmlspecialchars($_POST['aspsp_country'] ?? '') ?>), env=<?= htmlspecialchars($env) ?></small></p>
    <?php endif; ?>
  <?php endif; ?>

  <?php
  $pane = $_GET['pane'] ?? '';
  if (!in_array($pane, ['', 'sync', 'connect', 'testsync', 'logs'], true)) {
    $pane = '';
  }
  ?>
  <div style="display:flex;gap:18px;align-items:flex-start">
    <div style="min-width:220px">
      <form method="get">
        <label><strong>Action :</strong>
          <select name="pane" onchange="this.form.submit()">
            <option value="" <?php echo ($pane === '') ? 'selected' : ''; ?>>---</option>
            <option value="sync" <?php echo ($pane === 'sync') ? 'selected' : ''; ?>>Synchroniser banque</option>
            <option value="testsync" <?php echo ($pane === 'testsync') ? 'selected' : ''; ?>>Test synchro</option>
            <option value="connect" <?php echo ($pane === 'connect') ? 'selected' : ''; ?>>Connecter banque</option>
            <option value="logs" <?php echo ($pane === 'logs') ? 'selected' : ''; ?>>Voir les logs</option>
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
        <div id="pendingWrites" style="margin-top:12px;background:#fff8e1;padding:10px;border:1px solid #ffe58f;display:none">
          <h3 style="margin:0 0 8px 0">Écritures en attente</h3>
          <div id="pendingCount" style="font-weight:600;margin-bottom:6px"></div>
          <pre id="pendingJson" style="white-space:pre-wrap;font-size:.9rem;margin:0"></pre>
        </div>

      <?php elseif ($pane === 'testsync'): ?>
        <h2>Test synchronisation (API raw)</h2>
        <p>Affiche les réponses brutes renvoyées par l'API Enable Banking (session, soldes, écritures).</p>
        <div style="margin-top:12px;background:#fff;padding:10px;border:1px solid #eee;">
          <pre id="testRaw" style="white-space:pre-wrap;font-family:monospace;"><?php
            // Server-side: call EnableBanking API and print raw responses
            try {
              $pdo = require __DIR__ . '/mon-site/api/db.php';
              require_once __DIR__ . '/mon-site/api/EnableBankingClient.php';
              $client = new EnableBankingClient($config);

              // retrieve stored session id
              $sstmt = $pdo->prepare('SELECT `value` FROM settings WHERE `key` = :k');
              $sstmt->execute([':k' => 'eb_session_id']);
              $sessionId = $sstmt->fetchColumn();

              if (!$sessionId) {
                echo "No eb_session_id in settings (connect first via Connecter banque).";
              } else {
                $out = '';
                $sessionRes = $client->getSession($sessionId);
                $out .= "=== GET /sessions/" . $sessionId . " (status=" . ($sessionRes['status'] ?? '??') . ") ===\n";
                $out .= ($sessionRes['raw'] ?? json_encode($sessionRes['body'] ?? null)) . "\n\n";

                $accountsData = $sessionRes['body']['accounts_data'] ?? [];
                if (!empty($accountsData)) {
                  foreach ($accountsData as $acc) {
                    $uid = $acc['uid'] ?? ($acc['id'] ?? null);
                    if (!$uid) continue;
                    $balRes = $client->getAccountBalances($uid);
                    $out .= "=== GET /accounts/" . $uid . "/balances (status=" . ($balRes['status'] ?? '??') . ") ===\n";
                    $out .= ($balRes['raw'] ?? json_encode($balRes['body'] ?? null)) . "\n\n";

                    $txRes = $client->getAccountTransactions($uid);
                    $out .= "=== GET /accounts/" . $uid . "/transactions (status=" . ($txRes['status'] ?? '??') . ") ===\n";
                    $out .= ($txRes['raw'] ?? json_encode($txRes['body'] ?? null)) . "\n\n";
                  }
                } else {
                  $out .= "No accounts_data found in session response.\n";
                }
                echo htmlspecialchars($out, ENT_QUOTES | ENT_SUBSTITUTE);
              }
            } catch (Throwable $e) {
              echo 'Erreur côté serveur: ' . htmlspecialchars((string)$e);
            }
          ?></pre>
        </div>

      <?php elseif ($pane === 'connect'): ?>
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
        <form id="authForm" method="POST" action="bank.php" style="display:none">
          <input type="hidden" name="aspsp_name" id="aspsp_name">
          <input type="hidden" name="aspsp_country" id="aspsp_country">
          <input type="hidden" name="psu_type" id="psu_type" value="personal">
        </form>

        <p id="status"></p>
        <p><a href="index.php">Retour au tableau de bord</a></p>

      <?php else: ?>
        <!-- Empty placeholder when '---' selected: intentionally show nothing -->
      <?php endif; ?>
      <?php if ($pane === 'logs'): ?>
        <h2>Logs</h2>
        <p>Derniers enregistrements système (triés par date/heure décroissante)</p>
        <div style="margin-top:12px;background:#fff;padding:10px;border:1px solid #eee;overflow:auto">
          <div style="margin-bottom:8px">
            <button id="logsReload" class="btn">Reload</button>
          </div>
          <?php
            try {
              $db = require __DIR__ . '/mon-site/api/db.php';
              $q = $db->prepare('SELECT id, log_date, log_time, code_programme, libelle, payload, created_at FROM logs ORDER BY log_date DESC, log_time DESC, id DESC LIMIT 500');
              $q->execute();
              $rows = $q->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
              echo '<p style="color:red">Erreur lecture logs: ' . htmlspecialchars((string)$e) . '</p>';
              $rows = [];
            }
          ?>
          <table style="width:auto;border-collapse:collapse;table-layout:auto">
            <thead>
              <tr>
                <th style="border:1px solid #ddd;padding:6px">Date</th>
                <th style="border:1px solid #ddd;padding:6px">Heure</th>
                <th style="border:1px solid #ddd;padding:6px">Code</th>
                <th style="border:1px solid #ddd;padding:6px">Libellé</th>
                <th style="border:1px solid #ddd;padding:6px">Payload</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td style="border:1px solid #eee;padding:6px;vertical-align:top"><?php echo htmlspecialchars($r['log_date']); ?></td>
                <td style="border:1px solid #eee;padding:6px;vertical-align:top"><?php echo htmlspecialchars($r['log_time']); ?></td>
                <td style="border:1px solid #eee;padding:6px;vertical-align:top"><?php echo htmlspecialchars($r['code_programme']); ?></td>
                <td style="border:1px solid #eee;padding:6px;vertical-align:top;white-space:nowrap"><?php echo htmlspecialchars($r['libelle']); ?></td>
                <td style="border:1px solid #eee;padding:6px;vertical-align:top;text-align:center">
                  <button class="showPayloadBtn" data-payload="<?php echo htmlspecialchars((string)$r['payload']); ?>">+</button>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <!-- Payload popup -->
        <div id="payloadPopup" style="display:none;position:fixed;left:0;top:0;width:100%;height:100%;background:rgba(0,0,0,0.45);z-index:4000;align-items:center;justify-content:center">
          <div style="background:#fff;padding:12px;border-radius:8px;max-width:90%;max-height:90%;overflow:auto;box-sizing:border-box">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
              <strong>Détails payload</strong>
              <button id="payloadClose" class="btn">Fermer</button>
            </div>
            <pre id="payloadContent" style="white-space:pre-wrap;font-family:monospace;font-size:0.9rem;margin:0"></pre>
          </div>
        </div>

        <script>
          (function(){
            var reload = document.getElementById('logsReload');
            if (reload) reload.addEventListener('click', function(){ location.reload(); });
            var popup = document.getElementById('payloadPopup');
            var content = document.getElementById('payloadContent');
            var closeBtn = document.getElementById('payloadClose');
            document.querySelectorAll('.showPayloadBtn').forEach(function(b){
              b.addEventListener('click', function(){
                var p = this.getAttribute('data-payload') || '';
                // set as text to preserve JSON formatting
                if (content) content.textContent = p;
                if (popup) popup.style.display = 'flex';
              });
            });
            if (closeBtn) closeBtn.addEventListener('click', function(){ if (popup) popup.style.display = 'none'; });
            // close when clicking outside
            if (popup) popup.addEventListener('click', function(e){ if (e.target === popup) popup.style.display = 'none'; });
          })();
        </script>
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
    resultEl.textContent = 'Synchronisation en cours';
    statsEl.style.display = 'none';
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
          var pendingEl = document.getElementById('pendingWrites');
          var pendingJson = document.getElementById('pendingJson');
          var pendingCount = document.getElementById('pendingCount');
          if (res.transactions_skipped && Array.isArray(res.skipped_transactions) && res.skipped_transactions.length > 0) {
            pendingCount.textContent = res.transactions_skipped + ' écriture(s) en attente (non enregistrées)';
            pendingJson.textContent = JSON.stringify(res.skipped_transactions, null, 2);
            pendingEl.style.display = 'block';
          } else {
            pendingEl.style.display = 'none';
            if (pendingJson) pendingJson.textContent = '';
            if (pendingCount) pendingCount.textContent = '';
          }
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
