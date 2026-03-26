<?php
// Transactions — affichage avec bandeau, filtre par compte, traduction FR
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// NOTE: server-side user-agent based redirect removed — prefer client-side viewport handling

try {
  $pdo = require __DIR__ . '/mon-site/api/db.php';
} catch (Throwable $e) {
  echo '<h1>Erreur BDD</h1><pre>' . htmlspecialchars((string)$e) . '</pre>';
  exit;
}

// Handle POST: save category and redirect back (PRG pattern)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['tx_id']) && !empty($_POST['field'])) {
  $txId = $_POST['tx_id'];
  $field = $_POST['field'];
  $value = $_POST['value'] ?? null;
  $allowed = ['cat1_id','cat2_id','cat3_id','cat4_id'];
  if (in_array($field, $allowed, true)) {
    $stmt = $pdo->prepare("UPDATE transactions SET `$field` = :val WHERE id = :id");
    $stmt->execute([':val' => ($value !== '' ? $value : null), ':id' => $txId]);
  }
  // Redirect back to same page with GET params preserved + mobile card index
  $qs = $_SERVER['QUERY_STRING'] ?? '';
  $mcIdx = isset($_POST['mcIdx']) ? (int)$_POST['mcIdx'] : 0;
  $sep = $qs ? '&' : '';
  header('Location: transactions.php?' . $qs . $sep . 'mcIdx=' . $mcIdx);
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

// Show pending operations filter: default show pending (1). If show_pending=0, only include BOOK status
$showPending = isset($_GET['show_pending']) ? ($_GET['show_pending'] === '1') : true;
if (!$showPending) {
  $where[] = "UPPER(t.status) = 'BOOK'";
}

// Pagination (page param 0-based). Default limit 30; can be increased by user via 'limit' GET param
$page = max(0, (int)($_GET['page'] ?? 0));
$limit = max(1, (int)($_GET['limit'] ?? 30));

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
 $sql .= ' ORDER BY t.booking_date DESC, t.amount DESC LIMIT :limit OFFSET :offset';

 $stmt = $pdo->prepare($sql);
 foreach ($params as $k => $v) $stmt->bindValue($k, $v);
 $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
 $stmt->bindValue(':offset', $page * $limit, PDO::PARAM_INT);
 $stmt->execute();
 $txs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Build map NumImport => import datetime (use earliest created_at for the batch)
$importDates = [];
$numImports = [];
foreach ($txs as $t) {
  $ni = isset($t['NumImport']) ? (int)$t['NumImport'] : 0;
  if ($ni > 0) $numImports[$ni] = $ni;
}
if (!empty($numImports)) {
  $placeholders = implode(',', array_fill(0, count($numImports), '?'));
  $q = $pdo->prepare('SELECT NumImport, MIN(created_at) AS import_dt FROM transactions WHERE NumImport IN (' . $placeholders . ') GROUP BY NumImport');
  $i = 1;
  foreach ($numImports as $val) { $q->bindValue($i++, $val); }
  $q->execute();
  while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
    $imp = (int)$row['NumImport'];
    $importDates[$imp] = $row['import_dt'];
  }
}

