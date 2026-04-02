<?php
// Mobile — affichage 1 écriture BOOK à la fois, navigation prev/next côté serveur
ini_set('display_errors', 1);
error_reporting(E_ALL);

try {
  $pdo = require __DIR__ . '/mon-site/api/db.php';
} catch (Throwable $e) {
  echo '<pre>' . htmlspecialchars((string)$e) . '</pre>';
  exit;
}

// Handle POST: save category and redirect back (PRG)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['tx_id']) && !empty($_POST['field'])) {
  $allowed = ['cat1_id','cat2_id','cat3_id','cat4_id'];
  $field = $_POST['field'];
  if (in_array($field, $allowed, true)) {
    $val = ($_POST['value'] ?? '') !== '' ? $_POST['value'] : null;
    $stmt = $pdo->prepare("UPDATE transactions SET `$field` = :val WHERE id = :id");
    $stmt->execute([':val' => $val, ':id' => $_POST['tx_id']]);
  }
  $idx = (int)($_POST['idx'] ?? 0);
  $acct = $_POST['acct'] ?? '';
  header('Location: mobile.php?idx=' . $idx . ($acct !== '' ? '&account=' . urlencode($acct) : ''));
  exit;
}

  // Prevent client/browser caching of the mobile review page
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: 0');

// Accounts list: order by numero_affichage (NULLs last), then numero_affichage, then name
// Load accounts including secondary balance and account type
$accs = $pdo->query('SELECT id, name, balance, solde2eme, account_type, currency, numero_affichage FROM accounts ORDER BY (numero_affichage IS NULL), numero_affichage ASC, name ASC')->fetchAll(PDO::FETCH_ASSOC);
$acctSel = $_GET['account'] ?? ($_COOKIE['selected_account'] ?? '');

// Build quick maps for balances, second balances, types and names
$accBalances = [];
$accSecond = [];
$accTypeMap = [];
foreach ($accs as $a) { $accBalances[$a['id']] = (float)($a['balance'] ?? 0.0); $accSecond[$a['id']] = (float)($a['solde2eme'] ?? 0.0); $accTypeMap[$a['id']] = $a['account_type'] ?? null; }

// Check if transactions.accounting_date column exists (migration may not have been run yet)
$hasAccountingDate = false;
try {
  $cols = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'accounting_date'")->fetchAll();
  $hasAccountingDate = !empty($cols);
} catch (Throwable $e) { $hasAccountingDate = false; }

// Build group children map from categories criterion=0 (label expected to contain account id)
// groupChildren will be built after we load categories into $allCats
// initialize to avoid undefined variable notices in older PHP setups
$groupChildren = [];

// Defaults for variables that may be missing in some environments (avoid PHP notices)
$idx = isset($_GET['idx']) ? (int)$_GET['idx'] : 0;
$showPending = isset($_GET['show_pending']) && $_GET['show_pending'] !== '0' ? true : false;
$total = $total ?? 0;
$tx = $tx ?? null;
$displayBalance = $displayBalance ?? null;
$groupVirtualBalance = $groupVirtualBalance ?? null;
$isPending = $isPending ?? false;

// Criterion names
$criterionNames = [];
for ($i = 1; $i <= 4; $i++) {
  $s = $pdo->prepare('SELECT `value` FROM settings WHERE `key` = :k');
  $s->execute([':k' => "criterion_{$i}_name"]);
  $criterionNames[$i] = $s->fetchColumn() ?: "Critère $i";
}

// Category trees for selects
$allCats = $pdo->query('SELECT * FROM categories ORDER BY criterion, sort_order, label')->fetchAll(PDO::FETCH_ASSOC);
$catTree = [];
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
$catLabels = [];
foreach ($allCats as $c) { $catLabels[$c['id']] = $c['label']; }

// map category id => criterion (1..4 or 0)
$catCriteria = [];
foreach ($allCats as $c) { $catCriteria[$c['id']] = (int)$c['criterion']; }

// Build group children map (criterion=0): parent_id => [account_id, ...]
$groupChildren = [];
foreach ($allCats as $c) {
  if ((int)$c['criterion'] === 0 && $c['parent_id'] !== null) {
    $groupChildren[(int)$c['parent_id']][] = $c['label'];
  }
}
// Load the transaction at index $idx (mobile card navigation)
$idx = isset($_GET['idx']) ? (int)$_GET['idx'] : 0;
$showPending = isset($_GET['show_pending']) ? ($_GET['show_pending'] === '1') : true;


