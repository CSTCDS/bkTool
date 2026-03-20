<?php
// Transactions — affichage avec bandeau, filtre par compte, traduction FR
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

try {
  $pdo = require __DIR__ . '/mon-site/api/db.php';
} catch (Throwable $e) {
  echo '<h1>Erreur BDD</h1><pre>' . htmlspecialchars((string)$e) . '</pre>';
  exit;
}

// Liste des comptes pour le dropdown
$accs = $pdo->query('SELECT id, name FROM accounts ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$accMap = [];
foreach ($accs as $a) { $accMap[$a['id']] = $a['name']; }

// Palette de couleurs identique au graphe du Dashboard
$palette = [
  'rgba(54,162,235,0.18)',
  'rgba(255,99,132,0.18)',
  'rgba(75,192,192,0.18)',
  'rgba(255,159,64,0.18)',
  'rgba(153,102,255,0.18)',
  'rgba(255,205,86,0.18)'
];
$accColorMap = [];
$ci = 0;
foreach ($accs as $a) {
  $accColorMap[$a['id']] = $palette[$ci % count($palette)];
  $ci++;
}

// Noms des critères
$criterionNames = [];
for ($i = 1; $i <= 4; $i++) {
  $s = $pdo->prepare('SELECT `value` FROM settings WHERE `key` = :k');
  $s->execute([':k' => "criterion_{$i}_name"]);
  $criterionNames[$i] = $s->fetchColumn() ?: "Critère $i";
}

// Catégories hiérarchiques pour les 4 critères
$allCats = $pdo->query('SELECT * FROM categories ORDER BY criterion, sort_order, label')->fetchAll(PDO::FETCH_ASSOC);
$catTree = []; // criterion => [ parentId => ['info'=>..., 'children'=>[...]] ]
foreach ($allCats as $c) {
  $cr = $c['criterion'];
  if (!isset($catTree[$cr])) $catTree[$cr] = [];
  if ($c['parent_id'] === null) {
    if (!isset($catTree[$cr][$c['id']])) $catTree[$cr][$c['id']] = ['info' => $c, 'children' => []];
    else $catTree[$cr][$c['id']]['info'] = $c;
  } else {
    if (!isset($catTree[$cr][$c['parent_id']])) $catTree[$cr][$c['parent_id']] = ['info' => null, 'children' => []];
    $catTree[$cr][$c['parent_id']]['children'][] = $c;
  }
}
// Flat map id=>label for quick lookup
$catLabels = [];
foreach ($allCats as $c) { $catLabels[$c['id']] = $c['label']; }

$where = [];
$params = [];
if (!empty($_GET['account'])) { $where[] = 't.account_id = :account'; $params[':account'] = $_GET['account']; }
if (!empty($_GET['from']))    { $where[] = 't.booking_date >= :from';  $params[':from'] = $_GET['from']; }
if (!empty($_GET['to']))      { $where[] = 't.booking_date <= :to';    $params[':to'] = $_GET['to']; }

$sql = 'SELECT t.*, a.name AS account_name FROM transactions t LEFT JOIN accounts a ON a.id = t.account_id';
if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
$sql .= ' ORDER BY FIELD(t.status, \'pending\', \'booked\'), t.booking_date DESC, a.name ASC LIMIT 1000';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$txs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Export CSV
if (!empty($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="transactions.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Compte','Date','Montant','Devise','Description']);
    foreach ($txs as $t) {
        fputcsv($out, [$t['account_name'] ?? $t['account_id'], $t['booking_date'], $t['amount'], $t['currency'], $t['description']]);
    }
    exit;
}
?><!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Transactions — bkTool</title>
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="site-header">
  <div class="site-title">bkTool</div>
  <nav class="tabs">
    <a href="index.php">Dashboard</a>
    <a href="accounts.php">Comptes</a>
    <a href="transactions.php" class="active">Transactions</a>
    <a href="categories.php">Catégories</a>
    <a href="choix.php">Connecter banque</a>
  </nav>
</div>
<main class="full-width">

  <h1>Transactions</h1>

  <form method="get" style="margin-bottom:16px;display:flex;gap:12px;flex-wrap:wrap;align-items:end">
    <label>Compte :
      <select name="account" onchange="this.form.submit()">
        <option value="">— Tous —</option>
        <?php foreach ($accs as $a): ?>
          <option value="<?php echo htmlspecialchars($a['id']); ?>" <?php echo (($_GET['account'] ?? '') === $a['id']) ? 'selected' : ''; ?>>
            <?php echo htmlspecialchars($a['name'] ?: $a['id']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Du : <input type="date" name="from" value="<?php echo htmlspecialchars($_GET['from'] ?? ''); ?>"></label>
    <label>Au : <input type="date" name="to" value="<?php echo htmlspecialchars($_GET['to'] ?? ''); ?>"></label>
    <button type="submit">Filtrer</button>
    <button type="submit" name="export" value="csv">Exporter CSV</button>
  </form>

  <table class="tx-table">
    <thead>
      <tr><th class="col-compte">Compte</th><th class="col-date">Date</th><th class="col-montant">Montant</th><th class="col-devise">Devise</th><th class="col-desc">Commentaire</th><?php for($i=1;$i<=4;$i++): ?><th class="col-cat"><?php echo htmlspecialchars($criterionNames[$i]); ?></th><?php endfor; ?></tr>
    </thead>
    <tbody>
    <?php foreach ($txs as $t):
      $isPending = (($t['status'] ?? 'booked') === 'pending');
    ?>
      <tr<?php if ($isPending) echo ' class="row-pending"'; ?>>
        <td class="col-compte" style="background:<?php echo $accColorMap[$t['account_id']] ?? 'transparent'; ?>"><?php echo htmlspecialchars($t['account_name'] ?? $t['account_id']); ?></td>
        <td class="col-date"><?php echo htmlspecialchars((string)($t['booking_date'] ?? '')); if ($isPending) echo ' <span class="badge-pending">en attente</span>'; ?></td>
        <td class="col-montant" style="<?php echo ($t['amount'] < 0) ? 'color:#c62828' : 'color:#2e7d32'; ?>"><?php echo htmlspecialchars(number_format((float)$t['amount'], 2, ',', ' ')); ?></td>
        <td class="col-devise"><?php echo htmlspecialchars((string)($t['currency'] ?? '')); ?></td>
        <td class="col-desc"><?php echo htmlspecialchars((string)($t['description'] ?? '')); ?></td>
        <?php for ($ci2 = 1; $ci2 <= 4; $ci2++):
          $field = "cat{$ci2}_id";
          $curVal = $t[$field] ?? null;
        ?>
        <td class="col-cat">
          <select class="cat-select" data-txid="<?php echo htmlspecialchars($t['id']); ?>" data-field="<?php echo $field; ?>" title="<?php echo htmlspecialchars($criterionNames[$ci2]); ?>">
            <option value="">—</option>
            <?php if (!empty($catTree[$ci2])): foreach ($catTree[$ci2] as $pid => $node): if (!$node['info']) continue; ?>
              <optgroup label="<?php echo htmlspecialchars($node['info']['label']); ?>">
                <option value="<?php echo $node['info']['id']; ?>" <?php echo ((int)$curVal === (int)$node['info']['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($node['info']['label']); ?></option>
                <?php foreach ($node['children'] as $child): ?>
                  <option value="<?php echo $child['id']; ?>" <?php echo ((int)$curVal === (int)$child['id']) ? 'selected' : ''; ?>>&nbsp;&nbsp;<?php echo htmlspecialchars($child['label']); ?></option>
                <?php endforeach; ?>
              </optgroup>
            <?php endforeach; endif; ?>
          </select>
        </td>
        <?php endfor; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</main>
<script>
// Save category selection via AJAX
document.querySelectorAll('.cat-select').forEach(function(sel) {
  sel.addEventListener('change', function() {
    var data = new FormData();
    data.append('tx_id', this.dataset.txid);
    data.append('field', this.dataset.field);
    data.append('value', this.value);
    fetch('save_tx_category.php', { method: 'POST', body: data })
      .then(function(r) { return r.json(); })
      .then(function(j) {
        if (!j.ok) console.error('Erreur save category', j);
      })
      .catch(function(e) { console.error(e); });
  });
});
</script>
</body>
</html>