// Determine whether to show the "Solde" column.
// Hide the Solde column only when the 'from' date is provided and is earlier than today.
$toParam = $_GET['to'] ?? ($_COOKIE['selected_to'] ?? '');
$today = date('Y-m-d');
$showSolde = true;
// Hide Solde only when the 'to' date is provided and is strictly before today
if ($toParam !== '' && $toParam < $today) {
  $showSolde = false;
}

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
        <div style="margin-top:6px;font-size:0.95rem;color:#555">
          <span id="limitDisplay">Affichage: <?php echo htmlspecialchars((int)$limit); ?> opérations</span>
          <button id="moreBtn" type="button" class="btn" style="margin-left:8px;padding:4px 8px;font-weight:700">+</button>
          <div id="screenWidthDisplay" style="font-size:0.85rem;color:#666;margin-top:6px">Largeur écran: -- px</div>
        </div>
      </div>
        <div class="tx-col tx-center" style="flex:1;text-align:center">
        <div style="display:flex;flex-direction:column;align-items:center">
            <div style="display:flex;gap:8px;align-items:center">
              <select name="account" class="select-account" onchange="document.cookie='selected_account='+encodeURIComponent(this.value)+';path=/;max-age=31536000'; this.form.submit()" style="min-width:220px">
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
            <!-- 'Afficher les mouvements carte' checkbox removed (UI-only) -->
          </div>
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
      <div class="tx-col tx-left" style="flex:1"></div>
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

        
    <div style="height:8px;margin:8px 0"></div>

  <table class="tx-table">
    <thead>
      <tr>
        <th class="col-compte" style="width:8%">Compte</th>
        <th class="col-date" style="width:8%">Date</th>
        <th class="col-montant" style="width:8%">Montant</th>
        <th class="col-devise" style="width:5%">Devise</th>
        <th class="col-desc" style="width:35%">Libellé</th>
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
        <?php if ($showSolde): ?><th class="col-solde" style="width:8%">Solde</th><?php endif; ?>
        <?php if ($groupSelected): ?><th class="col-solde-virtuel" style="width:8%">Solde virtuel</th><?php endif; ?>
      </tr>
    </thead>
    <tbody>
      <?php
      $runningAcc = [];
      foreach ($txs as $t):
      // Consider BOOK as booked/current; MANUAL will be shown as manual badge (not generic pending)
      $statusUpper = strtoupper((string)($t['status'] ?? ''));
      $isPending = ($statusUpper !== 'BOOK' && $statusUpper !== 'MANUAL');
      $today = date('Y-m-d');
      // Determine OTHR badge and whether OTHR should count in virtual balance
      $countInVirtual = false;
      $badgeHtml = '';
      if ($statusUpper === 'MANUAL') {
        $badgeHtml = '<span class="badge-manual">Saisie manuelle</span>';
        $countInVirtual = false;
      } elseif ($statusUpper === 'OTHR') {
        $acctDate = isset($t['accounting_date']) && $t['accounting_date'] !== null && $t['accounting_date'] !== '' ? (string)$t['accounting_date'] : null;
        if ($acctDate) {
          if ($today === $acctDate) {
            $badgeHtml = '<span class="badge-today">Aujourd\'hui</span>';
            $countInVirtual = false;
          } elseif ($today < $acctDate) {
            $badgeHtml = '<span class="badge-pending">P. Différé</span>';
            $countInVirtual = true;
          } else {
            $badgeHtml = '<span class="badge-paid">Payé</span>';
            $countInVirtual = false;
          }
        } else {
          // fallback: treat as pending but do not count in virtual without accounting_date
          $badgeHtml = '<span class="badge-pending">P. Différé</span>';
          $countInVirtual = false;
        }
      }
      $acctId = $t['account_id'];
      if (!isset($runningAcc[$acctId])) $runningAcc[$acctId] = 0.0; // cumul des montants déjà vus (newest->oldest)
      $startBal = $accBalances[$acctId] ?? 0.0;
      // solde affiché = solde courant du compte - cumul des montants précédents
      $displayBalance = $startBal - $runningAcc[$acctId];
    ?>
      <?php $trClass = $isPending ? 'row-pending' : ''; ?>
      <tr id="tx_<?php echo htmlspecialchars($t['id']); ?>"<?php echo $trClass ? ' class="' . $trClass . '"' : ''; ?> data-txid="<?php echo htmlspecialchars($t['id']); ?>" data-status="<?php echo htmlspecialchars($t['status'] ?? ''); ?>" data-numimport="<?php echo htmlspecialchars($t['NumImport'] ?? 0); ?>">
        <td class="col-compte" style="background:<?php echo $accColorMap[$t['account_id']] ?? 'transparent'; ?>; ">
          <?php echo htmlspecialchars($t['account_name'] ?? $t['account_id']); ?>
          <?php if (!empty($t['NumImport']) && (int)$t['NumImport'] > 0):
            $imp = (int)$t['NumImport'];
            // prefer import batch datetime if available, else use transaction created_at or booking_date
            $title = '';
            if (!empty($importDates[$imp])) {
              $title = $importDates[$imp];
            } elseif (!empty($t['created_at'])) {
              $title = $t['created_at'];
            } elseif (!empty($t['booking_date'])) {
              $title = (string)$t['booking_date'] . ' 00:00:00';
            }
          ?>
            <span class="badge-numimport"<?php echo $title ? ' title="' . htmlspecialchars($title) . '"' : ''; ?>>#<?php echo htmlspecialchars((string)$t['NumImport']); ?></span>
          <?php endif; ?>
        </td>
        <td class="col-date">
          <?php if ($badgeHtml) { echo $badgeHtml . '<br>'; } else if ($isPending) { echo '<span class="badge-pending">P. Différé</span><br>'; } ?>
          <?php if (isset($t['status']) && strtoupper((string)$t['status']) === 'TODEL') echo '<span class="badge-todel">à supprimer ?</span><br>'; ?>
          <?php echo htmlspecialchars((string)($t['booking_date'] ?? '')); ?>
        </td>
        <td class="col-montant" style="<?php echo ($t['amount'] < 0) ? 'color:#c62828' : 'color:#2e7d32'; ?>"><?php echo htmlspecialchars(number_format((float)$t['amount'], 2, ',', ' ')); ?></td>
        <td class="col-devise"><?php echo htmlspecialchars((string)($t['currency'] ?? '')); ?></td>
        <td class="col-desc" data-label="Libellé"><?php echo htmlspecialchars((string)($t['description'] ?? '')); ?></td>
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
        <?php if ($showSolde): ?>
          <?php if (isset($t['status']) && strtoupper((string)$t['status']) === 'OTHR'): ?>
            <td class="col-solde" data-label="Solde"></td>
          <?php else: ?>
            <td class="col-solde" data-label="Solde"><?php echo htmlspecialchars(number_format($displayBalance, 2, ',', ' ')); ?></td>
          <?php endif; ?>
        <?php endif; ?>
        <?php if ($groupSelected): ?>
          <?php // compute virtual group balance before applying this row's amount ?>
          <td class="col-solde-virtuel" data-label="Solde virtuel"><?php echo htmlspecialchars(number_format($groupStartBalance - ($groupRunning ?? 0.0), 2, ',', ' ')); ?></td>
        <?php endif; ?>
      </tr>
        <?php
        // mettre à jour cumul pour ce compte (après affichage)
        $shouldCount = true;
        if ($statusUpper === 'OTHR') {
          $shouldCount = $countInVirtual;
        }
        if ($shouldCount) {
          $runningAcc[$acctId] += (float)$t['amount'];
          // mettre à jour cumul pour le groupe sélectionné si applicable
          if ($groupSelected) {
            if (!isset($groupRunning)) $groupRunning = 0.0;
            $groupRunning += (float)$t['amount'];
          }
        }
    endforeach;
    ?>
    </tbody>
  </table>
