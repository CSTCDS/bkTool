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

// Liste des comptes pour le dropdown (inclut le solde courant, solde2eme, type et reference_date)
$accs = $pdo->query('SELECT id, name, balance, solde2eme, account_type, color, reference_date, numero_affichage FROM accounts ORDER BY (numero_affichage IS NULL), numero_affichage ASC, name ASC')->fetchAll(PDO::FETCH_ASSOC);
$accMap = [];
$accBalances = [];
      } else {
        // legacy fallback: compute from accounting_date/reference_date
        if ($statusUpper === 'OTHR') {
          $acctDate = isset($t['accounting_date']) && $t['accounting_date'] !== null && $t['accounting_date'] !== '' ? (string)$t['accounting_date'] : null;
          $txDate = isset($t['booking_date']) && $t['booking_date'] !== null && $t['booking_date'] !== '' ? (string)$t['booking_date'] : null;
          $accRef = isset($accRefMap[$t['account_id']]) ? $accRefMap[$t['account_id']] : null;

          // Prefer accounting_date when present (paiement différé / today / payé)
          if ($acctDate) {
            if ($today === $acctDate) { $badgeHtml = '<span class="badge-today">Aujourd\'hui</span>'; }
            elseif ($today < $acctDate) { $badgeHtml = '<span class="badge-pending">Paiement différé</span>'; }
            else { $badgeHtml = '<span class="badge-paid">Payé</span>'; }
          }
          // Otherwise, fall back to reference_date vs transaction date to detect 'mois prochain'
          elseif ($txDate && $accRef) {
            try {
              $dtx = new DateTime($txDate);
              $dref = new DateTime($accRef);
              if ($dtx >= $dref) {
                $badgeHtml = '<span class="badge-nextmonth">Mois prochain</span>';
              } else {
                // no accounting_date and tx before reference -> treat as paiement différé
                $badgeHtml = '<span class="badge-pending">Paiement différé</span>';
              }
            } catch (Throwable $e) {
              $badgeHtml = '<span class="badge-pending">Paiement différé</span>';
            }
          } else {
            // default: paiement différé
            $badgeHtml = '<span class="badge-pending">Paiement différé</span>';
          }
        }
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

// Build categories grouped by criterion for modal population (parents with children)
$catsByCriterion = [];
for ($ci = 1; $ci <= 4; $ci++) {
  $catsByCriterion[$ci] = [];
  if (!empty($catTree[$ci])) {
    foreach ($catTree[$ci] as $pid => $node) {
      if (!$node['info']) continue;
      // only include parents that have children
      if (empty($node['children'])) continue;
      $grp = ['label' => $node['info']['label'], 'children' => []];
      foreach ($node['children'] as $child) $grp['children'][] = ['id' => (int)$child['id'], 'label' => $child['label']];
      $catsByCriterion[$ci][] = $grp;
    }
  }
}

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
 $sql .= ' ORDER BY t.booking_date DESC, t.id DESC LIMIT :limit OFFSET :offset';

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
        
      <script>
      (function(){
        function initToast(){
          try {
            var tc = document.createElement('div'); tc.id = 'toastContainer'; tc.style.position = 'fixed'; tc.style.right = '16px'; tc.style.top = '16px'; tc.style.zIndex = '3200'; tc.style.display = 'flex'; tc.style.flexDirection = 'column'; tc.style.gap = '8px'; document.body.appendChild(tc);
            window.showToast = function(msg, timeout){
              try {
                timeout = (typeof timeout === 'number') ? timeout : 3000;
                var el = document.createElement('div');
                el.textContent = msg || '';
                el.style.background = 'rgba(32,32,32,0.9)'; el.style.color = '#fff'; el.style.padding = '8px 12px'; el.style.borderRadius = '6px'; el.style.boxShadow = '0 6px 18px rgba(0,0,0,0.2)'; el.style.opacity = '1'; el.style.transition = 'opacity 0.35s ease'; el.style.maxWidth = '320px'; el.style.fontSize = '0.95rem';
                tc.appendChild(el);
                setTimeout(function(){ el.style.opacity = '0'; setTimeout(function(){ try{ el.remove(); }catch(e){} }, 360); }, timeout);
              } catch(e){ console.log('toast', msg); }
            };
          } catch(e) { /* ignore */ }
        }
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initToast); else initToast();
      })();
      </script>
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
      // cumulative sums: $groupRunning counts only BOOK rows; $virtualGroupRunning counts rows with CountInVirtual=1
      $groupRunning = 0.0;
      $virtualGroupRunning = 0.0;
      foreach ($txs as $t):
      // Consider BOOK as booked/current; MANUAL will be shown as manual badge (not generic pending)
      $statusUpper = strtoupper((string)($t['status'] ?? ''));
      $isPending = ($statusUpper !== 'BOOK' && $statusUpper !== 'MANUAL');
      $today = date('Y-m-d');
      // Use stored `badge` and `CountInVirtual` when available; fall back to legacy logic if absent
      $badgeHtml = '';
      $storedBadge = isset($t['badge']) && $t['badge'] !== '' ? $t['badge'] : null;
      $storedCount = isset($t['CountInVirtual']) ? (int)$t['CountInVirtual'] : null;
      if ($statusUpper === 'MANUAL') {
        $badgeHtml = '<span class="badge-manual">Saisie manuelle</span>';
      } elseif ($storedBadge !== null) {
        switch ($storedBadge) {
          case 'nextmonth': $badgeHtml = '<span class="badge-nextmonth">Mois prochain</span>'; break;
          case 'today': $badgeHtml = '<span class="badge-today">Aujourd\'hui</span>'; break;
          case 'pending': $badgeHtml = '<span class="badge-pending">Paiement différé</span>'; break;
          case 'paid': $badgeHtml = '<span class="badge-paid">Payé</span>'; break;
          case 'manual': $badgeHtml = '<span class="badge-manual">Saisie manuelle</span>'; break;
          default: $badgeHtml = '<span class="badge-pending">Paiement différé</span>'; break;
        }
      } else {
        // legacy fallback: compute from accounting_date/reference_date
        if ($statusUpper === 'OTHR') {
          $acctDate = isset($t['accounting_date']) && $t['accounting_date'] !== null && $t['accounting_date'] !== '' ? (string)$t['accounting_date'] : null;
          $txDate = isset($t['booking_date']) && $t['booking_date'] !== null && $t['booking_date'] !== '' ? (string)$t['booking_date'] : null;
          $accRef = isset($accRefMap[$t['account_id']]) ? $accRefMap[$t['account_id']] : null;
          if ($txDate && $accRef) {
            try {
              $dtx = new DateTime($txDate);
              $dref = new DateTime($accRef);
              if ($dtx >= $dref) {
                $badgeHtml = '<span class="badge-nextmonth">Mois prochain</span>';
              } else {
                if ($acctDate) {
                  if ($today === $acctDate) { $badgeHtml = '<span class="badge-today">Aujourd\'hui</span>'; }
                  elseif ($today < $acctDate) { $badgeHtml = '<span class="badge-pending">Paiement différé</span>'; }
                  else { $badgeHtml = '<span class="badge-paid">Payé</span>'; }
                } else { $badgeHtml = '<span class="badge-pending">Paiement différé</span>'; }
              }
            } catch (Throwable $e) {
              if ($acctDate) {
                if ($today === $acctDate) { $badgeHtml = '<span class="badge-today">Aujourd\'hui</span>'; }
                elseif ($today < $acctDate) { $badgeHtml = '<span class="badge-pending">Paiement différé</span>'; }
                else { $badgeHtml = '<span class="badge-paid">Payé</span>'; }
              } else { $badgeHtml = '<span class="badge-pending">Paiement différé</span>'; }
            }
          } else {
            if ($acctDate) {
              if ($today === $acctDate) { $badgeHtml = '<span class="badge-today">Aujourd\'hui</span>'; }
              elseif ($today < $acctDate) { $badgeHtml = '<span class="badge-pending">Paiement différé</span>'; }
              else { $badgeHtml = '<span class="badge-paid">Payé</span>'; }
            } else {
              $badgeHtml = '<span class="badge-pending">Paiement différé</span>';
            }
          }
        }
      }
      $acctId = $t['account_id'];
      if (!isset($runningAcc[$acctId])) $runningAcc[$acctId] = 0.0; // cumul des montants déjà vus (newest->oldest)
      $startBal = $accBalances[$acctId] ?? 0.0;
      // If a single card account is selected, include the second balance in the starting balance
      if (!$groupSelected && (isset($accTypeMap[$acctId]) && $accTypeMap[$acctId] === 'card')) {
        $startBal += ($accSecond[$acctId] ?? 0.0);
      }
      // solde affiché = solde courant du compte - cumul des montants précédents
      $displayBalance = $startBal - $runningAcc[$acctId];
      // Decide counting flags BEFORE rendering the row so we can conditionally show cells
      // If a single account is selected, count all rows regardless of status for Solde
      $shouldCountForSolde = !$groupSelected ? true : (strtoupper((string)($t['status'] ?? '')) === 'BOOK');
      $shouldCountForVirtual = ((int)($t['CountInVirtual'] ?? 0) === 1);
    ?>
      <?php $trClass = $isPending ? 'row-pending' : ''; ?>
      <tr id="tx_<?php echo htmlspecialchars($t['id']); ?>"<?php echo $trClass ? ' class="' . $trClass . '"' : ''; ?> data-txid="<?php echo htmlspecialchars($t['id']); ?>" data-status="<?php echo htmlspecialchars($t['status'] ?? ''); ?>" data-numimport="<?php echo htmlspecialchars($t['NumImport'] ?? 0); ?>" data-account="<?php echo htmlspecialchars($t['account_id']); ?>" data-desc="<?php echo htmlspecialchars($t['description'] ?? ''); ?>">
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
          <?php /* TODEL status removed: legacy marker cleaned */ ?>
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
                    <option value="999999<?php echo $ci2; ?>">Créer une Catégorie N°<?php echo $ci2; ?></option>
                    <option value="999998<?php echo $ci2; ?>">Créer une règle auto</option>
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
                    <option value="999999<?php echo $ci2; ?>">Créer une Catégorie N°<?php echo $ci2; ?></option>
                    <option value="999998<?php echo $ci2; ?>">Créer une règle auto</option>
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
          <?php if (!$groupSelected): ?>
            <td class="col-solde" data-label="Solde"><?php echo htmlspecialchars(number_format($displayBalance, 2, ',', ' ')); ?></td>
          <?php else: ?>
            <?php // group selected: show Solde only for BOOK rows ?>
            <?php if (isset($t['status']) && strtoupper((string)$t['status']) === 'BOOK'): ?>
              <td class="col-solde" data-label="Solde"><?php echo htmlspecialchars(number_format($displayBalance, 2, ',', ' ')); ?></td>
            <?php else: ?>
              <td class="col-solde" data-label="Solde"></td>
            <?php endif; ?>
          <?php endif; ?>
        <?php endif; ?>
        <?php if ($groupSelected): ?>
          <?php // Show Solde virtuel only when this row counts for virtual (CountInVirtual==1) ?>
          <?php if ($shouldCountForVirtual): ?>
            <td class="col-solde-virtuel" data-label="Solde virtuel"><?php echo htmlspecialchars(number_format($groupStartBalance - ($virtualGroupRunning ?? 0.0), 2, ',', ' ')); ?></td>
          <?php else: ?>
            <td class="col-solde-virtuel" data-label="Solde virtuel"></td>
          <?php endif; ?>
        <?php endif; ?>
      </tr>
        <?php
        // mettre à jour cumul pour ce compte (après affichage)
        // For Solde: count only BOOK rows
        if ($shouldCountForSolde) {
          $runningAcc[$acctId] += (float)$t['amount'];
          if ($groupSelected) {
            $groupRunning += (float)$t['amount'];
          }
        }
        // For Solde Virtuel: count rows where CountInVirtual == 1
        if ($shouldCountForVirtual) {
          if ($groupSelected) {
            $virtualGroupRunning += (float)$t['amount'];
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
            <option value="<?php echo htmlspecialchars($a['id']); ?>"><?php echo htmlspecialchars($a['name']); ?></option>
          <?php endforeach; ?>
        </select>
        <input name="currency" placeholder="Devise" style="width:80px" value="EUR">
      </div>
      <div style="display:flex;gap:8px;margin-bottom:8px">
        <?php for ($ci=1;$ci<=4;$ci++): ?>
          <select name="cat<?php echo $ci; ?>" class="cat-list" data-criterion="<?php echo $ci; ?>" style="flex:1">
            <option value=""><?php echo htmlspecialchars($criterionNames[$ci] ?? 'Cat'); ?></option>
            <option value="999999<?php echo $ci; ?>">Créer une Catégorie N°<?php echo $ci; ?></option>
            <option value="999998<?php echo $ci; ?>">Créer une règle auto</option>
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
  // remember previous value so we can restore it when opening a modal sentinel
  sel.addEventListener('focus', function(){ try { this.dataset.prev = this.value; } catch(e){} });
  sel.addEventListener('pointerdown', function(){ try { this.dataset.prev = this.value; } catch(e){} });
  sel.addEventListener('change', function() {
    var v = this.value || '';
    // Ignore sentinel values used to open modals: 999999{n} = create category, 999998{n} = create rule
    if (String(v).indexOf('999999') === 0 || String(v).indexOf('999998') === 0) {
      // modal flow will handle creation; do not send update to server
      return;
    }
    var data = new FormData();
    data.append('tx_id', this.dataset.txid);
    data.append('field', this.dataset.field);
    data.append('value', v);
    fetch('save_tx_category.php', { method: 'POST', body: data })
      .then(function(r) { return r.json(); })
      .then(function(j) {
        if (!j.ok) console.error('Erreur save category', j);
      })
      .catch(function(e) { console.error(e); });
  });
});
</script>
<!-- Create category modal -->
<div id="createCatModal" style="display:none;position:fixed;left:0;top:0;width:100%;height:100%;background:rgba(0,0,0,0.35);z-index:2200;align-items:center;justify-content:center">
  <div style="background:#fff;padding:14px;border-radius:8px;max-width:520px;width:92%;box-sizing:border-box">
    <h3 id="createCatTitle">Créer une catégorie</h3>
    <form id="createCatForm">
      <input type="hidden" name="criterion" id="cc_criterion">
      <div style="margin-bottom:8px" id="cc_parent_row">
        <label>Parent: <select name="parent_id" id="cc_parent" style="width:100%"></select></label>
      </div>
      <div style="margin-bottom:8px;display:none" id="cc_child_row">
        <label>Sous-code: <select name="child_id" id="cc_child" style="width:100%" disabled></select></label>
      </div>
      <div style="margin-bottom:8px">
        <label>Libellé: <input type="text" name="label" id="cc_label" required style="width:100%"></label>
      </div>
      <div style="text-align:right">
        <button type="button" id="cc_cancel" class="btn" style="margin-right:8px">Annuler</button>
        <button type="submit" id="cc_submit" class="btn btn-primary">Créer</button>
      </div>
    </form>
  </div>