$where = [];
$params = [];
if (!empty($acctSel)) {
  if (strpos((string)$acctSel, 'g:') === 0) {
    // group selection: restrict to accounts belonging to the group
    $gid = (int)substr((string)$acctSel, 2);
    $acctIds = $groupChildren[$gid] ?? [];
    if (!empty($acctIds)) {
      $placeholders = [];
      foreach ($acctIds as $i => $aid) {
        $ph = ':g' . $i;
        $placeholders[] = $ph;
        $params[$ph] = $aid;
      }
      $where[] = 't.account_id IN (' . implode(',', $placeholders) . ')';
    } else {
      // empty group -> match nothing
      $where[] = '1=0';
    }
  } else {
    $where[] = 't.account_id = :account';
    $params[':account'] = $acctSel;
  }
}
if (!$showPending) {
  $where[] = "UPPER(t.status) = 'BOOK'";
}
// If a specific transaction id is provided, compute its index within the current filter
if (isset($_GET['tx_id']) && $_GET['tx_id'] !== '') {
  $txIdParam = $_GET['tx_id'];
  try {
    $txInfoStmt = $pdo->prepare('SELECT booking_date, amount, id, account_id FROM transactions WHERE id = :id LIMIT 1');
    $txInfoStmt->execute([':id' => $txIdParam]);
    $txInfo = $txInfoStmt->fetch(PDO::FETCH_ASSOC);
    if ($txInfo) {
      // Compute how many rows come before this transaction according to ORDER BY booking_date DESC, amount DESC, id DESC
      $beforeConds = [];
      $beforeParams = $params; // start with existing filter bindings (account/group, show_pending)
      // condition for rows with booking_date greater than tx's date
      $beforeConds[] = '(t.booking_date > :bdate)';
      $beforeParams[':bdate'] = $txInfo['booking_date'];
      // same booking_date but amount greater
      $beforeConds[] = '(t.booking_date = :bdate AND t.amount > :amount)';
      $beforeParams[':amount'] = $txInfo['amount'];
      // same booking_date and amount but id greater
      $beforeConds[] = '(t.booking_date = :bdate AND t.amount = :amount AND t.id > :id)';
      $condSql = '(' . implode(' OR ', $beforeConds) . ')';
      $fullWhere = $where ? (' WHERE ' . implode(' AND ', $where) . ' AND ' . $condSql) : (' WHERE ' . $condSql);
      $countSqlForIdx = 'SELECT COUNT(*) FROM transactions t' . $fullWhere;
      $countStmtForIdx = $pdo->prepare($countSqlForIdx);
      // bind existing filter params
      foreach ($beforeParams as $k => $v) {
        $countStmtForIdx->bindValue($k, $v);
      }
      if (!array_key_exists(':id', $beforeParams)) $countStmtForIdx->bindValue(':id', $txInfo['id']);
      $countStmtForIdx->execute();
      $computedIdx = (int)$countStmtForIdx->fetchColumn();
      if ($computedIdx < 0) $computedIdx = 0;
      $idx = $computedIdx;
    }
  } catch (Throwable $e) {
    // on error, keep provided idx
  }
}
$countSql = 'SELECT COUNT(*) FROM transactions t' . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
$cntStmt = $pdo->prepare($countSql);
foreach ($params as $k => $v) $cntStmt->bindValue($k, $v);
$cntStmt->execute();
$total = (int)$cntStmt->fetchColumn();

