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
// group selection helper
$groupSelected = false;
$groupAccountIds = [];

// remember account selection from cookie if GET not provided
$acctSel = $_GET['account'] ?? ($_COOKIE['selected_account'] ?? '');
// Account selection: supports single account id or group selection with prefix 'g:'
if (!empty($acctSel)) {
  if (is_string($acctSel) && strpos($acctSel, 'g:') === 0) {
    $gid = (int)substr($acctSel, 2);
    $acctIds = $groupChildren[$gid] ?? [];
    $groupAccountIds = $acctIds;
    if (!empty($acctIds)) {
      $groupSelected = true;
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

// If a group is selected, compute the group's starting total balance
$groupStartBalance = 0.0;
if ($groupSelected && !empty($groupAccountIds)) {
  foreach ($groupAccountIds as $aid) {
    $groupStartBalance += (float)($accBalances[$aid] ?? 0.0);
  }
}

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
<?php include __DIR__ . '/header.php'; ?>
<main class="full-width">
<?php
$selectedQuickRange = $_GET['quickRange'] ?? ($_COOKIE['selected_quickRange'] ?? '');
$dateFieldsVisible = ($selectedQuickRange === 'custom') ? '' : 'display:none';
?>
  <form method="get" class="tx-filter-header" style="margin-bottom:16px">
    <div class="tx-header-row" style="display:flex;gap:12px;align-items:center;margin-bottom:8px">
      <div class="tx-col tx-left" style="flex:1">
        <h1 style="margin:0">Transactions</h1>
      </div>
      <div class="tx-col tx-center" style="flex:1;text-align:center">
        <div style="display:flex;flex-direction:column;align-items:center">
          <select id="quickRange" name="quickRange">
            <option value=""<?php echo ($selectedQuickRange === '') ? ' selected' : ''; ?>>Sélection Temporelle</option>
            <option value="10d"<?php echo ($selectedQuickRange === '10d') ? ' selected' : ''; ?>>10 derniers jours</option>
            <option value="20d"<?php echo ($selectedQuickRange === '20d') ? ' selected' : ''; ?>>20 derniers jours</option>
            <option value="1m"<?php echo ($selectedQuickRange === '1m') ? ' selected' : ''; ?>>1 mois</option>
            <option value="2m"<?php echo ($selectedQuickRange === '2m') ? ' selected' : ''; ?>>2 mois</option>
            <option value="6m"<?php echo ($selectedQuickRange === '6m') ? ' selected' : ''; ?>>6 mois</option>
            <option value="1y"<?php echo ($selectedQuickRange === '1y') ? ' selected' : ''; ?>>1 an</option>
            <option value="2y"<?php echo ($selectedQuickRange === '2y') ? ' selected' : ''; ?>>2 ans</option>
            <option value="custom"<?php echo ($selectedQuickRange === 'custom') ? ' selected' : ''; ?>>Choix de dates</option>
          </select>
        </div>
      </div>
      <div class="tx-col tx-right" style="flex:1;text-align:right;display:flex;gap:8px;justify-content:flex-end">
        <!-- Critères 1 & 2 (ligne 1, droite) -->
        <div>
          <select name="fcat1" onchange="this.form.submit()">
            <option value=""><?php echo htmlspecialchars($criterionNames[1]); ?></option>
            <?php if (!empty($catTree[1])): foreach ($catTree[1] as $pid => $node): if (!$node['info']) continue; ?>
              <optgroup label="<?php echo htmlspecialchars($node['info']['label']); ?>">
                <?php foreach ($node['children'] as $child): ?>
                  <option value="<?php echo $child['id']; ?>" <?php echo ((int)($_GET['fcat1'] ?? '') === (int)$child['id']) ? 'selected' : ''; ?>>&nbsp;&nbsp;<?php echo htmlspecialchars($child['label']); ?></option>
                <?php endforeach; ?>
              </optgroup>
            <?php endforeach; endif; ?>
          </select>
        </div>
        <div>
          <select name="fcat2" onchange="this.form.submit()">
            <option value=""><?php echo htmlspecialchars($criterionNames[2]); ?></option>
            <?php if (!empty($catTree[2])): foreach ($catTree[2] as $pid => $node): if (!$node['info']) continue; ?>
              <optgroup label="<?php echo htmlspecialchars($node['info']['label']); ?>">
                <?php foreach ($node['children'] as $child): ?>
                  <option value="<?php echo $child['id']; ?>" <?php echo ((int)($_GET['fcat2'] ?? '') === (int)$child['id']) ? 'selected' : ''; ?>>&nbsp;&nbsp;<?php echo htmlspecialchars($child['label']); ?></option>
                <?php endforeach; ?>
              </optgroup>
            <?php endforeach; endif; ?>
          </select>
        </div>
      </div>
    </div>

    <div class="tx-header-row" style="display:flex;gap:12px;align-items:center">
      <div class="tx-col tx-left" style="flex:1">
        <label>Sélection de compte :
          <select name="account" class="select-account" onchange="document.cookie='selected_account='+encodeURIComponent(this.value)+';path=/;max-age=31536000'; this.form.submit()">
            <option value="">— Tous —</option>
            <?php foreach ($accs as $a): ?>
              <option value="<?php echo htmlspecialchars($a['id']); ?>" <?php echo ($acctSel !== '' && (string)$acctSel === (string)$a['id']) ? 'selected' : ''; ?> >
                <?php echo htmlspecialchars($a['name'] ?: $a['id']); ?>
              </option>
            <?php endforeach; ?>
            <?php if (!empty($catTree[0])): foreach ($catTree[0] as $pid => $node): if (!$node['info']) continue; ?>
              <option value="g:<?php echo (int)$node['info']['id']; ?>" <?php echo ($acctSel !== '' && (string)$acctSel === ('g:' . (int)$node['info']['id'])) ? 'selected' : ''; ?> style="background:#e0e0e0">
                <?php echo 'G: ' . htmlspecialchars($node['info']['label']); ?>
              </option>
            <?php endforeach; endif; ?>
          </select>
        </label>
      </div>
      <div class="tx-col tx-center" style="flex:1;text-align:center">
        <div id="dateRangeFields" style="<?php echo $dateFieldsVisible; ?>">
          <label>Du : <input type="date" name="from" value="<?php echo htmlspecialchars($_GET['from'] ?? ($_COOKIE['selected_from'] ?? '')); ?>"></label>
          <label>Au : <input type="date" name="to" value="<?php echo htmlspecialchars($_GET['to'] ?? ($_COOKIE['selected_to'] ?? '')); ?>"></label>
        </div>
      </div>
      <div class="tx-col tx-right" style="flex:1;text-align:right;display:flex;gap:8px;justify-content:flex-end">
        <!-- Critères 3 & 4 (ligne 2, droite) -->
        <div>
          <select name="fcat3" onchange="this.form.submit()">
            <option value=""><?php echo htmlspecialchars($criterionNames[3]); ?></option>
            <?php if (!empty($catTree[3])): foreach ($catTree[3] as $pid => $node): if (!$node['info']) continue; ?>
              <optgroup label="<?php echo htmlspecialchars($node['info']['label']); ?>">
                <?php foreach ($node['children'] as $child): ?>
                  <option value="<?php echo $child['id']; ?>" <?php echo ((int)($_GET['fcat3'] ?? '') === (int)$child['id']) ? 'selected' : ''; ?>>&nbsp;&nbsp;<?php echo htmlspecialchars($child['label']); ?></option>
                <?php endforeach; ?>
              </optgroup>
            <?php endforeach; endif; ?>
          </select>
        </div>
        <div>
          <select name="fcat4" onchange="this.form.submit()">
            <option value=""><?php echo htmlspecialchars($criterionNames[4]); ?></option>
            <?php if (!empty($catTree[4])): foreach ($catTree[4] as $pid => $node): if (!$node['info']) continue; ?>
              <optgroup label="<?php echo htmlspecialchars($node['info']['label']); ?>">
                <?php foreach ($node['children'] as $child): ?>
                  <option value="<?php echo $child['id']; ?>" <?php echo ((int)($_GET['fcat4'] ?? '') === (int)$child['id']) ? 'selected' : ''; ?>>&nbsp;&nbsp;<?php echo htmlspecialchars($child['label']); ?></option>
                <?php endforeach; ?>
              </optgroup>
            <?php endforeach; endif; ?>
          </select>
        </div>
      </div>
    </div>
  </form>

  <table class="tx-table">
    <thead>
      <tr>
        <th class="col-compte" style="width:8%">Compte</th>
        <th class="col-date" style="width:8%">Date</th>
        <th class="col-montant" style="width:8%">Montant</th>
        <th class="col-devise" style="width:5%">Devise</th>
        <th class="col-desc" style="width:35%">Commentaire</th>
        <th class="col-categories" style="width:28%">
          <div class="cat-headers">
            <div class="cat-row">
              <span><?php echo htmlspecialchars($criterionNames[1]); ?></span>
              <span><?php echo htmlspecialchars($criterionNames[2]); ?></span>
            </div>
            <div class="cat-row">
              <span><?php echo htmlspecialchars($criterionNames[3]); ?></span>
              <span><?php echo htmlspecialchars($criterionNames[4]); ?></span>
            </div>
          </div>
        </th>
        <?php if ($groupSelected): ?><th class="col-solde-virtuel" style="width:8%">Solde virtuel</th><?php endif; ?>
        <?php if ($noDateFilter): ?><th class="col-solde" style="width:8%">Solde</th><?php endif; ?>
      <?php
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
      <?php $trClass = $isPending ? 'row-pending' : ''; ?>
      <tr<?php echo $trClass ? ' class="' . $trClass . '"' : ''; ?> data-txid="<?php echo htmlspecialchars($t['id']); ?>" data-status="<?php echo htmlspecialchars($t['status'] ?? ''); ?>" data-numimport="<?php echo htmlspecialchars($t['NumImport'] ?? 0); ?>">
        <td class="col-compte" style="background:<?php echo $accColorMap[$t['account_id']] ?? 'transparent'; ?>; ">
          <?php echo htmlspecialchars($t['account_name'] ?? $t['account_id']); ?>
          <?php if (!empty($t['NumImport']) && (int)$t['NumImport'] > 0): ?>
            <span class="badge-numimport">#<?php echo htmlspecialchars((string)$t['NumImport']); ?></span>
          <?php endif; ?>
        </td>
        <td class="col-date">
          <?php if ($isPending) echo '<span class="badge-pending">en attente</span><br>'; ?>
          <?php if (isset($t['status']) && strtoupper((string)$t['status']) === 'TODEL') echo '<span class="badge-todel">à supprimer ?</span><br>'; ?>
          <?php echo htmlspecialchars((string)($t['booking_date'] ?? '')); ?>
        </td>
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
        <?php if ($groupSelected): ?>
          <?php // compute virtual group balance before applying this row's amount ?>
          <td class="col-solde-virtuel"><?php echo htmlspecialchars(number_format($groupStartBalance - ($groupRunning ?? 0.0), 2, ',', ' ')); ?></td>
        <?php endif; ?>
        <?php if ($noDateFilter): ?><td class="col-solde"><?php echo htmlspecialchars(number_format($displayBalance, 2, ',', ' ')); ?></td><?php endif; ?>
      </tr>
    <?php
        // mettre à jour cumul pour ce compte (après affichage)
        $runningAcc[$acctId] += (float)$t['amount'];
        // mettre à jour cumul pour le groupe sélectionné si applicable
        if ($groupSelected) {
          if (!isset($groupRunning)) $groupRunning = 0.0;
          $groupRunning += (float)$t['amount'];
        }
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
<script>
// Quick range selector: set from/to and submit form
document.getElementById('quickRange').addEventListener('change', function(){
  var v = this.value;
  // persist selection across visits
  document.cookie = 'selected_quickRange=' + encodeURIComponent(v) + ';path=/;max-age=31536000';
  var dateFields = document.getElementById('dateRangeFields');
  var form = this.closest('form');
  var inpFrom = form ? form.querySelector('input[name=from]') : null;
  var inpTo = form ? form.querySelector('input[name=to]') : null;
  if (v === 'custom') {
    // show date inputs and do not auto-submit
    if (dateFields) dateFields.style.display = '';
    // If server or cookie provided explicit from/to keep them; otherwise leave empty
    return;
  }
  // hide date inputs for preset ranges
  if (dateFields) dateFields.style.display = 'none';
  if (!v) return;
  var now = new Date();
  var from = new Date(now.getFullYear(), now.getMonth(), now.getDate());
  if (v === '10d') { from.setDate(from.getDate() - 9); }
  else if (v === '20d') { from.setDate(from.getDate() - 19); }
  else if (v === '1m') { from.setMonth(from.getMonth() - 1); }
  else if (v === '2m') { from.setMonth(from.getMonth() - 2); }
  else if (v === '6m') { from.setMonth(from.getMonth() - 6); }
  else if (v === '1y') { from.setFullYear(from.getFullYear() - 1); }
  else if (v === '2y') { from.setFullYear(from.getFullYear() - 2); }
  function ymd(d){ return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0'); }
  if (!form) return;
  if (inpFrom) inpFrom.value = ymd(from);
  if (inpTo) inpTo.value = ymd(now);
  // submit form so server-side will render with selected params
  form.submit();
});

// Save from/to in cookies when custom dates are used — only on blur (not every keystroke)
document.querySelectorAll('input[name=from], input[name=to]').forEach(function(inp){
  inp.addEventListener('blur', function(){
    var form = this.closest('form');
    var from = form.querySelector('input[name=from]').value || '';
    var to = form.querySelector('input[name=to]').value || '';
    document.cookie = 'selected_from=' + encodeURIComponent(from) + ';path=/;max-age=31536000';
    document.cookie = 'selected_to=' + encodeURIComponent(to) + ';path=/;max-age=31536000';
    // ensure quickRange is set to custom
    var sel = document.getElementById('quickRange'); if (sel) { sel.value = 'custom'; document.cookie = 'selected_quickRange=custom;path=/;max-age=31536000'; }
    // submit to apply
    if (form) form.submit();
  });
});

// On load: restore saved quickRange and apply it if no explicit selection
document.addEventListener('DOMContentLoaded', function(){
  var sel = document.getElementById('quickRange');
  if (!sel) return;
  // If server already set a value (from GET), keep it. Otherwise restore cookie.
  // If server already set a value (from GET), keep it. Otherwise restore cookie.
  var form = sel.closest('form');
  var inpFrom = form ? form.querySelector('input[name=from]') : null;
  var inpTo = form ? form.querySelector('input[name=to]') : null;
  var dateFields = document.getElementById('dateRangeFields');
  // If quickRange is already set to custom, show date fields and stop
  if (sel.value === 'custom') {
    if (dateFields) dateFields.style.display = '';
    return;
  }
  // If quickRange is set to a preset, hide dates and stop
  if (sel.value) {
    if (dateFields) dateFields.style.display = 'none';
    return;
  }
  if (sel.value) return;
  var m = document.cookie.match('(?:^|; )selected_quickRange=([^;]+)');
  if (m && m[1]) {
    try {
      var val = decodeURIComponent(m[1]);
      sel.value = val;
      if (val === 'custom') {
        if (dateFields) dateFields.style.display = '';
        return;
      }
      sel.dispatchEvent(new Event('change'));
    } catch(e) { /* ignore */ }
  }
});
</script>
<div id="todelPopup" style="display:none">
  <div class="popup-title">Action sur la ligne</div>
  <div style="margin-bottom:8px">Que voulez-vous faire pour cette ligne ?</div>
  <div style="margin-bottom:8px">
    <button id="showImportRows" class="btn">Surligner les lignes du même import</button>
    <button id="filterImportRows" class="btn">Afficher seulement les lignes du même import</button>
  </div>
  <div id="todelActions">
    <button id="todelDelete" class="btn btn-danger">Supprimer définitivement</button>
    <button id="todelRestore" class="btn btn-restore">Restaurer</button>
  </div>
  <div style="margin-top:8px">
    <button id="todelCancel" class="btn btn-cancel">Annuler</button>
  </div>
</div>

<script>
;(function(){
  var popup = document.getElementById('todelPopup');
  var currentTr = null;

  function showPopup(x,y,txid,tr) {
    currentTr = tr;
    popup.style.display = 'block';
    // position with offset to avoid overflow
    var px = x + 6, py = y + 6;
    popup.style.left = px + 'px';
    popup.style.top = py + 'px';
  }
  function hidePopup(){ popup.style.display = 'none'; currentTr = null; }

  document.addEventListener('contextmenu', function(e){
    var tr = e.target.closest('tr');
    if (!tr) return;
    e.preventDefault();
    var st = (tr.dataset.status || '').toUpperCase();
    // Show popup for any row; hide TODEL actions if not TODEL
    showPopup(e.pageX, e.pageY, tr.dataset.txid, tr);
    var todelActions = document.getElementById('todelActions');
    if (st === 'TODEL') {
      todelActions.style.display = 'block';
    } else {
      todelActions.style.display = 'none';
    }
    // update the showImportRows button label based on current state
    var importBtn = document.getElementById('showImportRows');
    if (importBtn) importBtn.textContent = 'Surligner les lignes du même import';
  });

  document.getElementById('todelCancel').addEventListener('click', hidePopup);

  document.getElementById('todelDelete').addEventListener('click', function(){
    if (!currentTr) return;
    var txid = currentTr.dataset.txid;
    if (!confirm('Confirmer la suppression définitive de cette ligne ? Cette action est irréversible.')) return;
    fetch('mon-site/api/tx_action.php', { method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'}, body: 'action=delete&id='+encodeURIComponent(txid) })
      .then(r=>r.json()).then(function(j){
        if (j && j.ok) { currentTr.parentNode.removeChild(currentTr); hidePopup(); }
        else alert('Erreur: ' + (j && j.error ? j.error : 'action échouée'));
      }).catch(function(e){ alert('Erreur réseau: '+e); });
  });

  document.getElementById('todelRestore').addEventListener('click', function(){
    if (!currentTr) return;
    var txid = currentTr.dataset.txid;
    fetch('mon-site/api/tx_action.php', { method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'}, body: 'action=restore&id='+encodeURIComponent(txid) })
      .then(r=>r.json()).then(function(j){
        if (j && j.ok) {
          // update row status and remove badge
          currentTr.dataset.status = 'OTHR';
          var badge = currentTr.querySelector('.badge-todel'); if (badge) badge.parentNode.removeChild(badge);
          hidePopup();
        } else alert('Erreur: ' + (j && j.error ? j.error : 'action échouée'));
      }).catch(function(e){ alert('Erreur réseau: '+e); });
  });

  // Show/Hide rows of the same import (toggle)
  document.getElementById('showImportRows').addEventListener('click', function(){
    if (!currentTr) return;
    var num = currentTr.dataset.numimport || '0';
    var rows = Array.from(document.querySelectorAll('tr[data-numimport]'));
    var matches = rows.filter(function(r){ return (r.dataset.numimport||'0') === num; });
    if (!matches.length) { alert('Aucune ligne pour ce numéro d\'import: ' + num); return; }
    var anyHighlighted = matches.some(function(r){ return r.classList.contains('highlight-import'); });
    if (anyHighlighted) {
      matches.forEach(function(r){ r.classList.remove('highlight-import'); });
      this.textContent = 'Surligner les lignes du même import';
    } else {
      // remove highlight from others first
      document.querySelectorAll('tr.highlight-import').forEach(function(r){ r.classList.remove('highlight-import'); });
      matches.forEach(function(r){ r.classList.add('highlight-import'); });
      this.textContent = 'dé-surligner les lignes du même import';
      // scroll to first match
      var first = matches[0]; first.scrollIntoView({behavior:'smooth', block:'center'});
    }
  });

  // Filter to show only rows of the same import (toggle)
  var importFilterActive = false;
  document.getElementById('filterImportRows').addEventListener('click', function(){
    if (!currentTr) return;
    var num = currentTr.dataset.numimport || '0';
    var rows = Array.from(document.querySelectorAll('tr[data-numimport]'));
    if (!importFilterActive) {
      // hide rows not matching
      rows.forEach(function(r){ if ((r.dataset.numimport||'0') !== num) r.style.display = 'none'; });
      this.textContent = 'Réinitialiser filtre';
      importFilterActive = true;
    } else {
      // restore all rows
      rows.forEach(function(r){ r.style.display = ''; });
      this.textContent = 'Afficher seulement les lignes du même import';
      importFilterActive = false;
    }
  });

  // Hide popup on outside click
  document.addEventListener('click', function(e){ if (popup.style.display === 'block' && !popup.contains(e.target)) hidePopup(); });
})();
</script>
</body>
</html>
