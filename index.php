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
    // init daily buckets
    $daily = array_fill_keys($dates, 0.0);
    $txStmt = $pdo->prepare('SELECT booking_date, SUM(amount) as amt FROM transactions WHERE account_id = :aid AND booking_date BETWEEN :start AND :end GROUP BY booking_date');
    $txStmt->execute([':aid' => $acc['id'], ':start' => $dates[0], ':end' => $dates[count($dates)-1]]);
    while ($r = $txStmt->fetch(PDO::FETCH_ASSOC)) {
      $d = $r['booking_date'];
      if (isset($daily[$d])) $daily[$d] = (float)$r['amt'];
    }

    // cumulative series (activity) — build cumulative transactions then shift so final value equals current balance
    $cum = 0.0;
    $data = [];
    foreach ($dates as $d) {
      $cum += $daily[$d];
      $data[] = $cum;
    }
    // adjust so last point equals stored account balance (if available)
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

    // round values to 2 decimals for Chart clarity
    $data = array_map(function($v){ return round((float)$v, 2); }, $data);

    // prefer stored account color when available
    $color = null;
    $bg = null;
    if (!empty($acc['color'])) {
      $c = trim($acc['color']);
      // hex -> rgba
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
        // fallback to raw value as border, and use palette if bg needed
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
      // meta utile pour les tooltips
      'account' => ['id' => $acc['id'], 'name' => $acc['name'], 'balance' => (float)($acc['balance'] ?? 0), 'currency' => $acc['currency'] ?? '']
    ];
    $ci++;
  }
} catch (Throwable $e) {
  // en cas d'erreur, revenir à l'exemple simple pour éviter casse complète
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
    <canvas id="chart"></canvas>
  </section>

  <!-- Links removed: Voir transactions & Connecter une banque (now in Banque) -->
</main>

  <script src="assets/js/app.js"></script>
<script>
// Sync button removed from dashboard — sync is available via the 'Banque' screen

// Données réelles par compte pour Chart.js
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
    interaction: { mode: 'nearest', intersect: false },
    plugins: {
      tooltip: {
        callbacks: {
          title: function(items) { return items && items[0] ? items[0].label : ''; },
          label: function(context) {
            const ds = context.dataset || {};
            const acc = ds.account || {};
            const rawValue = context.parsed && context.parsed.y !== undefined ? context.parsed.y : context.formattedValue;
            const value = (rawValue !== null && rawValue !== undefined) ? Number(rawValue).toFixed(2) : rawValue;
            const date = context.label;
            // Use dataset label (which contains the account libellé) as primary label
            return [
              `Compte: ${ds.label || acc.name || acc.id}`,
              `Date: ${date}`,
              `Solde: ${value}`,
              `Solde actuel: ${acc.balance || ''} ${acc.currency || ''}`
            ];
          }
        }
      },
      legend: { position: 'bottom' }
    },
    scales: {
      x: { display: true },
      y: { display: true, beginAtZero: true }
    }
  }
});

// --- Dashboard chart controls ---
function populateCat1(){
  fetch('mon-site/api/agg.php?type=list_cats')
    .then(r=>r.json()).then(function(j){
      if (!Array.isArray(j)) return;
      var sel = document.getElementById('cat1Select'); sel.innerHTML = '<option value="all">— Tous —</option>';
      j.forEach(function(c){ var opt = document.createElement('option'); opt.value = c.id; opt.textContent = c.label; sel.appendChild(opt); });
    }).catch(()=>{});
}

// load cat2 for selected cat1
function populateCat2(cat1){
  var sel = document.getElementById('cat2Select'); sel.innerHTML = '<option value="all">— Tous —</option>';
  if (!cat1 || cat1==='all') return;
  fetch('mon-site/api/agg.php?type=list_cats2&cat1='+encodeURIComponent(cat1)).then(r=>r.json()).then(function(j){ if (!Array.isArray(j)) return; j.forEach(function(c){ var opt=document.createElement('option'); opt.value=c.id; opt.textContent=c.label; sel.appendChild(opt); }); }).catch(()=>{});
}

document.getElementById('chartType').addEventListener('change', function(){
  var t = this.value;
  var cat1Wrap = document.getElementById('cat1Wrap');
  var cat2Wrap = document.getElementById('cat2Wrap');
  var barModeWrap = document.getElementById('barModeWrap');
  if (t === 'category_month') { cat1Wrap.style.display='inline-block'; cat2Wrap.style.display='inline-block'; barModeWrap.style.display='inline-block'; } else { cat1Wrap.style.display='none'; cat2Wrap.style.display='none'; barModeWrap.style.display='none'; }
});

document.getElementById('cat1Select').addEventListener('change', function(){ populateCat2(this.value); });

document.getElementById('chartStyle').addEventListener('change', function(){ var wrap=document.getElementById('barModeWrap'); wrap.style.display = (this.value==='bar') ? 'inline-block' : 'none'; });

function refreshDashboardChart(){
  var type = document.getElementById('chartType').value;
  if (type === 'balance') {
    chart.data.labels = labels; chart.data.datasets = datasets.map(ds=>Object.assign({}, ds, {fill:false})); chart.options.type='line'; chart.update(); return;
  }
  // category_month
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