$sql = 'SELECT t.*, a.name AS account_name FROM transactions t LEFT JOIN accounts a ON a.id = t.account_id' . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
$sql .= ' ORDER BY t.booking_date DESC, t.id DESC LIMIT 1 OFFSET :offset';
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':offset', max(0, $idx), PDO::PARAM_INT);
$stmt->execute();
$tx = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

  // compute displayBalance and groupVirtualBalance for this tx
  $displayBalance = null;
  $groupVirtualBalance = null;
  // new variables requested
  $startSold = null;
  $startVirtualSold = null;
  // determine if selection is a group (available to rendering scope)
  $isGroupSel = is_string($acctSel) && strpos($acctSel, 'g:') === 0;
  if ($tx) {
    $acctId = $tx['account_id'];
    if ($isGroupSel) {
      $gid = (int)substr($acctSel, 2);
      $acctIds = $groupChildren[$gid] ?? [];
      // compute startSold: for group, use balance of first account only
      $firstAid = $acctIds[0] ?? null;
      $startSold = ($firstAid !== null) ? ($accBalances[$firstAid] ?? 0.0) : 0.0;
      // startVirtualSold is sum of balances of all accounts in the group
      $startVirtualSold = 0.0;
      foreach ($acctIds as $aid) { if (isset($accBalances[$aid])) $startVirtualSold += $accBalances[$aid]; }

      if (!empty($acctIds)) {
        $placeholders = [];
        $bindings = [];
        foreach ($acctIds as $i => $aid) { $ph = ':g' . $i; $placeholders[] = $ph; $bindings[$ph] = $aid; }
        // Sum of previous BOOK transactions across the group (newer in ordering)
        $qBook = "SELECT COALESCE(SUM(amount),0) FROM transactions WHERE account_id IN (" . implode(',', $placeholders) . ") AND (booking_date > :bdate OR (booking_date = :bdate AND id > :id)) AND UPPER(status) = 'BOOK'";
        $stmtBook = $pdo->prepare($qBook);
        $stmtBook->bindValue(':bdate', $tx['booking_date']); $stmtBook->bindValue(':id', $tx['id']);
        foreach ($bindings as $k => $v) $stmtBook->bindValue($k, $v);
        $stmtBook->execute();
        $groupBookNewer = (float)$stmtBook->fetchColumn();
        $displayBalance = $startSold - $groupBookNewer;

        // Sum of previous transactions counted in virtual across the group
        $qVirt = 'SELECT COALESCE(SUM(amount),0) FROM transactions WHERE account_id IN (' . implode(',', $placeholders) . ') AND (booking_date > :bdate OR (booking_date = :bdate AND id > :id)) AND CountInVirtual = 1';
        $stmtVirt = $pdo->prepare($qVirt);
        $stmtVirt->bindValue(':bdate', $tx['booking_date']); $stmtVirt->bindValue(':id', $tx['id']);
        foreach ($bindings as $k => $v) $stmtVirt->bindValue($k, $v);
        $stmtVirt->execute();
        $groupVirtNewer = (float)$stmtVirt->fetchColumn();
        $groupVirtualBalance = $startVirtualSold - $groupVirtNewer;
      } else {
        // no accounts in group -> treat as zero
        $startSold = 0.0; $startVirtualSold = 0.0; $displayBalance = 0.0; $groupVirtualBalance = 0.0;
      }
    } else {
      // single account selection
      $startSold = $accBalances[$acctId] ?? 0.0;
      // if single selected account is a card, include the secondary balance
      if (isset($accTypeMap[$acctId]) && $accTypeMap[$acctId] === 'card') {
        $startSold += ($accSecond[$acctId] ?? 0.0);
      }
      $startVirtualSold = $startSold;
      // Sum of previous transactions for this account (ignore status)
      $sumNewer = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE account_id = :aid AND (booking_date > :bdate OR (booking_date = :bdate AND id > :id))");
      $sumNewer->execute([':aid' => $acctId, ':bdate' => $tx['booking_date'], ':id' => $tx['id']]);
      $newer = (float)$sumNewer->fetchColumn();
      $displayBalance = $startSold - $newer;

      // Sum of previous CountInVirtual transactions for this account (still computed but will be hidden in UI)
      $sumVirt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE account_id = :aid AND (booking_date > :bdate OR (booking_date = :bdate AND id > :id)) AND CountInVirtual = 1");
      $sumVirt->execute([':aid' => $acctId, ':bdate' => $tx['booking_date'], ':id' => $tx['id']]);
      $virtNewer = (float)$sumVirt->fetchColumn();
      $groupVirtualBalance = $startVirtualSold - $virtNewer;
    }
  }

  // Determine badge and virtual counting for the single displayed transaction using stored fields
  $today = date('Y-m-d');
  $statusUpper = strtoupper((string)($tx['status'] ?? ''));
  $badgeHtml = '';
  $countInVirtualRow = false;
  $storedBadge = isset($tx['badge']) && $tx['badge'] !== '' ? $tx['badge'] : null;
  $storedCount = isset($tx['CountInVirtual']) ? (int)$tx['CountInVirtual'] : null;
  if ($statusUpper === 'MANUAL') {
    $badgeHtml = '<span class="badge-manual">Saisie manuelle</span>';
    $countInVirtualRow = false;
  } elseif ($storedBadge !== null) {
    switch ($storedBadge) {
      case 'nextmonth': $badgeHtml = '<span class="badge-nextmonth">Mois prochain</span>'; break;
      case 'today': $badgeHtml = '<span class="badge-today">Aujourd\'hui</span>'; break;
      case 'pending': $badgeHtml = '<span class="badge-pending">Paiement différé</span>'; break;
      case 'paid': $badgeHtml = '<span class="badge-paid">Payé</span>'; break;
      case 'manual': $badgeHtml = '<span class="badge-manual">Saisie manuelle</span>'; break;
      default: $badgeHtml = '<span class="badge-pending">Paiement différé</span>'; break;
    }
    $countInVirtualRow = ($storedCount === 1);
  } else {
    // legacy fallback: keep previous accounting_date behaviour
    $acctDate = isset($tx['accounting_date']) && $tx['accounting_date'] !== null && $tx['accounting_date'] !== '' ? (string)$tx['accounting_date'] : null;
    if ($statusUpper === 'OTHR') {
      if ($acctDate) {
        if ($today === $acctDate) { $badgeHtml = '<span class="badge-today">Aujourd\'hui</span>'; $countInVirtualRow = false; }
        elseif ($today < $acctDate) { $badgeHtml = '<span class="badge-pending">Paiement différé</span>'; $countInVirtualRow = true; }
        else { $badgeHtml = '<span class="badge-paid">Payé</span>'; $countInVirtualRow = false; }
      } else { $badgeHtml = '<span class="badge-pending">Paiement différé</span>'; $countInVirtualRow = false; }
    }
  }

}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Mobile — bkTool</title>
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>

<!-- Redirect to desktop transactions view when viewport is wide (>832px) -->
<script>
  (function(){
    try {
      // If opened as popup, do not auto-redirect back to transactions even on wide screens
      var sp = new URLSearchParams(location.search);
      var isPopup = sp.get('popup') === '1' || sp.get('popup') === 'true';
      if (typeof window !== 'undefined' && window.innerWidth > 800 && !isPopup) {
        var params = new URLSearchParams();
        <?php if ($acctSel !== ''): ?>params.set('account', <?php echo json_encode((string)$acctSel); ?>);<?php endif; ?>
        params.set('show_pending', <?php echo $showPending ? '1' : '0'; ?>);
        params.set('idx', <?php echo (int)$idx; ?>);
        // Use replace to avoid polluting history
        window.location.replace('transactions.php?' + params.toString());
      }
    } catch(e) { /* ignore */ }
  })();
</script>

