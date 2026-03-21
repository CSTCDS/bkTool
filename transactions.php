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

// Liste des comptes pour le dropdown (inclut le solde courant)
$accs = $pdo->query('SELECT id, name, balance, color FROM accounts ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$accMap = [];
$accBalances = [];
foreach ($accs as $a) { $accMap[$a['id']] = $a['name']; $accBalances[$a['id']] = (float)($a['balance'] ?? 0); }

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
    $c = trim((string)($a['color'] ?? ''));
    $bg = null;
    if ($c !== '') {
      // If stored as hex or rgb(a), prefer using it directly as background
      if (preg_match('/^#([0-9a-fA-F]{6})$/', $c)) {
        $bg = $c; // use hex as-is
      } elseif (strpos($c, 'rgb') === 0) {
        $bg = $c; // rgb(...) or rgba(...)
      } else {
        // fallback to using raw value
        $bg = $c;
      }
    }
    if ($bg === null) {
      // fallback to palette with light alpha
      $bg = $palette[$ci % count($palette)];
    }
    $accColorMap[$a['id']] = $bg;
    $ci++;
  }

// Noms des critères
$criterionNames = [];
for ($i = 1; $i <= 4; $i++) {
  $s = $pdo->prepare('SELECT `value` FROM settings WHERE `key` = :k');
  $s->execute([':k' => "criterion_{$i}_name"]);
  $criterionNames[$i] = $s->fetchColumn() ?: "Critère $i";
}

// Catégories hiérarchiques pour les 4 critères + regroupements (criterion=0)
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

// Build group children map (criterion=0): parent_id => [account_id, ...]
$groupChildren = [];
foreach ($allCats as $c) {
  if ((int)$c['criterion'] === 0 && $c['parent_id'] !== null) {
    $groupChildren[(int)$c['parent_id']][] = $c['label'];
  }
}

// Map parent_id => [child ids] for level-1 filter expansion
$catChildrenIds = [];
foreach ($allCats as $c) {
  if ($c['parent_id'] !== null) {
    $catChildrenIds[(int)$c['parent_id']][] = (int)$c['id'];
  }
}

$where = [];
$params = [];
// Account selection: supports single account id or group selection with prefix 'g:'
if (!empty($_GET['account'])) {
  $acctSel = $_GET['account'];
  if (is_string($acctSel) && strpos($acctSel, 'g:') === 0) {
    $gid = (int)substr($acctSel, 2);
    $acctIds = $groupChildren[$gid] ?? [];
    if (!empty($acctIds)) {
      $placeholders = [];
      foreach ($acctIds as $idx => $aid) {
        $ph = ':g_' . $gid . '_' . $idx;
        $placeholders[] = $ph;
        $params[$ph] = $aid;
      }
      $where[] = 't.account_id IN (' . implode(',', $placeholders) . ')';
    }
  } else {
    $where[] = 't.account_id = :account';
    $params[':account'] = $acctSel;
  }
}
if (!empty($_GET['from']))    { $where[] = 't.booking_date >= :from';  $params[':from'] = $_GET['from']; }
if (!empty($_GET['to']))      { $where[] = 't.booking_date <= :to';    $params[':to'] = $_GET['to']; }

// Paramètre: type sélectionné (group ou crit 1..4)
$paramType = $_GET['param_type'] ?? '';
if ($paramType === 'group' && !empty($_GET['fgroup'])) {
  $gid = (int)$_GET['fgroup'];
  $acctIds = $groupChildren[$gid] ?? [];
  if (!empty($acctIds)) {
    $placeholders = [];
    foreach ($acctIds as $idx => $aid) {
      $ph = ':g_' . $gid . '_' . $idx;
      $placeholders[] = $ph;
      $params[$ph] = $aid;
    }
    $where[] = 't.account_id IN (' . implode(',', $placeholders) . ')';
  }
}

// Filtres par critères (cat1..cat4)
for ($fi = 1; $fi <= 4; $fi++) {
  $filterKey = "fcat{$fi}";
  $filterVal = $_GET[$filterKey] ?? '';
  if ($filterVal !== '') {
    $catId = (int)$filterVal;
    // Check if it's a level-1 category (has children) => include parent + all children
    $ids = [$catId];
    if (!empty($catChildrenIds[$catId])) {
      $ids = array_merge($ids, $catChildrenIds[$catId]);
    }
    $placeholders = [];
    foreach ($ids as $idx => $id) {
      $ph = ":fcat{$fi}_{$idx}";
      $placeholders[] = $ph;
      $params[$ph] = $id;
    }
    $where[] = "t.cat{$fi}_id IN (" . implode(',', $placeholders) . ")";
  }
}

$sql = 'SELECT t.*, a.name AS account_name FROM transactions t LEFT JOIN accounts a ON a.id = t.account_id';
if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
 $sql .= ' ORDER BY t.booking_date DESC, t.amount DESC LIMIT 1000';

 $stmt = $pdo->prepare($sql);
 $stmt->execute($params);
 $txs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Detecter absence de filtres de dates — si vrai, on affichera la colonne "Solde"
