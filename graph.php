<?php
// Dashboard moved to graph.php to keep a stable graph view while refactoring index.php
// Copied from original index.php
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

  $stmt = $pdo->query('SELECT id, name, currency, balance, color FROM accounts');
  $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $datasets = [];
  $palette = [
    'rgba(54,162,235,1)',
    'rgba(255,99,132,1)',
    'rgba(75,192,192,1)',
    'rgba(255,159,64,1)',
    'rgba(153,102,255,1)',
    'rgba(255,205,86,1)'
  ];
  $ci = 0;
  foreach ($accounts as $acc) {
    $daily = array_fill_keys($dates, 0.0);
    $txStmt = $pdo->prepare('SELECT booking_date, SUM(amount) as amt FROM transactions WHERE account_id = :aid AND booking_date BETWEEN :start AND :end GROUP BY booking_date');
    $txStmt->execute([':aid' => $acc['id'], ':start' => $dates[0], ':end' => $dates[count($dates)-1]]);
    while ($r = $txStmt->fetch(PDO::FETCH_ASSOC)) {
      $d = $r['booking_date'];
      if (isset($daily[$d])) $daily[$d] = (float)$r['amt'];
    }

    $cum = 0.0;
    $data = [];
    foreach ($dates as $d) {
      $cum += $daily[$d];
      $data[] = $cum;
    }
    $lastIndex = count($data) - 1;
    if ($lastIndex >= 0) {
      $last = $data[$lastIndex];
      $target = (float)($acc['balance'] ?? 0.0);
      $offset = $target - $last;
      if ($offset != 0.0) {
        foreach ($data as &$v) { $v = $v + $offset; }
        unset($v);
      }
    }

    $data = array_map(function($v){ return round((float)$v, 2); }, $data);

    $color = null;
    $bg = null;
    if (!empty($acc['color'])) {
      $c = trim($acc['color']);
      if (preg_match('/^#([0-9a-fA-F]{6})$/', $c, $m)) {
        $hex = $m[1];
        $r = hexdec(substr($hex,0,2));
        $g = hexdec(substr($hex,2,2));
        $b = hexdec(substr($hex,4,2));
        $color = $c;
        $bg = "rgba($r,$g,$b,0.15)";
      } elseif (strpos($c, 'rgba(') === 0) {
        $color = $c;
        $bg = preg_replace('/,\s*([0-9\.]+)\)$/', ',0.15)', $c);
      } elseif (strpos($c, 'rgb(') === 0) {
        $color = preg_replace('/rgb\(/','rgba(',$c) . ',0.15)';
        $bg = preg_replace('/rgb\(([^)]+)\)/','rgba($1,0.15)',$c);
      } else {
        $color = $c;
      }
    }
    if ($color === null) {
      $color = $palette[$ci % count($palette)];
      $bg = preg_replace('/1\)$/','0.15)',$color);
    }

    $datasets[] = [
      'label' => ($acc['name'] ?? $acc['id']) . ' (' . ($acc['currency'] ?? '') . ')',
      'borderColor' => $color,
      'backgroundColor' => $bg,
      'data' => $data,
      'tension' => 0.2,
      'pointRadius' => 3,
      'account' => ['id' => $acc['id'], 'name' => $acc['name'], 'balance' => (float)($acc['balance'] ?? 0), 'currency' => $acc['currency'] ?? '']
    ];
    $ci++;
  }
} catch (Throwable $e) {
  $labels = ['J-6','J-5','J-4','J-3','J-2','J-1','Aujourd\'hui'];
  $datasets = [[
    'label' => 'Solde (exemple)',
    'backgroundColor' => 'rgba(54,162,235,0.2)',
    'borderColor' => 'rgba(54,162,235,1)',
    'data' => [1000,1200,1100,1300,1250,1400,(float)$total]
  ]];
}

?><!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>bkTool - Dashboard</title>
  <link rel="manifest" href="manifest.json">
  <link rel="stylesheet" href="assets/css/style.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    *,*::before,*::after{box-sizing:border-box}
    html,body{height:100%;}
    body{display:flex;flex-direction:column;min-height:100vh;margin:0;overflow-x:hidden}
    main{flex:1;display:flex;flex-direction:column;padding:12px}
    /* chart section: center the chart and limit its size to 90% width / 60% viewport height */
    main > section:last-of-type{display:flex;justify-content:center;align-items:center}
    #chartWrapper{width:90%;height:60vh;display:flex;align-items:stretch;overflow:hidden;margin:0 auto}
    #chart{display:block;max-width:100% !important;width:100% !important;height:100% !important}
  </style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>
<main>
  <section>
    <h2>Solde total : <?php echo htmlspecialchars((string)$total); ?> </h2>
  </section>

  <section>
    <h3>Historique (exemple)</h3>
    <div style="display:flex;gap:12px;align-items:center;margin-bottom:8px">
      <label>Type de graphique:
        <select id="chartType">
          <option value="balance">Solde compte</option>
          <option value="category_month">Graphique mensuel sur une catégorie</option>
        </select>
      </label>
      <label id="cat1Wrap" style="display:none">Catégorie:
        <select id="cat1Select"><option value="all">— Tous —</option></select>
      </label>
      <label id="cat2Wrap" style="display:none">Niveau 1 sélectionné -> Niveau 2:
        <select id="cat2Select"><option value="all">— Tous —</option></select>
      </label>
      <label style="margin-left:12px">Type:
        <select id="chartStyle"><option value="line">Graph lignes</option><option value="bar">Graph à barres mensuelles</option></select>
      </label>
      <label style="margin-left:8px;display:none" id="barModeWrap">Mode barres:
        <select id="barMode"><option value="cumulative">Cumuler</option><option value="split">Séparer débit/crédit</option></select>
      </label>
      <button id="chartRefresh">Rafraîchir</button>
    </div>
    <div id="chartWrapper"><canvas id="chart"></canvas></div>
  </section>

  <!-- Links removed: Voir transactions & Connecter une banque (now in Banque) -->
</main>

  <script src="assets/js/app.js"></script>
<script>
const labels = <?php echo json_encode($labels); ?>;
const datasets = <?php echo json_encode($datasets); ?>;
const ctx = document.getElementById('chart').getContext('2d');
const chart = new Chart(ctx, {
  type: 'line',
  data: {
    labels: labels,
    datasets: datasets.map(ds => Object.assign({}, ds, { fill: false }))
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    scales: {
      x: { display: true },
      y: { display: true }
    }
  }
});
</script>
</body>
</html>