<script>
  // screen width display removed
  </script>

  <div class="mobile-page">
  <div id="toast"></div>

  <div class="m-account">
    <!-- selection du compte: déplacée dans la barre de navigation pour gagner de la place -->
  </div>

  <div class="mobile-nav" style="display:flex;align-items:center;justify-content:space-between;margin:0">
    <div style="display:flex;align-items:center;gap:8px">
      <button class="arrow-btn" <?php echo ($idx <= 0) ? 'disabled' : ''; ?> onclick="location.href='mobile.php?idx=0<?php echo ($acctSel !== '' ? '&account=' . urlencode($acctSel) : ''); ?>&show_pending=' + (<?php echo $showPending ? '1' : '0'; ?>)">&lt;&lt;</button>
      <button class="arrow-btn" <?php echo ($idx <= 0) ? 'disabled' : ''; ?> onclick="location.href='mobile.php?idx=<?php echo max(0,$idx-1) . ($acctSel !== '' ? '&account=' . urlencode($acctSel) : ''); ?>&show_pending=' + (<?php echo $showPending ? '1' : 0; ?>)">&lt;</button>
      <span id="mcCounter" style="margin:0 8px"><?php echo ($idx + 1) . ' / ' . $total; ?></span>
    </div>
    <div style="display:flex;align-items:center;gap:8px">
      <select class="m-account-select" onchange="document.cookie='selected_account='+encodeURIComponent(this.value)+';path=/;max-age=31536000'; location.href='mobile.php?idx=' + encodeURIComponent(<?php echo (int)$idx; ?>) + '&account=' + encodeURIComponent(this.value) + '&show_pending=' + (<?php echo $showPending ? '1' : '0'; ?>)" style="min-width:180px">
        <option value="">— Tous les comptes —</option>
        <?php foreach ($accs as $a): ?>
          <option value="<?php echo htmlspecialchars($a['id']); ?>"<?php echo ((string)$acctSel === (string)$a['id']) ? ' selected' : ''; ?>><?php echo htmlspecialchars($a['name'] ?: $a['id']); ?></option>
        <?php endforeach; ?>
        <?php if (!empty($catTree[0])): foreach ($catTree[0] as $pid => $node): if (!$node['info']) continue; ?>
          <option value="g:<?php echo (int)$node['info']['id']; ?>"<?php echo ($acctSel !== '' && (string)$acctSel === ('g:' . (int)$node['info']['id'])) ? ' selected' : ''; ?> style="background:#e0e0e0"><?php echo 'G: ' . htmlspecialchars($node['info']['label']); ?></option>
        <?php endforeach; endif; ?>
      </select>
      <button class="arrow-btn" <?php echo ($idx >= $total - 1) ? 'disabled' : ''; ?> onclick="location.href='mobile.php?idx=<?php echo min($total - 1,$idx+1) . ($acctSel !== '' ? '&account=' . urlencode($acctSel) : ''); ?>&show_pending=' + (<?php echo $showPending ? '1' : 0; ?>)">&gt;</button>
    </div>
  </div>

  <script>
    // Keyboard navigation: ArrowLeft / ArrowRight and '<' '>' characters
    (function(){
      try {
        var idx = <?php echo (int)$idx; ?>;
        var total = <?php echo (int)$total; ?>;
        var acct = <?php echo json_encode((string)$acctSel); ?>;
        var showPending = <?php echo $showPending ? '1' : '0'; ?>;
        document.addEventListener('keydown', function(e){
          // ignore when focus in an input/select/textarea
          var tag = (document.activeElement && document.activeElement.tagName) ? document.activeElement.tagName.toLowerCase() : '';
          if (tag === 'input' || tag === 'select' || tag === 'textarea') return;
          var k = e.key;
          var go = null;
          if (k === 'ArrowLeft' || k === '<') go = Math.max(0, idx - 1);
          else if (k === 'ArrowRight' || k === '>') go = Math.min(total - 1, idx + 1);
          if (go === null) return;
          var url = 'mobile.php?idx=' + encodeURIComponent(go) + '&show_pending=' + encodeURIComponent(showPending);
          if (acct && acct !== '') url += '&account=' + encodeURIComponent(acct);
          location.href = url;
        });
      } catch(e) { /* ignore */ }
    })();
  </script>

<?php if (!$tx): ?>
  <div class="m-empty">Aucune écriture trouvée.</div>
