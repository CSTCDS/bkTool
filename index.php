<?php
// Root homepage for bkTool — moved from mon-site/public/index.php
// This file lives at C:\Perso\LWS\bkTool\index.php and expects the API and config
// to remain in mon-site/api and mon-site/config (which stay non-public).

// Temporary debug: enable error display for diagnosis (remove in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

try {
  $pdo = require __DIR__ . '/mon-site/api/db.php';
} catch (Throwable $e) {
  echo '<h1>Erreur BDD</h1><pre>' . htmlspecialchars((string)$e) . '</pre>';
  exit;
}

try {
  $stmt = $pdo->query('SELECT SUM(balance) as total FROM accounts');
  $row = $stmt->fetch();
  $total = $row['total'] ?? 0;
} catch (Throwable $e) {
  echo '<h1>Erreur requête</h1><pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
  exit;
}

?><!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>bkTool - Dashboard</title>
  <link rel="manifest" href="/manifest.json">
  <link rel="stylesheet" href="/assets/css/style.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
<main>
  <h1>bkTool — Dashboard</h1>
  <section>
    <h2>Solde total : <?php echo htmlspecialchars($total); ?> </h2>
  </section>

  <section>
    <h3>Historique (exemple)</h3>
    <canvas id="chart"></canvas>
  </section>

  <p><a href="/transactions.php">Voir transactions</a></p>
  <p>
    <button id="syncBtn">Synchroniser maintenant</button>
    <span id="syncStatus"></span>
  </p>
</main>

<script src="/assets/js/app.js"></script>
<script>
document.getElementById('syncBtn').addEventListener('click', function(){
  const status = document.getElementById('syncStatus');
  status.textContent = '… synchronisation en cours';
  fetch('/sync.php', { method: 'GET' })
    .then(r => r.json())
    .then(j => {
      if (j.status === 'ok') status.textContent = `OK comptes=${j.result.accounts} tx=${j.result.transactions}`;
      else status.textContent = 'Erreur: ' + (j.message||'');
    }).catch(e => { status.textContent = 'Erreur: '+e; });
});

// Exemple de données pour Chart.js
const ctx = document.getElementById('chart').getContext('2d');
const chart = new Chart(ctx, {
  type: 'line',
  data: {
    labels: ['J-6','J-5','J-4','J-3','J-2','J-1','Aujourd\'hui'],
    datasets: [{
      label: 'Solde',
      backgroundColor: 'rgba(54,162,235,0.2)',
      borderColor: 'rgba(54,162,235,1)',
      data: [1000, 1200, 1100, 1300, 1250, 1400, <?php echo (float)$total; ?>]
    }]
  }
});
</script>
</body>
</html>