</div>

<!-- Create rule modal -->
<div id="createRuleModal" style="display:none;position:fixed;left:0;top:0;width:100%;height:100%;background:rgba(0,0,0,0.35);z-index:2300;align-items:center;justify-content:center">
  <div style="background:#fff;padding:18px;border-radius:8px;max-width:700px;width:95%;min-height:520px;box-sizing:border-box">
    <h3>Nouvelle règle</h3>
    <form id="createRuleForm" method="post" action="rules.php">
      <input type="hidden" name="action" value="create">
      <!-- Row 1: Actif, Critère (readonly), Compte (readonly) -->
      <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px">
        <label style="display:flex;align-items:center;gap:6px"><input type="checkbox" name="active" value="1" checked> Actif</label>
        <select id="rule_category_level" class="select-category-level" style="width:220px" disabled>
          <option value="0">Choisir critère (1..4)</option>
          <?php for ($ci=1;$ci<=4;$ci++): ?>
            <option value="<?php echo $ci; ?>"><?php echo $ci . ' - ' . htmlspecialchars($criterionNames[$ci]); ?></option>
          <?php endfor; ?>
        </select>
        <input type="hidden" name="category_level" id="rule_category_level_hidden" value="0">
        <select id="rule_scope_account" style="width:220px" disabled>
          <option value="">-- pas de sélection --</option>
          <option value="NULL">Global (tous les comptes)</option>
          <?php foreach ($accs as $a): ?>
            <option value="<?php echo htmlspecialchars($a['id']); ?>"><?php echo htmlspecialchars($a['name']); ?></option>
          <?php endforeach; ?>
        </select>
        <input type="hidden" name="scope_account_id" id="rule_scope_account_hidden" value="">
      </div>

      <!-- Row 2: Motif (full width) -->
      <div style="margin-bottom:8px">
        <input name="pattern" id="rule_pattern" placeholder="Motif / libellé" style="width:100%;padding:10px;box-sizing:border-box">
      </div>

      <!-- Row 3: Valeur à affecter (full width) -->
      <div style="margin-bottom:8px">
        <select name="valeur_a_affecter" id="rule_valeur_a_affecter" class="select-valeur" style="width:100%;box-sizing:border-box">
          <option value="0">Valeur à affecter (sélectionner critère)</option>
        </select>
      </div>

      <!-- Row 4: boutons -->
      <div style="display:flex;justify-content:space-between;align-items:center;gap:8px">
        <div>
          <button type="button" id="createRuleCancel" class="btn">Annuler</button>
        </div>
        <div style="text-align:center">
          <button type="button" id="createRuleApply" class="btn btn-primary">Appliquer</button>
        </div>
        <div style="text-align:right">
          <button type="button" id="createRuleApplyUnassigned" class="btn">Appliquer uniquement aux opérations non affectées</button>
          <button type="button" id="createRuleApplyAll" class="btn">Appliquer à tous</button>
        </div>
        <input name="priority" id="rule_priority" value="100" style="width:70px;padding:6px;display:none">
      </div>
      <!-- Matches list (populated dynamically) -->
      <div id="rule_matches" style="margin-top:12px;max-height:240px;overflow:auto;border-top:1px solid #eee;padding-top:8px"></div>
    </form>
  </div>