$noDateFilter = empty($_GET['from']) && empty($_GET['to']);

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
    <a href="transactions.php" class="active">Transactions</a>
    <a href="categories.php">Paramètres</a>
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
        <?php if (!empty($catTree[0])): foreach ($catTree[0] as $pid => $node): if (!$node['info']) continue; ?>
          <option value="g:<?php echo (int)$node['info']['id']; ?>" <?php echo (($_GET['account'] ?? '') === ('g:' . (int)$node['info']['id'])) ? 'selected' : ''; ?> style="background:#e0e0e0">
            <?php echo 'G: ' . htmlspecialchars($node['info']['label']); ?>
          </option>
        <?php endforeach; endif; ?>
      </select>
    </label>
    <label>Du : <input type="date" name="from" value="<?php echo htmlspecialchars($_GET['from'] ?? ''); ?>"></label>
    <label>Au : <input type="date" name="to" value="<?php echo htmlspecialchars($_GET['to'] ?? ''); ?>"></label>
    <?php for ($fi = 1; $fi <= 4; $fi++):
      $filterKey = "fcat{$fi}";
      $filterVal = $_GET[$filterKey] ?? '';
    ?>
    <label><?php echo htmlspecialchars($criterionNames[$fi]); ?> :
      <select name="<?php echo $filterKey; ?>" onchange="this.form.submit()">
        <option value="">— Tous —</option>
        <?php if (!empty($catTree[$fi])): foreach ($catTree[$fi] as $pid => $node): if (!$node['info']) continue; ?>
          <optgroup label="<?php echo htmlspecialchars($node['info']['label']); ?>">
            <option value="<?php echo $node['info']['id']; ?>" <?php echo ($filterVal !== '' && (int)$filterVal === (int)$node['info']['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($node['info']['label']); ?> (tout)</option>
            <?php foreach ($node['children'] as $child): ?>
              <option value="<?php echo $child['id']; ?>" <?php echo ($filterVal !== '' && (int)$filterVal === (int)$child['id']) ? 'selected' : ''; ?>>&nbsp;&nbsp;<?php echo htmlspecialchars($child['label']); ?></option>
            <?php endforeach; ?>
          </optgroup>
        <?php endforeach; endif; ?>
      </select>
    </label>
    <?php endfor; ?>
    <button type="submit">Filtrer</button>
    <button type="submit" name="export" value="csv">Exporter CSV</button>
  </form>

  <table class="tx-table">
    <thead>
      <tr>
        <th class="col-compte" style="width:8%">Compte</th>
        <th class="col-date" style="width:8%">Date</th>
        <th class="col-montant" style="width:8%">Montant</th>
        <th class="col-devise" style="width:5%">Devise</th>
        <th class="col-desc" style="width:35%">Commentaire</th>
        <th class="col-categories" style="width:28%">Catégories</th>
        <?php if ($noDateFilter): ?><th class="col-solde" style="width:8%">Solde</th><?php endif; ?>
      </tr>
    </thead>
    <tbody>
    <?php
    // Pour calculer le solde courant par compte, on itère dans l'ordre affiché
    $runningAcc = [];
    foreach ($txs as $t):
      // Consider BOOK as booked/current; any other status treated as pending
      $isPending = (strtoupper((string)($t['status'] ?? '')) !== 'BOOK');
      $acctId = $t['account_id'];
      if (!isset($runningAcc[$acctId])) $runningAcc[$acctId] = 0.0; // cumul des montants déjà vus (newest->oldest)
      $startBal = $accBalances[$acctId] ?? 0.0;
      // solde affiché = solde courant du compte - cumul des montants précédents
      $displayBalance = $startBal - $runningAcc[$acctId];
    ?>
      <tr<?php if ($isPending) echo ' class="row-pending"'; ?>>
        <td class="col-compte" style="background:<?php echo $accColorMap[$t['account_id']] ?? 'transparent'; ?>; "><?php echo htmlspecialchars($t['account_name'] ?? $t['account_id']); ?></td>
        <td class="col-date"><?php if ($isPending) echo '<span class="badge-pending">en attente</span><br>'; ?><?php echo htmlspecialchars((string)($t['booking_date'] ?? '')); ?></td>
        <td class="col-montant" style="<?php echo ($t['amount'] < 0) ? 'color:#c62828' : 'color:#2e7d32'; ?>"><?php echo htmlspecialchars(number_format((float)$t['amount'], 2, ',', ' ')); ?></td>
        <td class="col-devise"><?php echo htmlspecialchars((string)($t['currency'] ?? '')); ?></td>
        <td class="col-desc"><?php echo htmlspecialchars((string)($t['description'] ?? '')); ?></td>
        <td class="col-categories">
          <div style="display:flex;flex-direction:column;gap:6px">
            <div style="display:flex;gap:8px">
              <?php // Catégorie 1 and 2 on first line ?>
              <?php for ($ci2 = 1; $ci2 <= 2; $ci2++):
                $field = "cat{$ci2}_id";
                $curVal = $t[$field] ?? null;
              ?>
                <div style="flex:1">
                  <select class="cat-select" data-txid="<?php echo htmlspecialchars($t['id']); ?>" data-field="<?php echo $field; ?>" title="<?php echo htmlspecialchars($criterionNames[$ci2]); ?>" style="width:100%">
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
                </div>
              <?php endfor; ?>
            </div>

            <div style="display:flex;gap:8px">
              <?php // Catégorie 3 and 4 on second line ?>
              <?php for ($ci2 = 3; $ci2 <= 4; $ci2++):
                $field = "cat{$ci2}_id";
                $curVal = $t[$field] ?? null;
              ?>
                <div style="flex:1">
                  <select class="cat-select" data-txid="<?php echo htmlspecialchars($t['id']); ?>" data-field="<?php echo $field; ?>" title="<?php echo htmlspecialchars($criterionNames[$ci2]); ?>" style="width:100%">
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
                </div>
              <?php endfor; ?>
            </div>
          </div>
        </td>
        <?php if ($noDateFilter): ?><td class="col-solde"><?php echo htmlspecialchars(number_format($displayBalance, 2, ',', ' ')); ?></td><?php endif; ?>
      </tr>
    <?php
      // mettre à jour cumul pour ce compte (après affichage)
      $runningAcc[$acctId] += (float)$t['amount'];
    endforeach;
    ?>
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
