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

// Préparer les données du graphique: dernières N journées
try {
  $days = 30;
  $dates = [];
  for ($i = $days - 1; $i >= 0; $i--) {
    $d = new DateTime("-$i days");
    $dates[] = $d->format('Y-m-d');
  }
  $labels = array_map(function($d){ $dt = DateTime::createFromFormat('Y-m-d', $d); return $dt->format('d/m'); }, $dates);
<?php
// Front-controller index.php (PWA navigation refactor start on main)
// If no ?page= is provided, display header + terms.txt only.

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$page = isset($_GET['page']) && is_string($_GET['page']) ? trim($_GET['page']) : '';

if ($page === '') {
  // show minimal home: header + terms
  include __DIR__ . '/header.php';
  echo "<main style=\"padding:12px\">";
  $termsFile = __DIR__ . '/terms.txt';
  if (is_readable($termsFile)) {
    $terms = file_get_contents($termsFile);
    echo '<section><h2>Conditions / Mentions</h2><pre style="white-space:pre-wrap">' . htmlspecialchars($terms) . '</pre></section>';
  } else {
    echo '<section><p>Fichier terms.txt introuvable.</p></section>';
  }
  echo "</main>";
  exit;
}

// Simple whitelist router for now — extend as needed
$whitelist = [
  'graph' => 'graph.php',
  'synchro' => 'synchsmart.php',
  'transactions' => 'transactions.php',
  'categories' => 'categories.php',
  'banque' => 'choix.php',
  'mobile' => 'mobile.php'
];

if (isset($whitelist[$page])) {
  include __DIR__ . '/' . $whitelist[$page];
  exit;
}

// unknown page
include __DIR__ . '/header.php';
http_response_code(404);
echo '<main style="padding:12px"><h2>Page introuvable</h2><p>Page "' . htmlspecialchars($page) . '" non reconnue.</p></main>';
exit;
  var cat1 = document.getElementById('cat1Select').value || 'all';
  var cat2 = document.getElementById('cat2Select').value || 'all';
  fetch('mon-site/api/agg.php?type=category_month&cat1='+encodeURIComponent(cat1)+'&cat2='+encodeURIComponent(cat2)+'&months=12')
    .then(r=>r.json()).then(function(j){ if (j.error) { console.error(j); return; } chart.data.labels = j.labels; chart.data.datasets = j.datasets.map(function(ds,i){ return { label: ds.label, data: ds.data, fill: false }; }); chart.update(); }).catch(console.error);
}

document.getElementById('chartRefresh').addEventListener('click', refreshDashboardChart);
// initialize categories list on load
populateCat1();
</script>
</body>
</html>