</div>

<script>
// catsByCriterion available for modal population
var catsByCriterion = <?php echo json_encode($catsByCriterion); ?>;
var catLabels = <?php echo json_encode($catLabels); ?>;

function openCreateRuleModal(criterion, txid, preselectVal) {
  var modal = document.getElementById('createRuleModal');
  var form = document.getElementById('createRuleForm');
  var levelSel = document.getElementById('rule_category_level');
  var levelHidden = document.getElementById('rule_category_level_hidden');
  var scopeSel = document.getElementById('rule_scope_account');
  var scopeHidden = document.getElementById('rule_scope_account_hidden');
  var patternInp = document.getElementById('rule_pattern');
  var valSel = document.getElementById('rule_valeur_a_affecter');
  if (!modal || !form) return;
  // preselect criterion
  if (levelSel) levelSel.value = (criterion || 0);
  if (levelHidden) levelHidden.value = (criterion || 0);
  // prefill pattern from transaction description if txid provided
  if (txid) {
    var row = document.getElementById('tx_' + txid);
    // remember which tx opened the modal so apply actions can reference it
    try { form.dataset.txid = txid; } catch(e) {}
    if (row) {
      var acc = row.getAttribute('data-account') || '';
      var desc = row.getAttribute('data-desc') || '';
      if (scopeSel) scopeSel.value = acc || '';
      if (scopeHidden) scopeHidden.value = acc || '';
      patternInp.value = desc || '';
    }
  }
  // populate valeur_a_affecter for this criterion
  populateRuleValues(criterion);
  // if a preselect value was provided (from the select previous value), use it
  if (typeof preselectVal !== 'undefined' && preselectVal !== null && preselectVal !== '') {
    try { document.getElementById('rule_valeur_a_affecter').value = preselectVal; } catch(e) {}
  }
  // if txid provided, preselect the valeur_a_affecter to the transaction's current category for this criterion
  if (txid) {
    try {
      var selField = document.querySelector('select.cat-select[data-txid="' + txid + '"][data-field="cat' + criterion + '_id"]');
      var valSel = document.getElementById('rule_valeur_a_affecter');
      if (selField && valSel) {
        // set scope account hidden to account on the row
        var row = document.getElementById('tx_' + txid);
        if (row) {
          var acc = row.getAttribute('data-account') || '';
          var scopeSel = document.getElementById('rule_scope_account');
          var scopeHidden = document.getElementById('rule_scope_account_hidden');
          if (scopeSel) scopeSel.value = acc || '';
          if (scopeHidden) scopeHidden.value = acc || '';
        }
        // set the valeur selection to the transaction's current category value if present
        var cur = selField.value || '';
        try { valSel.value = cur || '0'; } catch(e) {}
      }
    } catch(e) { /* ignore */ }
  }
  // adjust modal/list heights to fit viewport
  try { adjustModalHeights(); } catch(e){}
  modal.style.display = 'flex';
  // focus pattern
  try { patternInp.focus(); } catch(e){}
  // trigger suggestion fetch now that scope/account is set
  try { patternInp.dispatchEvent(new Event('input')); } catch(e){}
}