<?php else: ?>
  <div class="mobile-card">
    <div class="mobile-card-row"><span class="mobile-card-label">Compte</span><span class="m-value"><?php echo htmlspecialchars($tx['account_name'] ?? $tx['account_id']); ?></span></div>
    <div class="mobile-card-row"><span class="mobile-card-label">Date</span><span class="m-value"><?php if (!empty($badgeHtml)) { echo $badgeHtml . '<br>'; } elseif ($isPending) { echo '<span class="badge-pending">P. Différé</span><br>'; } ?><?php echo htmlspecialchars($tx['booking_date'] ?? ''); ?></span></div>
    <div class="mobile-card-row"><span class="mobile-card-label">Montant</span><span class="m-value" style="color:<?php echo ($tx['amount'] < 0) ? '#c62828' : '#2e7d32'; ?>"><?php echo htmlspecialchars(number_format((float)$tx['amount'], 2, ',', ' ')); ?></span></div>
    <?php /* Statut row removed: badge is shown on the date line */ ?>
    <div class="mobile-card-row"><span class="mobile-card-label">Devise</span><span class="m-value"><?php echo htmlspecialchars($tx['currency'] ?? ''); ?></span></div>
    <div class="mobile-card-row mc-desc"><span class="mobile-card-label">Libellé</span><span class="m-value"><?php echo htmlspecialchars($tx['description'] ?? ''); ?></span></div>
    <?php if ($displayBalance !== null): ?>
      <div class="mobile-card-row"><span class="mobile-card-label">Solde</span><span class="m-value"><?php echo htmlspecialchars(number_format($displayBalance, 2, ',', ' ')); ?></span></div>
    <?php endif; ?>
    <?php if ($isGroupSel): ?>
    <div class="mobile-card-row"><span class="mobile-card-label">Solde virtuel</span><span class="m-value" style="color:#5c6bc0"><?php echo htmlspecialchars(number_format($groupVirtualBalance ?? 0.0, 2, ',', ' ')); ?></span></div>
    <?php endif; ?>
  </div>
  
  <div class="m-cats">
    <?php for ($ci = 1; $ci <= 4; $ci++):
      $field = "cat{$ci}_id";
      $curVal = $tx[$field] ?? null;
    ?>
      <form method="post" data-field="<?php echo $field; ?>" data-txid="<?php echo htmlspecialchars($tx['id']); ?>" style="margin:0">
        <input type="hidden" name="tx_id" value="<?php echo htmlspecialchars($tx['id']); ?>">
        <input type="hidden" name="field" value="<?php echo $field; ?>">
        <input type="hidden" name="idx" value="<?php echo $idx; ?>">
        <input type="hidden" name="acct" value="<?php echo htmlspecialchars($acctSel); ?>">
        <select name="value" onchange="this.form.submit()">
          <option value=""><?php echo htmlspecialchars($criterionNames[$ci]); ?></option>
          <?php if (!empty($catTree[$ci])): foreach ($catTree[$ci] as $pid => $node): if (!$node['info']) continue; ?>
            <optgroup label="<?php echo htmlspecialchars($node['info']['label']); ?>">
              <?php foreach ($node['children'] as $child): ?>
                <option value="<?php echo $child['id']; ?>"<?php echo ((int)$curVal === (int)$child['id']) ? ' selected' : ''; ?>>&nbsp;&nbsp;<?php echo htmlspecialchars($child['label']); ?></option>
              <?php endforeach; ?>
            </optgroup>
          <?php endforeach; endif; ?>
        </select>
      </form>
      <div class="suggestions-placeholder" id="suggestions_cat<?php echo $ci; ?>" data-crit="<?php echo $ci; ?>" style="margin:8px 0"></div>
      <div id="suggestionBox_<?php echo $ci; ?>" class="suggestion-box" style="display:none;border:1px solid #e0e0e0;padding:8px;border-radius:6px;margin-bottom:8px;background:#fff">
        <div id="suggestLabel_<?php echo $ci; ?>" style="font-weight:700;margin-bottom:6px"></div>
        <div id="suggestDebug_<?php echo $ci; ?>" style="display:none;font-family:monospace;white-space:pre-wrap;margin-bottom:6px;color:#444"></div>
        <div style="display:flex;gap:8px">
            <button class="applySuggestion btn">Appliquer</button>
            <button class="createCategory btn">Créer catégorie</button>
            <button class="createRule btn">Créer une règle</button>
            <button class="ignoreSuggestion btn">Ignorer</button>
        </div>
      </div>
    <?php endfor; ?>
  </div>

  <!-- Modal: New Rule (hidden) -->
  <div id="newRuleModal" style="display:none;position:fixed;z-index:3000;left:0;right:0;top:50px;margin:0 auto;max-width:800px;padding:12px;background:#fff;border:1px solid #ddd;border-radius:8px;box-shadow:0 6px 24px rgba(0,0,0,0.16)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
      <strong>Nouvelle règle</strong>
      <button id="closeNewRuleModal" class="btn">X</button>
    </div>
    <div style="margin-bottom:8px">Catégorie cible: <span id="modalCatLabel" style="font-weight:700"></span></div>
    <form id="newRuleForm">
      <input type="hidden" name="category_level" value="">
      <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px">
        <input name="pattern" placeholder="Motif / libellé" style="flex:1;padding:8px">
      </div>
      <div style="display:flex;gap:8px;align-items:center">
        <select name="scope_account_id">
          <option value="">-- pas de sélection --</option>
          <option value="NULL">Global (tous les comptes)</option>
          <?php foreach ($accs as $a): ?>
            <option value="<?php echo htmlspecialchars($a['id']); ?>"><?php echo htmlspecialchars($a['name']); ?></option>
          <?php endforeach; ?>
        </select>
        <input name="priority" value="100" style="width:70px;padding:6px">
        <div style="margin-left:auto">
          <button id="createRuleSubmit" class="btn" type="button">Créer</button>
          <button id="createRuleCancel" class="btn" type="button">Annuler</button>
        </div>
      </div>
    </form>
  </div>

  <!-- Modal: Create Category (mobile) -->
  <div id="createCatModalMobile" style="display:none;position:fixed;z-index:3001;left:0;right:0;top:80px;margin:0 auto;max-width:720px;padding:12px;background:#fff;border:1px solid #ddd;border-radius:8px;box-shadow:0 6px 24px rgba(0,0,0,0.16)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
      <strong>Créer une catégorie liée</strong>
      <button id="closeCreateCatMobile" class="btn">X</button>
    </div>
    <form id="createCatFormMobile">
      <input type="hidden" name="criterion" value="">
      <div style="margin-bottom:8px">
        <label>Parent (facultatif):
          <select name="parent_id" id="createCatParentMobile" style="width:100%">
            <option value="0">Créer un nouveau code de niveau 1</option>
          </select>
        </label>
      </div>
      <div style="margin-bottom:8px">
        <label>Libellé: <input name="label" id="createCatLabelMobile" required style="width:100%;padding:8px"></label>
      </div>
      <div style="text-align:right">
        <button type="button" id="createCatSubmitMobile" class="btn">Créer</button>
        <button type="button" id="createCatCancelMobile" class="btn">Annuler</button>
      </div>
    </form>
  </div>

    <script>
    (function(){
      let txId = <?php echo json_encode($tx['id'] ?? ''); ?>;
      // fallback: if PHP didn't provide tx id (empty string), try reading from the form data-txid
      if (!txId) {
        const f = document.querySelector('.m-cats form[data-txid]');
        if (f && f.dataset && f.dataset.txid) txId = f.dataset.txid;
      }
      console.debug('suggest:init txId=', txId);
      logDebug('init txId=' + txId);
      const catLabels = <?php echo json_encode($catLabels); ?>;
      const catCriteria = <?php echo json_encode($catCriteria); ?>;
      const txDescription = <?php echo json_encode($tx['description'] ?? ''); ?>;
      const txAccount = <?php echo json_encode($tx['account_id'] ?? null); ?>;

      function logDebug(msg, obj) {
        try {
          var p = document.getElementById('suggestionDebugPanel');
          var line = '[' + (new Date()).toLocaleTimeString() + '] ' + msg;
          if (obj !== undefined) {
            try { line += ' ' + JSON.stringify(obj); } catch(e) { line += ' ' + String(obj); }
          }
          if (p) p.textContent = line + '\n' + p.textContent;
        } catch(e) { console.debug('logDebug error', e); }
      }
      if (!txId) {
        console.debug('suggest: no txId, aborting suggestion fetch');
        return;
      }

      // Hide all suggestion boxes
      function hideAll() {
        for (let i=1;i<=4;i++) {
          const b = document.getElementById('suggestionBox_'+i);
          if (b) { b.style.display = 'none'; const p = document.getElementById('suggestDebug_'+i); if (p) p.style.display='none'; }
        }
      }

      fetch('./mon-site/api/suggest_category.php?tx_id=' + encodeURIComponent(txId))
        .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(data => {
          console.debug('suggest: response', data);
          logDebug('fetch response', data);
          // Always request debug dump (rules + match info) to populate debug panels
          fetch('./mon-site/api/suggest_category.php?tx_id=' + encodeURIComponent(txId) + '&debug=1')
            .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(dd => {
              console.debug('suggest: debug dump', dd);
              logDebug('debug dump', dd);
              // populate per-criterion debug areas
              if (dd && dd.rules && Array.isArray(dd.rules)) {
                dd.rules.forEach(function(r){
                  var crit = (<?php echo json_encode($catCriteria); ?>[r.category_level] || 0);
                  var el = document.getElementById('suggestDebug_' + (crit || 1));
                  if (el) el.textContent = JSON.stringify(r, null, 2);
                });
              }
              // Now always show suggestion boxes for criteria 1..4 so user can create rules
              for (let crit = 1; crit <= 4; crit++) {
                const bx = document.getElementById('suggestionBox_' + crit);
                if (!bx) continue;
                bx.style.display = 'block';
                const lbl = document.getElementById('suggestLabel_' + crit);
                const dbg = document.getElementById('suggestDebug_' + crit);
                // If server returned a suggestion for this criterion, render it
                if (data && data.suggestion) {
                  const s = data.suggestion;
                  const sugCrit = parseInt(catCriteria[s.category_level] || 0, 10);
                  if (sugCrit === crit) {
                    const catLabel = catLabels[s.category_level] || ('#' + s.category_level);
                    if (lbl) lbl.textContent = catLabel + (s.is_regex ? ' (regex)' : '');
                    if (dbg) { dbg.style.display = 'block'; dbg.textContent = JSON.stringify(data, null, 2); }
                    // enable apply
                    const applyBtn = bx.querySelector('.applySuggestion'); if (applyBtn) { applyBtn.disabled = false; applyBtn.dataset.cat = s.category_level; }
                  } else {
                    if (lbl) lbl.textContent = 'Aucune suggestion';
                    if (dbg) { dbg.style.display = 'none'; dbg.textContent = ''; }
                    const applyBtn = bx.querySelector('.applySuggestion'); if (applyBtn) applyBtn.disabled = true;
                  }
                } else {
                  if (lbl) lbl.textContent = 'Aucune suggestion';
                  if (dbg) { dbg.style.display = 'none'; dbg.textContent = ''; }
                  const applyBtn = bx.querySelector('.applySuggestion'); if (applyBtn) applyBtn.disabled = true;
                }

                // Apply handler (works only when a category id is present in dataset)
                bx.querySelector('.applySuggestion').onclick = function(){
                  const catId = this.dataset.cat;
                  if (!catId) { showToast('Aucune suggestion à appliquer', 'error'); return; }
                  const fieldName = 'cat'+crit+'_id';
                  // find form similar to before
                  function findForm(field) {
                    let f = document.querySelector('.m-cats form[data-field="'+field+'"][data-txid="'+txId+'"]'); if (f) return {form:f};
                    f = document.querySelector('.m-cats form[data-field="'+field+'"]'); if (f) return {form:f};
                    const forms = Array.from(document.querySelectorAll('.m-cats form'));
                    for (const fr of forms) { const hf = fr.querySelector('input[name="field"]'); if (hf && hf.value === field) return {form:fr}; }
                    return null;
                  }
                  const found = findForm(fieldName);
                  if (!found) { showToast('Formulaire cible introuvable', 'error'); return; }
                  const form = found.form; const sel = form.querySelector('select[name="value"]'); if (!sel) { showToast('Sélecteur introuvable', 'error'); return; }
                  sel.value = catId; form.submit();
                };

                // Create rule handler: open modal using the suggested category
                bx.querySelector('.createRule').onclick = function(){
                  const applyBtn = bx.querySelector('.applySuggestion');
                  const suggestedCat = (applyBtn && applyBtn.dataset && applyBtn.dataset.cat) ? applyBtn.dataset.cat : null;
                  const lbl = document.getElementById('suggestLabel_' + crit);
                  const catLabelText = lbl ? lbl.textContent : '';
                  if (!suggestedCat) { showToast('Aucune catégorie suggérée', 'error'); return; }
                  const modal = document.getElementById('newRuleModal');
                  if (!modal) { alert('Modal création règle introuvable'); return; }
                  modal.querySelector('[name="category_level"]').value = suggestedCat;
                  modal.querySelector('#modalCatLabel').textContent = catLabelText || ('#'+suggestedCat);
                  modal.querySelector('[name="pattern"]').value = txDescription || '';
                  modal.querySelector('[name="is_regex"]').checked = false;
                  const scopeSel = modal.querySelector('[name="scope_account_id"]');
                  if (scopeSel && (txAccount !== null && txAccount !== '')) {
                    for (const o of scopeSel.options) { if (o.value === String(txAccount)) { o.selected = true; break; } }
                  }
                  modal.querySelector('[name="priority"]').value = '100';
                  modal.style.display = 'block';
                };

                // Create category button handler (mobile): open create modal and prefill
                var createCatBtn = bx.querySelector('.createCategory');
                if (createCatBtn) {
                  createCatBtn.onclick = function(){
                    const applyBtn = bx.querySelector('.applySuggestion');
                    const suggestedCat = (applyBtn && applyBtn.dataset && applyBtn.dataset.cat) ? applyBtn.dataset.cat : null;
                    const critNum = crit;
                    // open modal
                    const cm = document.getElementById('createCatModalMobile');
                    if (!cm) { showToast('Modal création catégorie introuvable', 'error'); return; }
                    cm.style.display = 'block';
                    cm.querySelector('[name="criterion"]').value = String(critNum);
                    // populate parent select for this criterion
                    const parentSel = document.getElementById('createCatParentMobile');
                    parentSel.innerHTML = '<option value="0">Créer un nouveau code de niveau 1</option>';
                    try {
                      var parents = (<?php echo json_encode($catTree); ?>)[critNum] || {};
                      Object.keys(parents).forEach(function(pid){ var node = parents[pid]; if (node && node.info) { var o = document.createElement('option'); o.value = node.info.id; o.textContent = node.info.label; parentSel.appendChild(o); } });
                    } catch(e) { console.debug('populate parents error', e); }
                    // prefill label using txDescription
                    var lab = document.getElementById('createCatLabelMobile'); if (lab) lab.value = txDescription || '';
                    // if we have suggestedCat and can map it to a parent, try to preselect that parent
                    try {
                      var childToParent = <?php
                        $childParents = [];
                        foreach ($allCats as $c) {
                          if ($c['parent_id'] !== null) { $childParents[$c['id']] = $c['parent_id']; }
                        }
                        echo json_encode($childParents);
                      ?>;
                      if (suggestedCat && childToParent[suggestedCat]) {
                        var pval = childToParent[suggestedCat];
                        for (const o of parentSel.options) { if (String(o.value) === String(pval)) { o.selected = true; break; } }
                      }
                    } catch(e){}
                  };
                }

                bx.querySelector('.ignoreSuggestion').onclick = function(){ bx.style.display='none'; };
              }
            }).catch(e => { console.debug('suggest debug fetch error', e); logDebug('suggest debug fetch error', String(e)); });
        })
        .catch(err=>{ console.error('suggest fetch error', err); });
    })();
    
    // Modal handlers for new rule form
    (function(){
      var modal = document.getElementById('newRuleModal');
      if (!modal) return;
      document.getElementById('closeNewRuleModal').addEventListener('click', function(){ modal.style.display='none'; });
      document.getElementById('createRuleCancel').addEventListener('click', function(){ modal.style.display='none'; });
      document.getElementById('createRuleSubmit').addEventListener('click', function(){
        var f = document.getElementById('newRuleForm');
        var fd = new FormData(f);
        // map field names to API expectations
        var payload = new URLSearchParams();
        payload.append('pattern', fd.get('pattern') || '');
        payload.append('is_regex', fd.get('is_regex') ? '1' : '0');
        payload.append('category_level', fd.get('category_level') || '0');
        payload.append('scope_account_id', fd.get('scope_account_id') || '');
        payload.append('priority', fd.get('priority') || '100');
        fetch('./mon-site/api/create_rule.php', { method: 'POST', body: payload })
          .then(r=>r.json()).then(function(resp){
            if (resp && resp.ok) { showToast('Règle créée', 'success'); modal.style.display='none'; }
            else { console.error(resp); alert('Erreur création règle'); }
          }).catch(function(e){ console.error(e); alert('Erreur réseau'); });
      });
    })();

    // Swipe navigation: detect left/right swipes to move prev/next
    (function(){
      var startX = null, startY = null, startTime = null;
      var threshold = 40; // px
      var allowedTime = 500; // ms
      function onTouchStart(e){
        var t = e.touches ? e.touches[0] : (e.changedTouches ? e.changedTouches[0] : e);
        startX = t.clientX; startY = t.clientY; startTime = Date.now();
      }
      function onTouchEnd(e){
        if (startX === null) return;
        var t = e.changedTouches ? e.changedTouches[0] : e;
        var distX = t.clientX - startX; var distY = t.clientY - startY; var elapsed = Date.now() - startTime;
        startX = startY = startTime = null;
        if (elapsed > allowedTime) return;
        if (Math.abs(distX) > Math.abs(distY) && Math.abs(distX) > threshold) {
          // horizontal swipe
          var go = null;
          if (distX > 0) go = Math.max(0, <?php echo (int)$idx; ?> - 1);
          else go = Math.min(<?php echo (int)$total; ?> - 1, <?php echo (int)$idx; ?> + 1);
          if (go === null) return;
          var url = 'mobile.php?idx=' + encodeURIComponent(go) + '&show_pending=' + encodeURIComponent(<?php echo $showPending ? '1' : '0'; ?>);
          var acct = <?php echo json_encode((string)$acctSel); ?>;
          if (acct && acct !== '') url += '&account=' + encodeURIComponent(acct);
          location.href = url;
        }
      }
      document.addEventListener('touchstart', onTouchStart, {passive:true});
      document.addEventListener('touchend', onTouchEnd, {passive:true});
      // fallback for mouse drag on desktops
      var mouseDown = false;
      document.addEventListener('pointerdown', function(e){ mouseDown = true; onTouchStart(e); });
      document.addEventListener('pointerup', function(e){ if (!mouseDown) return; mouseDown = false; onTouchEnd(e); });
    })();

    // Modal handlers for create category (mobile)
    (function(){
      var modal = document.getElementById('createCatModalMobile');
      if (!modal) return;
      document.getElementById('closeCreateCatMobile').addEventListener('click', function(){ modal.style.display='none'; });
      document.getElementById('createCatCancelMobile').addEventListener('click', function(){ modal.style.display='none'; });
      document.getElementById('createCatSubmitMobile').addEventListener('click', function(){
        var f = document.getElementById('createCatFormMobile');
        var fd = new FormData(f);
        var criterion = fd.get('criterion') || '1';
        var parent_id = fd.get('parent_id') || '0';
        var label = fd.get('label') || '';
        if (!label || label.trim() === '') { alert('Libellé requis'); return; }
        var payload = new URLSearchParams();
        if (parent_id && parent_id !== '0') {
          payload.append('action','add_level2');
          payload.append('parent_id', parent_id);
          payload.append('label', label);
        } else {
          payload.append('action','add_level1');
          payload.append('label', label);
        }
        payload.append('criterion', criterion);
        payload.append('ajax', '1');
        fetch('categories.php', { method: 'POST', body: payload }).then(r=>r.json()).then(function(resp){
          if (resp && resp.ok) { showToast('Catégorie créée', 'success'); modal.style.display='none'; setTimeout(function(){ location.reload(); }, 600); }
          else { console.error(resp); alert('Erreur création catégorie'); }
        }).catch(function(e){ console.error(e); alert('Erreur réseau'); });
      });
    })();
    </script>
    <script>
    // small toast utility
    function showToast(msg, type) {
      try {
        var t = document.getElementById('toast');
        if (!t) return;
        t.className = '';
        if (type === 'success') t.classList.add('toast-success');
        if (type === 'error') t.classList.add('toast-error');
        t.textContent = msg;
        t.style.display = 'block';
        setTimeout(function(){ t.style.display = 'none'; t.className = ''; }, 3500);
      } catch(e){ console.debug('toast error', e); }
    }
    </script>
    <script>
    // toggle show pending checkbox handler
    (function(){
      var cb = document.getElementById('showPending');
      if (!cb) return;
      cb.addEventListener('change', function(){
        var q = new URLSearchParams(location.search);
        q.set('show_pending', this.checked ? '1' : '0');
        // keep same index
        q.set('idx', '<?php echo $idx; ?>');
        if ('<?php echo addslashes($acctSel); ?>') q.set('account', '<?php echo addslashes($acctSel); ?>');
        location.search = q.toString();
      });
    })();
    </script>
</div>
<?php endif; ?>
</body>
</html>