</main>
<!-- Floating add button (keeps current view) -->
<button id="bottomAddBtn" title="Ajouter une opération" style="position:fixed;right:16px;bottom:16px;z-index:2000;background:#1976d2;color:#fff;border:none;border-radius:50%;width:56px;height:56px;font-size:28px;line-height:56px;box-shadow:0 6px 18px rgba(0,0,0,0.18);cursor:pointer">+</button>

<!-- Add modal -->
<div id="addModal" style="display:none;position:fixed;left:0;top:0;width:100%;height:100%;background:rgba(0,0,0,0.35);z-index:2100;align-items:center;justify-content:center">
  <div style="background:#fff;padding:14px;border-radius:8px;max-width:520px;width:92%;box-sizing:border-box">
    <h3 style="margin-top:0">Ajouter une opération</h3>
    <form id="addTxForm">
      <div style="display:flex;gap:8px;margin-bottom:8px">
        <select name="account_id" required style="flex:1">
          <option value="">Choisir compte…</option>
          <?php foreach ($accs as $a): ?>
            <option value="<?php echo (int)$a['id']; ?>"><?php echo htmlspecialchars($a['name']); ?></option>
          <?php endforeach; ?>
        </select>
        <input name="currency" placeholder="Devise" style="width:80px" value="EUR">
      </div>
      <div style="display:flex;gap:8px;margin-bottom:8px">
        <?php for ($ci=1;$ci<=4;$ci++): ?>
          <select name="cat<?php echo $ci; ?>" style="flex:1">
            <option value=""><?php echo htmlspecialchars($criterionNames[$ci] ?? 'Cat'); ?></option>
            <?php if (!empty($catTree[$ci])): foreach ($catTree[$ci] as $pid=>$node): if (!$node['info']) continue; ?>
              <optgroup label="<?php echo htmlspecialchars($node['info']['label']); ?>">
                <option value="<?php echo (int)$node['info']['id']; ?>"><?php echo htmlspecialchars($node['info']['label']); ?></option>
                <?php foreach ($node['children'] as $child): ?>
                  <option value="<?php echo (int)$child['id']; ?>">&nbsp;&nbsp;<?php echo htmlspecialchars($child['label']); ?></option>
                <?php endforeach; ?>
              </optgroup>
            <?php endforeach; endif; ?>
          </select>
        <?php endfor; ?>
      </div>
      <div style="display:flex;gap:8px;margin-bottom:8px">
        <input name="booking_date" type="date" required style="flex:1" value="<?php echo date('Y-m-d'); ?>">
        <input name="amount" placeholder="Montant" required style="width:140px">
      </div>
      <div style="margin-bottom:8px">
        <input name="description" placeholder="Libellé" style="width:100%">
      </div>
      <div style="text-align:right">
        <button type="button" id="addCancel" class="btn" style="margin-right:8px">Annuler</button>
        <button type="submit" class="btn btn-primary">Ajouter</button>
      </div>
    </form>
  </div>