function adjustModalHeights() {
  var modal = document.getElementById('createRuleModal'); if (!modal) return;
  var content = modal.firstElementChild || null; if (!content) return;
  content.style.maxHeight = Math.round(window.innerHeight * 0.85) + 'px';
  var matchesDiv = document.getElementById('rule_matches'); if (!matchesDiv) return;
  // leave ~260px for header and controls, allocate remaining to matches list
  var avail = Math.round(window.innerHeight * 0.85) - 260; if (avail < 120) avail = 120;
  matchesDiv.style.maxHeight = avail + 'px';
}

window.addEventListener('resize', function(){ try { adjustModalHeights(); } catch(e){} });

function populateRuleValues(criterion) {
  var valSel = document.getElementById('rule_valeur_a_affecter');
  valSel.innerHTML = '';
  var firstOpt = document.createElement('option'); firstOpt.value = '0'; firstOpt.textContent = 'Valeur à affecter (sélectionner critère)'; valSel.appendChild(firstOpt);
  var groups = catsByCriterion[criterion] || [];
  groups.forEach(function(g){
    var optgroup = document.createElement('optgroup'); optgroup.label = g.label;
    g.children.forEach(function(ch){ var o = document.createElement('option'); o.value = ch.id; o.textContent = ch.label; optgroup.appendChild(o); });
    valSel.appendChild(optgroup);
  });
}

document.getElementById('createRuleCancel').addEventListener('click', function(){ document.getElementById('createRuleModal').style.display = 'none'; });
document.getElementById('rule_category_level').addEventListener('change', function(){ populateRuleValues(this.value); });
</script>

<script>
(function(){
  var catTree = <?php echo json_encode($catTree); ?>;

  function openCreateModal(criterion, level, triggerSelect) {
    var modal = document.getElementById('createCatModal');
    var title = document.getElementById('createCatTitle');
    var critInput = document.getElementById('cc_criterion');
    var parentRow = document.getElementById('cc_parent_row');
    var parentSel = document.getElementById('cc_parent');
    critInput.value = criterion;
    document.getElementById('cc_label').value = '';
    modal.dataset.level = level;
    // store trigger info: for row selects we keep txid+field, for modal selects we keep name
    modal.dataset.trigger_name = triggerSelect && triggerSelect.name ? triggerSelect.name : '';
    modal.dataset.trigger_txid = triggerSelect && triggerSelect.getAttribute ? (triggerSelect.getAttribute('data-txid') || '') : '';
    modal.dataset.trigger_field = triggerSelect && triggerSelect.getAttribute ? (triggerSelect.getAttribute('data-field') || '') : '';
    // Always show parent selector so user can choose to create a new level-1
    // or create a level-2 under an existing level-1. First option = create new.
    title.textContent = (level === 1) ? ('Créer une Catégorie N°' + criterion) : ('Créer une Sous-catégorie N°' + criterion);
    parentSel.innerHTML = '';
    var firstOpt = document.createElement('option'); firstOpt.value = '0'; firstOpt.textContent = 'Créer un nouveau code'; parentSel.appendChild(firstOpt);
    var parents = catTree[criterion] || {};
    Object.keys(parents).forEach(function(pid){
      var info = parents[pid].info;
      if (!info) return;
      var opt = document.createElement('option'); opt.value = info.id; opt.textContent = info.label;
      parentSel.appendChild(opt);
    });
    parentRow.style.display = 'block';
    // reset child select and submit button text
    try { document.getElementById('cc_child').innerHTML = ''; document.getElementById('cc_child').disabled = true; document.getElementById('cc_child_row').style.display = 'none'; document.getElementById('cc_submit').textContent = 'Créer'; } catch(e){}
    modal.style.display = 'flex';
  }

  document.addEventListener('change', function(e){
    var el = e.target;
    if (!el || el.tagName !== 'SELECT') return;
    // Accept either named selects like select[name="cat1"] (modal) or row selects with data-field (cat-select)
    var isRowSelect = el.classList && el.classList.contains('cat-select') && el.dataset && el.dataset.field && (/^cat\d+_id$/.test(el.dataset.field));
    var isNamedCat = el.name && (/^cat\d+/.test(el.name));
    if (!isRowSelect && !isNamedCat) return;
    var v = el.value;
    if (!v) return;
    var m = v.match(/^999999(\d)$/);
    if (m) {
      var crit = parseInt(m[1], 10);
      var level = 1;
      openCreateModal(crit, level, el);
        setTimeout(function(){ try { el.value = (el.dataset && el.dataset.prev) ? el.dataset.prev : ''; } catch(e){ el.value = ''; } }, 200);
      return;
    }
    var r = v.match(/^999998(\d)$/);
    if (r) {
      var crit2 = parseInt(r[1], 10);
      // Open create-rule modal prefilled with transaction data
      try {
        var txid = el.getAttribute('data-txid');
        var prev = el.dataset && el.dataset.prev ? el.dataset.prev : '';
        openCreateRuleModal(crit2, txid, prev);
      } catch(e) { console.error(e); }
        setTimeout(function(){ try { el.value = (el.dataset && el.dataset.prev) ? el.dataset.prev : ''; } catch(e){ el.value = ''; } }, 200);
      return;
    }
  });

  document.getElementById('cc_cancel').addEventListener('click', function(){ document.getElementById('createCatModal').style.display = 'none'; });
  // create-rule modal helpers
  document.getElementById('createRuleCancel').addEventListener('click', function(){ document.getElementById('createRuleModal').style.display = 'none'; });
  document.getElementById('rule_category_level').addEventListener('change', function(){ populateRuleValues(this.value); });

  // Suggest matching transactions as user types motif
  ;(function(){
    var inp = document.getElementById('rule_pattern');
    var matchesDiv = document.getElementById('rule_matches');
    var acctHidden = document.getElementById('rule_scope_account_hidden');
    var debounce = null;
    function renderRows(rows, rules) {
      matchesDiv.innerHTML = '';
      if (!rows || !rows.length) { matchesDiv.textContent = 'Aucune opération correspondante'; return; }
      var unassigned = [];
      var different = [];
      var matchingCount = 0;
      var crit = parseInt((document.getElementById('rule_category_level_hidden')||{value:'0'}).value, 10) || 0;
      var targetVal = (document.getElementById('rule_valeur_a_affecter')||{value:'0'}).value || '0';
      rows.forEach(function(r){
          var catField = 'cat' + crit + '_id';
          var cur = (r[catField] === null || typeof r[catField] === 'undefined') ? '0' : String(r[catField]);
          if (cur === '0' || cur === '' || cur === null) {
            unassigned.push(r);
          } else if (String(cur) === String(targetVal)) {
            matchingCount++;
          } else {
            different.push(r);
          }
      });

      // Summary line
      var summary = document.createElement('div'); summary.style.marginBottom = '8px'; summary.style.fontSize = '0.95rem'; summary.style.color = '#333';
        summary.textContent = 'Correspondances trouvées: ' + rows.length + ' — ' + matchingCount + ': opérations déjà à la valeur';
      matchesDiv.appendChild(summary);

      // Rules block
      try {
        var rulesWrap = document.createElement('div');
        var rulesHdr = document.createElement('div');
        rulesHdr.textContent = 'Règles correspondant au libellé (' + (rules && rules.length ? rules.length : 0) + ')';
        rulesHdr.style.fontWeight = '700'; rulesHdr.style.cursor = 'pointer'; rulesHdr.style.padding = '6px 0'; rulesHdr.style.borderBottom = '1px solid #eee';
        var rulesContent = document.createElement('div'); rulesContent.style.marginBottom = '8px'; rulesContent.style.border = '1px solid #efefef'; rulesContent.style.display = 'none';
        if (rules && rules.length) {
          rules.forEach(function(rr){
            var el = document.createElement('div'); el.style.padding = '6px 8px'; el.style.borderBottom = '1px solid #f8f8f8';
            var tgtLabel = rr.valeur_label || (rr.valeur_a_affecter ? String(rr.valeur_a_affecter) : '—');
            var scopeLabel = rr.scope_account_id ? ('compte ' + rr.scope_account_id) : 'global';
            el.innerHTML = '<strong>' + (rr.pattern || '') + '</strong> &nbsp; → ' + tgtLabel + ' <em style="color:#666">(' + scopeLabel + ')</em>';
            rulesContent.appendChild(el);
          });
        } else {
          var none = document.createElement('div'); none.style.padding = '6px 8px'; none.textContent = 'Aucune règle trouvée'; rulesContent.appendChild(none);
        }
        rulesHdr.addEventListener('click', function(){ rulesContent.style.display = (rulesContent.style.display === 'none') ? 'block' : 'none'; try { adjustModalHeights(); } catch(e){} });
        rulesWrap.appendChild(rulesHdr); rulesWrap.appendChild(rulesContent); matchesDiv.appendChild(rulesWrap);
      } catch(e) { /* ignore rendering rules */ }

      // Unassigned collapsible
      var wrap1 = document.createElement('div');
      var hdr1 = document.createElement('div'); hdr1.textContent = 'Opérations non affectées (' + unassigned.length + ')'; hdr1.style.fontWeight = '700'; hdr1.style.cursor = 'pointer'; hdr1.style.padding = '6px 0'; hdr1.style.borderBottom = '1px solid #eee';
      var content1 = document.createElement('div'); content1.style.display = 'none'; content1.style.border = '1px solid #efefef'; content1.style.marginBottom = '8px';
      unassigned.forEach(function(r){ var el = document.createElement('div'); el.style.padding = '6px 8px'; el.style.borderBottom = '1px solid #f8f8f8'; el.dataset.txid = r.id; el.className = 'match unassigned'; el.dataset.current = (r['cat' + crit + '_id'] || '0'); el.innerHTML = '<strong>' + (r.booking_date || '') + '</strong> &nbsp; ' + (r.amount ? Number(r.amount).toFixed(2) : '') + ' &nbsp; ' + (r.description ? r.description : ''); content1.appendChild(el); });
      hdr1.addEventListener('click', function(){ content1.style.display = (content1.style.display === 'none') ? 'block' : 'none'; adjustModalHeights(); });
      wrap1.appendChild(hdr1); wrap1.appendChild(content1); matchesDiv.appendChild(wrap1);

      // Different-assigned collapsible (show current label)
      var wrap2 = document.createElement('div');
      var hdr2 = document.createElement('div'); hdr2.textContent = 'Opérations affectées à une autre valeur (' + different.length + ')'; hdr2.style.fontWeight = '700'; hdr2.style.cursor = 'pointer'; hdr2.style.padding = '6px 0'; hdr2.style.borderBottom = '1px solid #eee';
      var content2 = document.createElement('div'); content2.style.display = 'none'; content2.style.border = '1px solid #efefef';
      different.forEach(function(r){ var curId = (r['cat' + crit + '_id'] || '0'); var label = (catLabels[curId] || curId); var el = document.createElement('div'); el.style.padding = '6px 8px'; el.style.borderBottom = '1px solid #f8f8f8'; el.dataset.txid = r.id; el.className = 'match different'; el.dataset.current = curId; el.innerHTML = '<strong>' + (r.booking_date || '') + '</strong> &nbsp; ' + (r.amount ? Number(r.amount).toFixed(2) : '') + ' &nbsp; ' + (r.description ? r.description : '') + ' <em style="color:#666">— ' + label + '</em>'; content2.appendChild(el); });
      hdr2.addEventListener('click', function(){ content2.style.display = (content2.style.display === 'none') ? 'block' : 'none'; adjustModalHeights(); });
      wrap2.appendChild(hdr2); wrap2.appendChild(content2); matchesDiv.appendChild(wrap2);
      try { adjustModalHeights(); } catch(e){}
    }
    function fetchMatches() {
      if (!inp) return;
      var q = inp.value || '';
      var acct = acctHidden ? acctHidden.value : '';
      // fallback to visible scope select if hidden value not set
      if (!acct) {
        var scopeSel = document.getElementById('rule_scope_account'); if (scopeSel && scopeSel.value) acct = scopeSel.value;
      }
      // fallback to form's txid row account
      if (!acct) {
        var form = document.getElementById('createRuleForm');
        if (form && form.dataset && form.dataset.txid) {
          var row = document.getElementById('tx_' + form.dataset.txid);
          if (row) acct = row.getAttribute('data-account') || '';
        }
      }
      if (!acct) { matchesDiv.textContent = 'Pas de compte sélectionné'; return; }
      if (!q) { matchesDiv.textContent = 'Entrez un motif pour voir les opérations correspondantes'; return; }
      var clevel = (document.getElementById('rule_category_level_hidden')||{value:'0'}).value || '0';
      var val = (document.getElementById('rule_valeur_a_affecter')||{value:'0'}).value || '0';
      var txUrl = 'mon-site/api/search_tx.php?account_id=' + encodeURIComponent(acct) + '&q=' + encodeURIComponent(q) + '&category_level=' + encodeURIComponent(clevel) + '&valeur=' + encodeURIComponent(val);
      var rulesUrl = 'mon-site/api/search_rules.php?account_id=' + encodeURIComponent(acct) + '&q=' + encodeURIComponent(q) + '&category_level=' + encodeURIComponent(clevel);
      Promise.all([fetch(txUrl).then(function(r){ return r.json(); }).catch(function(){ return null; }), fetch(rulesUrl).then(function(r){ return r.json(); }).catch(function(){ return null; })])
        .then(function(results){
          var txRes = results[0];
          var rulesRes = results[1];
          var txRows = (txRes && txRes.ok && Array.isArray(txRes.rows)) ? txRes.rows : [];
          var ruleExistsNote = (txRes && txRes.rule_exists);
          var rules = (rulesRes && rulesRes.ok && Array.isArray(rulesRes.rules)) ? rulesRes.rules : [];
          renderRows(txRows, rules);
          if (ruleExistsNote) {
            var note = document.createElement('div');
            note.textContent = 'La règle existe déjà';
            note.style.color = 'orange';
            note.style.fontWeight = '700';
            note.style.marginTop = '8px';
            matchesDiv.insertBefore(note, matchesDiv.firstChild);
          }
        }).catch(function(){ matchesDiv.textContent = 'Erreur réseau'; });
    }
    if (inp) {
      inp.addEventListener('input', function(){ clearTimeout(debounce); debounce = setTimeout(fetchMatches, 300); });
      // do not run initial fetch on page load; we'll trigger when modal opens so scope/account is set
    }
  })();

  // Create rule via AJAX and optionally apply
  function createRuleAndApply(mode) {
    var form = document.getElementById('createRuleForm');
    var data = new FormData(form);
    // ensure hidden fields present
    var levelH = document.getElementById('rule_category_level_hidden'); if (levelH) data.set('category_level', levelH.value || '0');
    var scopeH = document.getElementById('rule_scope_account_hidden'); if (scopeH) data.set('scope_account_id', scopeH.value || '');
    // client-side validation to provide clearer feedback
    var patternVal = (document.getElementById('rule_pattern')||{value:''}).value.trim();
    var clevelVal = (levelH||{value:'0'}).value || '0';
    var valeurSel = document.getElementById('rule_valeur_a_affecter');
    var valeurVal = (valeurSel ? valeurSel.value : '0') || '0';
    var missing = [];
    if (!patternVal) missing.push('motif');
    if (!clevelVal || parseInt(clevelVal,10) <= 0) missing.push('critère');
    if (!valeurVal || valeurVal === '0') missing.push('valeur à affecter');
    if (missing.length) { alert('Veuillez renseigner: ' + missing.join(', ')); return; }
    fetch('mon-site/api/create_rule.php', { method: 'POST', body: data }).then(function(r){ return r.json(); }).then(function(j){
      if (!j) { alert('Erreur création règle'); return; }
      var ruleId = null;
      var creationExists = false;
      if (j.exists) {
        creationExists = true;
        ruleId = j.rule_id || null;
      } else {
        if (!j.ok) { alert('Erreur création règle'); return; }
        ruleId = j.rule_id || null;
      }
      if (!ruleId) { alert('ID règle manquant'); return; }
      // If the rule already existed, update it with the popup parameters before applying
      var proceedAfterUpdate = Promise.resolve({ ok: true, rule_id: ruleId });
      if (creationExists) {
        var updFd = new FormData();
        updFd.append('id', ruleId);
        // copy relevant fields from the create form
        try {
          updFd.append('pattern', (document.getElementById('rule_pattern')||{value:''}).value || '');
          updFd.append('category_level', (document.getElementById('rule_category_level_hidden')||{value:'0'}).value || '0');
          updFd.append('valeur_a_affecter', (document.getElementById('rule_valeur_a_affecter')||{value:'0'}).value || '0');
          updFd.append('scope_account_id', (document.getElementById('rule_scope_account_hidden')||{value:''}).value || '');
          updFd.append('priority', (document.getElementById('rule_priority')||{value:'100'}).value || '100');
          updFd.append('active', document.querySelector('#createRuleForm input[name=active]') && document.querySelector('#createRuleForm input[name=active]').checked ? '1' : '0');
        } catch(e) {}
        proceedAfterUpdate = fetch('mon-site/api/update_rule.php', { method: 'POST', body: updFd }).then(function(r){ return r.json(); });
      }

      proceedAfterUpdate.then(function(updRes){
        if (!updRes || !updRes.ok) { alert('Erreur mise à jour règle existante'); return; }
        ruleId = updRes.rule_id || ruleId;

        // continue with apply flow
        // gather tx ids to apply
        var txIds = [];
        if (mode === 'all' || mode === 'unassigned') {
          var selector = (mode === 'unassigned') ? '#rule_matches .match.unassigned' : '#rule_matches div[data-txid]';
          var nodes = Array.from(document.querySelectorAll(selector));
          nodes.forEach(function(n){ txIds.push(n.dataset.txid); });
        } else {
          // get current tx id from form dataset
          var lastOpen = form.dataset.txid || '';
          if (lastOpen) txIds.push(lastOpen);
        }
        if (txIds.length === 0) { document.getElementById('createRuleModal').style.display = 'none'; showToast('Règle créée'); return; }
        if (mode === 'all' || mode === 'unassigned') {
        // confirm with user showing number of operations
        var cnt = txIds.length;
        if (cnt === 0) { document.getElementById('createRuleModal').style.display = 'none'; showToast('Règle créée'); return; }
        var promptText = (mode === 'unassigned') ? ('Appliquer la règle à ' + cnt + ' opérations non affectées ?') : ('Appliquer la règle à ' + cnt + ' opérations ?');
        if (!confirm(promptText)) return;
        // call bulk endpoint
        var payload = new URLSearchParams(); payload.append('rule_id', ruleId); payload.append('tx_ids', txIds.join(','));
        fetch('mon-site/api/apply_rule_bulk.php', { method: 'POST', body: payload }).then(function(r){ return r.json(); }).then(function(res){
          if (!res || !res.ok) { alert('Erreur application en masse' + (res && (res.error || res.message) ? ': ' + (res.error || res.message) : '')); return; }
          // update DOM for affected rows
          (res.affected || []).forEach(function(a){
            var txid = a.tx_id;
            var sel = document.querySelector('select.cat-select[data-txid="' + txid + '"]');
            if (sel) { sel.value = String(a.new); sel.dispatchEvent(new Event('change')); }
          });
          document.getElementById('createRuleModal').style.display = 'none';
          if ((res.count || 0) > 0) { window.location.reload(); }
          else { alert('Aucune opération modifiée'); window.location.reload(); }
        }).catch(function(){ alert('Erreur réseau application en masse'); });
        } else {
          // single apply via existing endpoint
          var lastOpen = form.dataset.txid || '';
          var txid = lastOpen;
          fetch('mon-site/api/apply_rule.php', { method: 'POST', body: new URLSearchParams({ rule_id: ruleId, tx_id: txid }) }).then(function(r){ return r.json(); }).then(function(res){ if (res && res.ok) {
              document.getElementById('createRuleModal').style.display = 'none'; window.location.reload();
            } else { alert('Erreur application'); }
          }).catch(function(){ alert('Erreur réseau'); });
        }
      }).catch(function(){ alert('Erreur réseau mise à jour règle'); });
    }).catch(function(){ alert('Erreur réseau création règle'); });
  }

  document.getElementById('createRuleApply').addEventListener('click', function(){ createRuleAndApply(false); });
  document.getElementById('createRuleApplyAll').addEventListener('click', function(){ createRuleAndApply('all'); });
  var btnUnassigned = document.getElementById('createRuleApplyUnassigned'); if (btnUnassigned) btnUnassigned.addEventListener('click', function(){ createRuleAndApply('unassigned'); });
  // Parent/child selects behavior inside modal
  (function(){
    var parentSel = document.getElementById('cc_parent');
    var childSel = document.getElementById('cc_child');
    var childRow = document.getElementById('cc_child_row');
    var renameRow = document.getElementById('cc_rename_badge_row');
    if (parentSel) {
      parentSel.addEventListener('change', function(){
        try {
          var pv = this.value || '0';
          childSel.innerHTML = '';
          if (renameRow) try { renameRow.style.display = 'none'; } catch(e){}
          if (pv && pv !== '0') {
            // populate child select with existing children for this parent
            var crit = document.getElementById('cc_criterion').value || '';
            var parents = catTree[crit] || {};
            var node = parents[pv] || null;
            var firstOpt = document.createElement('option'); firstOpt.value = '0'; firstOpt.textContent = 'Créer un nouveau sous-code'; childSel.appendChild(firstOpt);
            if (node && node.children && node.children.length) {
              node.children.forEach(function(ch){ var o = document.createElement('option'); o.value = ch.id; o.textContent = ch.label; childSel.appendChild(o); });
            }
            childSel.disabled = false; childRow.style.display = 'block';
          } else {
            childSel.disabled = true; childRow.style.display = 'none';
          }
        } catch(e) { console.error(e); }
      });
    }
    if (childSel) {
      childSel.addEventListener('change', function(){
        var submitBtn = document.getElementById('cc_submit');
        if (this.value && this.value !== '0') { if (submitBtn) submitBtn.textContent = 'Renommer'; } else { if (submitBtn) submitBtn.textContent = 'Créer'; }
      });
    }
  })();

  document.getElementById('createCatForm').addEventListener('submit', function(ev){
    ev.preventDefault();
    var modal = document.getElementById('createCatModal');
    var fd = new FormData(this);
    var parentSel = document.getElementById('cc_parent');
    var childSel = document.getElementById('cc_child');
    var parentVal = parentSel ? parentSel.value : '0';
    var childVal = childSel ? (childSel.value || '0') : '0';
    // Determine operation:
    // - parentVal == '0' => add_level1
    // - parentVal != '0' and childVal == '0' => add_level2 (create new under parent)
    // - parentVal != '0' and childVal != '0' => edit (rename existing child)
    if (parentVal === '0' || parentVal === 0) {
      fd.set('action','add_level1');
    } else {
      if (childVal && childVal !== '0') {
        // rename existing level2
        fd.set('action','edit');
        fd.set('cat_id', childVal);
      } else {
        // create level2: compose label as ParentLabel/LabelInput
        var parentOption = parentSel.options[parentSel.selectedIndex];
        var parentLabel = parentOption ? parentOption.text : '';
        var inputLabel = document.getElementById('cc_label').value || '';
        var fullLabel = parentLabel + '/' + inputLabel;
        fd.set('label', fullLabel);
        fd.set('action','add_level2');
        fd.set('parent_id', parentVal);
      }
    }
    fd.set('ajax','1');
    var _action = fd.get('action');
    var _parentId = fd.get('parent_id') || null;
    fetch('categories.php', { method: 'POST', body: fd }).then(function(r){ return r.json(); }).then(function(j){
      if (!j || !j.ok) { alert('Erreur création/modification: ' + (j && j.error ? j.error : 'unknown')); return; }
      var newId = j.id; var newLabel = j.label; var criterion = j.criterion;
      // Update client-side catTree so modal lists are rebuilt with latest data next time
      try {
        var critKey = String(criterion);
        if (!_action) _action = 'add_level1';
        if (_action === 'add_level1') {
          if (!catTree[critKey]) catTree[critKey] = {};
          catTree[critKey][String(newId)] = { info: { id: newId, label: newLabel, criterion: criterion, parent_id: null }, children: [] };
        } else if (_action === 'add_level2') {
          var pid = String(_parentId || fd.get('parent_id') || '');
          if (!catTree[critKey]) catTree[critKey] = {};
          if (!catTree[critKey][pid]) catTree[critKey][pid] = { info: null, children: [] };
          // push child
          catTree[critKey][pid].children.push({ id: newId, label: newLabel });
        } else if (_action === 'edit') {
          var catId = String(fd.get('cat_id'));
          if (catTree[critKey]) {
            Object.keys(catTree[critKey]).forEach(function(pid){
              var node = catTree[critKey][pid];
              if (node && node.info && String(node.info.id) === catId) node.info.label = newLabel;
              if (node && node.children) {
                node.children.forEach(function(ch){ if (String(ch.id) === catId) ch.label = newLabel; });
              }
            });
          }
        }
      } catch (e) { console.error('update catTree failed', e); }
      // update selects across page: insert new or update label if rename
      var selCandidates = Array.from(document.querySelectorAll('select'));
      selCandidates.forEach(function(sel){
        var crit = null;
        if (sel.name && sel.name.match(/^cat(\d+)/)) { crit = parseInt(sel.name.replace(/^cat(\d+).*$/, '$1'), 10); }
        else if (sel.classList && sel.classList.contains('cat-select') && sel.dataset && sel.dataset.field) {
          var m = sel.dataset.field.match(/^cat(\d+)_id$/); if (m) crit = parseInt(m[1],10);
        }
        if (crit === null || isNaN(crit)) return;
        if (crit === parseInt(criterion,10)) {
          if (fd.get('action') === 'edit') {
            // rename existing option if present
            var opt = sel.querySelector('option[value="' + newId + '"]'); if (opt) opt.textContent = newLabel;
          } else {
            var opt2 = document.createElement('option'); opt2.value = newId; opt2.textContent = newLabel; sel.appendChild(opt2);
          }
        }
      });

      // locate triggering select to set value
      var trigger = null; var ttx = modal.dataset.trigger_txid || ''; var tfield = modal.dataset.trigger_field || '';
      if (ttx && tfield) {
        trigger = document.querySelector('select.cat-select[data-txid="' + ttx + '"][data-field="' + tfield + '"]');
      }
      if (!trigger && modal.dataset.trigger_name) { trigger = document.querySelector('select[name="' + modal.dataset.trigger_name + '"]'); }
      if (trigger) { trigger.value = (fd.get('action') === 'edit') ? fd.get('cat_id') : newId; trigger.dispatchEvent(new Event('change')); }
      // show confirmation toast
      try {
        var op = fd.get('action');
        if (op === 'edit') showToast('Catégorie renommée'); else showToast('Catégorie créée');
      } catch(e){}
      modal.style.display = 'none';
    }).catch(function(e){ alert('Erreur AJAX: '+e); });
  });
  
  // show toast after successful create/rename inside modal flow
  (function(){
    var originalFetch = window.fetch;
    // we don't override fetch globally; instead add toast calls in the promise resolution above.
  })();
})();
</script>
<script>
// Add button/modal behaviour
document.addEventListener('DOMContentLoaded', function(){
  var bottom = document.getElementById('bottomAddBtn');
  var modal = document.getElementById('addModal');
  var form = document.getElementById('addTxForm');
  var cancel = document.getElementById('addCancel');
  if (!bottom || !modal || !form) return;
  // Pre-fill account selection when opening modal using current page selector
  bottom.addEventListener('click', function(){
    try {
      var sel = form.querySelector('select[name=account_id]');
      var pageSel = document.querySelector('select[name=account]');
      if (sel) {
        if (pageSel && pageSel.value) {
          // If page selector is a group (g:...), pick first available option in modal
          if (String(pageSel.value).indexOf('g:') === 0) {
            // choose first non-empty option in modal
            var opt = sel.querySelector('option[value]:not([value=""])');
            if (opt) sel.value = opt.value;
          } else {
            // try to set exact value (matches modal account ids)
            var tryVal = pageSel.value;
            // if an option with that value exists, use it
            if (sel.querySelector('option[value="' + tryVal + '"]')) {
              sel.value = tryVal;
            } else {
              // fallback to first option
              var opt2 = sel.querySelector('option[value]:not([value=""])'); if (opt2) sel.value = opt2.value;
            }
          }
        } else {
          // fallback: pick first option
          var opt3 = sel.querySelector('option[value]:not([value=""])'); if (opt3) sel.value = opt3.value;
        }
        sel.focus();
      }
    } catch (e) { /* ignore */ }
    modal.style.display = 'flex';
  });

  // If the page account selector changes while modal is open, update modal account field
  var pageAccountSelect = document.querySelector('select[name=account]');
  if (pageAccountSelect) {
    pageAccountSelect.addEventListener('change', function(){
      try {
        var sel = form.querySelector('select[name=account_id]');
        if (!sel) return;
        if (modal.style.display === 'flex' || modal.style.display === '') {
          // same logic as above
          if (String(this.value).indexOf('g:') === 0) {
            var opt = sel.querySelector('option[value]:not([value=""])'); if (opt) sel.value = opt.value;
          } else if (sel.querySelector('option[value="' + this.value + '"]')) {
            sel.value = this.value;
          }
        }
      } catch (e) { }
    });
  }
  cancel.addEventListener('click', function(){ modal.style.display = 'none'; });
  form.addEventListener('submit', function(ev){
    ev.preventDefault();
    var fd = new FormData(form);
    fetch('mon-site/api/add_tx.php', { method: 'POST', body: fd })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (j && j.ok) {
          modal.style.display = 'none';
          form.reset();
          try {
            var anchor = j.id ? '#tx_' + encodeURIComponent(j.id) : '';
            location.href = location.pathname + (location.search || '') + anchor;
          } catch(e) { location.reload(); }
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
  <div id="todelMarkActions" style="margin-top:8px">
    <button id="todelMark" class="btn btn-warning">Supprimer la ligne</button>
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
    var todelMarkActions = document.getElementById('todelMarkActions');
    if (st === 'TODEL') {
      todelActions.style.display = 'block';
      if (todelMarkActions) todelMarkActions.style.display = 'none';
    } else {
      todelActions.style.display = 'none';
      if (todelMarkActions) todelMarkActions.style.display = 'block';
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
        if (j && j.ok) { currentTr.parentNode.removeChild(currentTr); hidePopup(); showToast('Ligne supprimée définitivement'); }
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
          showToast('Ligne restaurée');
        } else alert('Erreur: ' + (j && j.error ? j.error : 'action échouée'));
      }).catch(function(e){ alert('Erreur réseau: '+e); });
  });

  // Mark a row as to-delete (soft mark)
  var todelMarkBtn = document.getElementById('todelMark');
  if (todelMarkBtn) {
    todelMarkBtn.addEventListener('click', function(){
      if (!currentTr) return;
      var txid = currentTr.dataset.txid;
      if (!confirm('Confirmer la suppression de cette ligne ? Cette action est irréversible.')) return;
      fetch('mon-site/api/tx_action.php', { method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'}, body: 'action=delete&id='+encodeURIComponent(txid) })
      .then(r=>r.json()).then(function(j){
        if (j && j.ok) {
          hidePopup();
          // reload the transactions page to reflect deletion and preserve server state
          location.reload();
        } else {
          alert('Erreur: ' + (j && j.error ? j.error : 'action échouée'));
        }
      }).catch(function(e){ alert('Erreur réseau: '+e); });
    });
  }

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