</div>
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
// Add button/modal behaviour
document.addEventListener('DOMContentLoaded', function(){
  var bottom = document.getElementById('bottomAddBtn');
  var modal = document.getElementById('addModal');
  var form = document.getElementById('addTxForm');
  var cancel = document.getElementById('addCancel');
  if (!bottom || !modal || !form) return;
  bottom.addEventListener('click', function(){ modal.style.display = 'flex'; });
  cancel.addEventListener('click', function(){ modal.style.display = 'none'; });
  form.addEventListener('submit', function(ev){
    ev.preventDefault();
    var fd = new FormData(form);
    fetch('mon-site/api/add_tx.php', { method: 'POST', body: fd })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (j && j.ok) {
          alert('Opération ajoutée (id=' + j.id + '). L affichage reste sur la ligne courante.');
          modal.style.display = 'none';
          form.reset();
        } else {
          alert('Erreur ajout: ' + (j && j.error ? j.error : 'erreur inconnue'));
        }
      }).catch(function(e){ alert('Erreur réseau: '+e); });
  });
});
</script>
<script>
// Responsive behavior: on small viewports, redirect to mobile.php preserving filters
document.addEventListener('DOMContentLoaded', function(){
  try {
    var width = window.innerWidth || document.documentElement.clientWidth || window.screen.width;
    // update screen width display under the '+' button
    var _swEl = document.getElementById('screenWidthDisplay');
    function _updateScreenWidth() {
      var w2 = window.innerWidth || document.documentElement.clientWidth || window.screen.width;
      if (_swEl) _swEl.textContent = 'Largeur écran: ' + w2 + ' px';
    }
    _updateScreenWidth();
    window.addEventListener('resize', _updateScreenWidth);
    var qs = location.search || '';
    if (width <= 800 && qs.indexOf('desktop=1') === -1) {
      location.href = 'mobile.php' + qs;
      return;
    }
  } catch(e) { /* ignore */ }

  // Click on a transaction row opens mobile.php for that tx
  var tbody = document.querySelector('table.tx-table tbody');
  if (!tbody) return;
  var accountSel = <?php echo json_encode($acctSel); ?>;
  var showPendingFlag = <?php echo $showPending ? '1' : '0'; ?>;
  tbody.addEventListener('click', function(ev){
    var tr = ev.target.closest('tr');
    if (!tr) return;
    // ignore clicks on selects/buttons/links
    if (ev.target.closest('select') || ev.target.closest('button') || ev.target.closest('a')) return;
    var txid = tr.dataset.txid;
    if (!txid) return;
    var url = 'mobile.php?tx_id=' + encodeURIComponent(txid) + '&popup=1';
    if (accountSel) url += '&account=' + encodeURIComponent(accountSel);
    url += '&show_pending=' + encodeURIComponent(showPendingFlag);
    // open details in a popup window to avoid full redirect
    window.open(url, 'txpopup', 'width=640,height=760,menubar=no,toolbar=no,location=no,status=no');
  });

  // '+' button to increase limit by 30
  var moreBtn = document.getElementById('moreBtn');
  if (moreBtn) {
    moreBtn.addEventListener('click', function(){
      var sp = new URLSearchParams(location.search);
      var cur = parseInt(sp.get('limit') || '<?php echo (int)$limit; ?>', 10) || <?php echo (int)$limit; ?>;
      sp.set('limit', String(cur + 30));
      sp.set('page', '0');
      location.search = sp.toString();
    });
  }

  // bottom '+' to load 30 more while preserving current visible line
  var loadMoreBottom = document.getElementById('loadMoreBottom');
  if (loadMoreBottom) {
    loadMoreBottom.addEventListener('click', function(){
      var rows = Array.from(document.querySelectorAll('table.tx-table tbody tr'));
      if (!rows.length) { // fallback: just increase limit
        var spf = new URLSearchParams(location.search);
        var curf = parseInt(spf.get('limit') || '<?php echo (int)$limit; ?>', 10) || <?php echo (int)$limit; ?>;
        spf.set('limit', String(curf + 30)); spf.set('page','0'); location.search = spf.toString(); return;
      }
      var firstVisible = rows.find(function(r){ var rect = r.getBoundingClientRect(); return rect.top >= 0 && rect.bottom > 0; }) || rows[0];
      var txid = firstVisible ? (firstVisible.dataset.txid || firstVisible.id.replace(/^tx_/, '')) : '';
      var sp = new URLSearchParams(location.search);
      var cur = parseInt(sp.get('limit') || '<?php echo (int)$limit; ?>', 10) || <?php echo (int)$limit; ?>;
      sp.set('limit', String(cur + 30));
      sp.set('page', '0');
      var hash = txid ? ('#tx_' + encodeURIComponent(txid)) : '';
      location.href = location.pathname + '?' + sp.toString() + hash;
    });
  }
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
  if (inpTo) {
    // set 'to' to December 31st of the current year for presets
    var dec31 = new Date(now.getFullYear(), 11, 31);
    inpTo.value = ymd(dec31);
  }
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
  <!-- Static load-more button placed at end of document (normal flow) -->
  <div style="text-align:center;margin:18px 0">
    <button id="loadMoreBottom" class="btn" style="padding:10px 18px;font-size:15px;border-radius:8px;background:#1976d2;color:#fff;border:none;cursor:pointer">Plus de lignes</button>
  </div>
</body>
</html>
