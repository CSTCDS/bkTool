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

// Accounts list
$accs = $pdo->query('SELECT id, name, balance FROM accounts ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$acctSel = $_GET['account'] ?? ($_COOKIE['selected_account'] ?? '');

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
}

// Flat map id=>label for quick lookup (used for suggestion display)
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

// Account balances map
$accBalances = [];
foreach ($accs as $a) $accBalances[$a['id']] = (float)($a['balance'] ?? 0);

$showPending = isset($_GET['show_pending']) ? ($_GET['show_pending'] === '1') : true;

// Build WHERE for account filter
$where = [];
if (!$showPending) {
  $where[] = "UPPER(t.status) = 'BOOK'";
}
$params = [];
$groupSelected = false;
$groupAccountIds = [];
if ($acctSel !== '') {
  if (is_string($acctSel) && strpos($acctSel, 'g:') === 0) {
    $gid = (int)substr($acctSel, 2);
    $acctIds = $groupChildren[$gid] ?? [];
    $groupAccountIds = $acctIds;
    if (!empty($acctIds)) {
      $groupSelected = true;
      $placeholders = [];
      foreach ($acctIds as $i => $aid) {
        $ph = ':g_' . $gid . '_' . $i;
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

$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

// Count total BOOK rows
$countSql = 'SELECT COUNT(*) FROM transactions t' . $whereSql;
$stmt = $pdo->prepare($countSql);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
// If tx_id supplied, load that transaction directly (used when opening from transactions list)
$tx = null;
$requestedTxId = isset($_GET['tx_id']) ? (int)$_GET['tx_id'] : 0;
if ($requestedTxId) {
  $sql = 'SELECT t.*, a.name AS account_name, a.balance AS account_balance FROM transactions t LEFT JOIN accounts a ON a.id = t.account_id WHERE t.id = :tid LIMIT 1';
  $stmt = $pdo->prepare($sql);
  $stmt->execute([':tid' => $requestedTxId]);
  $tx = $stmt->fetch(PDO::FETCH_ASSOC);
  $idx = 0;
} else {
  // Current index (0-based)
  $idx = max(0, min((int)($_GET['idx'] ?? 0), $total - 1));

    // Fetch single row at offset
    $sql = 'SELECT t.*, a.name AS account_name, a.balance AS account_balance
      FROM transactions t LEFT JOIN accounts a ON a.id = t.account_id'
      . $whereSql . '
      ORDER BY t.booking_date DESC, t.amount DESC
      LIMIT 1 OFFSET :off';
  $stmt = $pdo->prepare($sql);
  foreach ($params as $k => $v) $stmt->bindValue($k, $v);
  $stmt->bindValue(':off', $idx, PDO::PARAM_INT);
  $stmt->execute();
  $tx = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Compute Solde (per-account balance at this position)
$displayBalance = null;
if ($tx) {
  $accountBalance = (float)($tx['account_balance'] ?? 0.0);
  $displayBalance = $accountBalance;
  if ($idx > 0) {
    $subWhere = $where ? (' WHERE ' . implode(' AND ', $where)) : '';
    $sumSql = 'SELECT COALESCE(SUM(sub.amount), 0) FROM (
      SELECT t.amount, t.account_id FROM transactions t'
      . $subWhere . '
      ORDER BY t.booking_date DESC, t.amount DESC
      LIMIT :lim
    ) sub WHERE sub.account_id = :cur_acct';
    $sumStmt = $pdo->prepare($sumSql);
    foreach ($params as $k => $v) $sumStmt->bindValue($k, $v);
    $sumStmt->bindValue(':lim', $idx, PDO::PARAM_INT);
    $sumStmt->bindValue(':cur_acct', $tx['account_id']);
    $sumStmt->execute();
    $displayBalance = $accountBalance - (float)$sumStmt->fetchColumn();
  }
}

// pending flag
$isPending = $tx ? (strtoupper((string)($tx['status'] ?? '')) !== 'BOOK') : false;

// Compute Solde virtuel (group total balance at this position)
$groupVirtualBalance = null;
if ($tx && $groupSelected) {
  $groupStartBalance = 0.0;
  foreach ($groupAccountIds as $aid) {
    $groupStartBalance += (float)($accBalances[$aid] ?? 0.0);
  }
  $groupVirtualBalance = $groupStartBalance;
  if ($idx > 0) {
    $subWhere = $where ? (' WHERE ' . implode(' AND ', $where)) : '';
    $gSumSql = 'SELECT COALESCE(SUM(sub.amount), 0) FROM (
      SELECT t.amount FROM transactions t' . $subWhere . '
      ORDER BY t.booking_date DESC, t.amount DESC
      LIMIT :lim
    ) sub';
    $gSumStmt = $pdo->prepare($gSumSql);
    foreach ($params as $k => $v) $gSumStmt->bindValue($k, $v);
    $gSumStmt->bindValue(':lim', $idx, PDO::PARAM_INT);
    $gSumStmt->execute();
    $groupVirtualBalance = $groupStartBalance - (float)$gSumStmt->fetchColumn();
  }
}
?><!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>bkTool — Mobile</title>
  <link rel="stylesheet" href="assets/css/style.css">
  <style>
    body{margin:0;padding:8px;font-size:17px}
    .m-header{display:flex;align-items:center;justify-content:space-between;padding:8px 0}
    .m-header .site-title{font-size:1.1rem}
    .m-account select{width:100%;padding:8px;font-size:1rem;border-radius:6px;border:1px solid #ccc}
    .m-card{background:#fff;border:1px solid #ddd;border-radius:10px;padding:16px;margin:12px 0;box-shadow:0 2px 8px rgba(0,0,0,.08)}
    .m-row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f0f0f0}
    .m-row:last-child{border-bottom:none}
    .m-label{font-weight:600;color:#666;font-size:.9rem}
    .m-value{text-align:right;font-size:.95rem}
    .m-desc{flex-direction:column}
    .m-desc .m-value{text-align:left;margin-top:4px;color:#444}
    .m-cats{margin-top:12px}
    .m-cats select{width:100%;padding:8px;font-size:.95rem;border-radius:6px;border:1px solid #ccc;margin-bottom:8px}
    .m-nav{display:flex;justify-content:space-between;align-items:center;padding:12px 0}
    .m-nav button{padding:12px 24px;font-size:1rem;border-radius:8px;border:1px solid #ccc;background:#f5f5f5;cursor:pointer}
    .m-nav button:disabled{opacity:.4;cursor:default}
    .m-nav .arrow-btn{padding:6px 10px;font-size:1rem;border-radius:6px;border:1px solid #ccc;background:#f5f5f5;cursor:pointer;margin-right:6px}
    .m-nav .arrow-btn:disabled{opacity:.4;cursor:default}
    .m-counter{font-size:.9rem;color:#666}
    .m-empty{text-align:center;padding:40px 0;color:#999}
    /* Toast */
    #toast{position:fixed;left:50%;transform:translateX(-50%);bottom:18px;min-width:200px;padding:10px 16px;border-radius:8px;background:rgba(0,0,0,0.8);color:#fff;display:none;z-index:9999}
    #toast.toast-success{background:linear-gradient(90deg,#2e7d32,#388e3c)}
    #toast.toast-error{background:linear-gradient(90deg,#c62828,#d32f2f)}
  </style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>

  <div id="toast"></div>

  <div class="m-account">
    <select onchange="document.cookie='selected_account='+encodeURIComponent(this.value)+';path=/;max-age=31536000'; location.href='mobile.php?account='+encodeURIComponent(this.value)">
      <option value="">— Tous les comptes —</option>
      <?php foreach ($accs as $a): ?>
        <option value="<?php echo htmlspecialchars($a['id']); ?>"<?php echo ((string)$acctSel === (string)$a['id']) ? ' selected' : ''; ?>>
          <?php echo htmlspecialchars($a['name'] ?: $a['id']); ?>
        </option>
      <?php endforeach; ?>
      <?php if (!empty($catTree[0])): foreach ($catTree[0] as $pid => $node): if (!$node['info']) continue; ?>
        <option value="g:<?php echo (int)$node['info']['id']; ?>"<?php echo ($acctSel !== '' && (string)$acctSel === ('g:' . (int)$node['info']['id'])) ? ' selected' : ''; ?> style="background:#e0e0e0">
          <?php echo 'G: ' . htmlspecialchars($node['info']['label']); ?>
        </option>
      <?php endforeach; endif; ?>
    </select>
  </div>

  <div class="m-nav" style="display:flex;align-items:center;justify-content:space-between;margin:12px 0">
    <div>
      <button class="arrow-btn" <?php echo ($idx <= 0) ? 'disabled' : ''; ?> onclick="location.href='mobile.php?idx=0<?php echo ($acctSel !== '' ? '&account=' . urlencode($acctSel) : ''); ?>&show_pending=' + (<?php echo $showPending ? '1' : '0'; ?>)">&lt;&lt;</button>
      <button class="arrow-btn" <?php echo ($idx <= 0) ? 'disabled' : ''; ?> onclick="location.href='mobile.php?idx=<?php echo max(0,$idx-1) . ($acctSel !== '' ? '&account=' . urlencode($acctSel) : ''); ?>&show_pending=' + (<?php echo $showPending ? '1' : 0; ?>)">&lt;</button>
      <span class="m-counter" style="margin:0 8px"><?php echo ($idx + 1) . ' / ' . $total; ?></span>
      <button class="arrow-btn" <?php echo ($idx >= $total - 1) ? 'disabled' : ''; ?> onclick="location.href='mobile.php?idx=<?php echo min($total - 1,$idx+1) . ($acctSel !== '' ? '&account=' . urlencode($acctSel) : ''); ?>&show_pending=' + (<?php echo $showPending ? '1' : 0; ?>)">&gt;</button>
    </div>
    <label style="font-size:.95rem"><input type="checkbox" id="showPending" <?php echo $showPending ? 'checked' : ''; ?>> Afficher les opérations en attente</label>
  </div>

<?php if (!$tx): ?>
  <div class="m-empty">Aucune écriture trouvée.</div>
<?php else: ?>
  <div class="m-card">
    <div class="m-row"><span class="m-label">Compte</span><span class="m-value"><?php echo htmlspecialchars($tx['account_name'] ?? $tx['account_id']); ?></span></div>
    <div class="m-row"><span class="m-label">Date</span><span class="m-value"><?php if ($isPending) echo '<span class="badge-pending">en attente</span><br>'; ?><?php echo htmlspecialchars($tx['booking_date'] ?? ''); ?></span></div>
    <div class="m-row"><span class="m-label">Montant</span><span class="m-value" style="color:<?php echo ($tx['amount'] < 0) ? '#c62828' : '#2e7d32'; ?>"><?php echo htmlspecialchars(number_format((float)$tx['amount'], 2, ',', ' ')); ?></span></div>
    <div class="m-row"><span class="m-label">Devise</span><span class="m-value"><?php echo htmlspecialchars($tx['currency'] ?? ''); ?></span></div>
    <div class="m-row m-desc"><span class="m-label">Commentaire</span><span class="m-value"><?php echo htmlspecialchars($tx['description'] ?? ''); ?></span></div>
    <?php if ($displayBalance !== null): ?>
    <div class="m-row"><span class="m-label">Solde</span><span class="m-value" style="font-weight:700"><?php echo htmlspecialchars(number_format($displayBalance, 2, ',', ' ')); ?></span></div>
    <?php endif; ?>
    <?php if ($groupVirtualBalance !== null): ?>
    <div class="m-row"><span class="m-label">Solde virtuel</span><span class="m-value" style="font-weight:700;color:#5c6bc0"><?php echo htmlspecialchars(number_format($groupVirtualBalance, 2, ',', ' ')); ?></span></div>
    <?php endif; ?>
  </div>

  <div class="m-cats">
    <div id="suggestionDebugPanel" style="background:#fff8e1;border:1px solid #ffecb3;padding:8px;border-radius:6px;margin-bottom:8px;font-size:.9rem;color:#333">Debug suggestions: initialising...</div>
    <?php for ($ci = 1; $ci <= 4; $ci++): ?>
      <div id="suggestionBox_<?php echo $ci; ?>" style="display:none;background:#eef7ff;border:1px solid #cfe8ff;padding:10px;border-radius:6px;margin-bottom:8px">
        <strong>Suggestion <?php echo $ci; ?> :</strong> <span id="suggestLabel_<?php echo $ci; ?>"></span>
        <div style="margin-top:8px;display:flex;gap:8px">
          <button data-ci="<?php echo $ci; ?>" class="applySuggestion btn">Appliquer</button>
          <button data-ci="<?php echo $ci; ?>" class="createRule btn">Créer règle</button>
          <button data-ci="<?php echo $ci; ?>" class="ignoreSuggestion btn">Ignorer</button>
        </div>
        <pre id="suggestDebug_<?php echo $ci; ?>" style="display:none;padding:8px;background:#f7f7f7;border:1px solid #eee;margin-top:8px"></pre>
      </div>
    <?php endfor; ?>
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
    <?php endfor; ?>
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
          hideAll();
          if (!data || !data.suggestion) {
            // no suggestion — fetch debug dump (rules + match info) to help diagnose
            fetch('./mon-site/api/suggest_category.php?tx_id=' + encodeURIComponent(txId) + '&debug=1')
              .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
              .then(dd => {
                console.debug('suggest: debug dump', dd);
                logDebug('no suggestion — debug dump', dd);
                // display full rules count and description
                var p = document.getElementById('suggestionDebugPanel');
                if (p) {
                  p.textContent = '[DEBUG] rules_count=' + (dd.rules_count||0) + ' description="' + (dd.description||'') + '" account=' + (dd.accountId||'') + '\n' + (p.textContent||'');
                }
                // also populate per-criterion debug areas for visibility
                if (dd.rules && Array.isArray(dd.rules)) {
                  dd.rules.forEach(function(r){
                    var crit = (<?php echo json_encode($catCriteria); ?>[r.category_id] || 0);
                    var el = document.getElementById('suggestDebug_' + (crit || 1));
                    if (el) el.textContent = JSON.stringify(r, null, 2);
                  });
                }
              }).catch(e=>{ console.debug('suggest debug fetch error', e); logDebug('suggest debug fetch error', String(e)); });
            return;
          }
          const s = data.suggestion;
          const catLabel = catLabels[s.category_id] || ('#' + s.category_id);
          const crit = parseInt(catCriteria[s.category_id] || 0, 10);
          if (!crit || crit < 1 || crit > 4) return;
          const bx = document.getElementById('suggestionBox_'+crit);
          if (!bx) return;
          // Move the suggestion box next to the corresponding category form (so it's visible near the select)
          const formForCrit = document.querySelector('.m-cats form[data-field="cat'+crit+'_id"][data-txid="'+txId+'"]') || document.querySelector('.m-cats form[data-field="cat'+crit+'_id"]');
          if (formForCrit && formForCrit.insertAdjacentElement) {
            try { formForCrit.insertAdjacentElement('afterend', bx); } catch(e){ /* ignore DOM errors */ }
          }
          bx.style.display = 'block';
          const lbl = document.getElementById('suggestLabel_'+crit);
          if (lbl) lbl.textContent = catLabel + (s.is_regex ? ' (regex)' : '');
          const dbg = document.getElementById('suggestDebug_'+crit);
          if (dbg) { dbg.style.display='block'; dbg.textContent = JSON.stringify(data, null, 2); }

          // Apply handler: find the form with hidden input field == 'cat{crit}_id'
              bx.querySelector('.applySuggestion').onclick = function(){
                const fieldName = 'cat'+crit+'_id';
                function findForm(field) {
                  // 1) exact match data-field + data-txid
                  let f = document.querySelector('.m-cats form[data-field="'+field+'"][data-txid="'+txId+'"]');
                  if (f) return {form:f, selector:'data-field+txid'};
                  // 2) match data-field only
                  f = document.querySelector('.m-cats form[data-field="'+field+'"]');
                  if (f) return {form:f, selector:'data-field'};
                  // 3) fallback: scan hidden input[name=field]
                  const forms = Array.from(document.querySelectorAll('.m-cats form'));
                  for (const fr of forms) {
                    const hf = fr.querySelector('input[name="field"]');
                    if (hf && hf.value === field) return {form:fr, selector:'hidden-field'};
                  }
                  return null;
                }
                const found = findForm(fieldName);
                if (!found) {
                  const payload = {action:'apply_missing_form', tx_id:txId, field:fieldName, criterion:crit, time:(new Date()).toISOString()};
                  console.debug('apply: target form not found', payload);
                  logDebug('apply: target form not found', payload);
                  try { navigator.sendBeacon('./mon-site/api/client_log.php', JSON.stringify(payload)); } catch(e) {}
                  showToast('Formulaire cible introuvable', 'error');
                  return;
                }
                const form = found.form;
                const sel = form.querySelector('select[name="value"]');
                if (!sel) {
                  console.debug('apply: select not found in form', {txId, fieldName});
                  logDebug('apply: select not found', {txId, fieldName});
                  showToast('Sélecteur introuvable', 'error');
                  return;
                }
                sel.value = s.category_id;
                console.debug('apply: submitting form', {txId, fieldName, selector:found.selector});
                form.submit();
              };

          // Create rule handler (create + apply)
          bx.querySelector('.createRule').onclick = function(){
            const fd = new FormData();
            fd.append('pattern', <?php echo json_encode($tx['description'] ?? ''); ?>);
            fd.append('is_regex', '0');
            fd.append('category_id', s.category_id);
            fd.append('scope_account_id', <?php echo json_encode($tx['account_id'] ?? null); ?>);
            fd.append('priority', '100');
            fetch('./mon-site/api/create_rule.php', { method: 'POST', body: fd })
              .then(r=>r.json()).then(resp=>{
                if (resp && resp.ok && resp.rule_id) {
                  const afd = new FormData(); afd.append('rule_id', resp.rule_id); afd.append('tx_id', txId);
                  return fetch('./mon-site/api/apply_rule.php', { method: 'POST', body: afd }).then(r2=>r2.json());
                }
                throw new Error('create failed');
              }).then(ar=>{
                if (ar && ar.ok) {
                  // update select for this criterion (defensive)
                  const fieldName = ar.field;
                  const tryFind = (f)=>{
                    let ff = document.querySelector('.m-cats form[data-field="'+f+'"][data-txid="'+txId+'"]');
                    if (ff) return ff;
                    ff = document.querySelector('.m-cats form[data-field="'+f+'"]');
                    if (ff) return ff;
                    const forms = Array.from(document.querySelectorAll('.m-cats form'));
                    for (const fr of forms) { const hf = fr.querySelector('input[name="field"]'); if (hf && hf.value === f) return fr; }
                    return null;
                  };
                  const form = tryFind(fieldName);
                  if (form) {
                    const sel = form.querySelector('select[name="value"]');
                    if (sel) { sel.value = String(ar.new); sel.style.background = 'orange'; setTimeout(()=>sel.style.background='',3000); }
                  } else {
                    const payload = {action:'apply_update_missing', tx_id:txId, field:fieldName, server:ar, time:(new Date()).toISOString()};
                    try { navigator.sendBeacon('./mon-site/api/client_log.php', JSON.stringify(payload)); } catch(e){}
                    console.debug('create+apply: update target not found', payload);
                  }
                  showToast('Règle créée et appliquée', 'success'); hideAll();
                } else showToast('Erreur application', 'error');
              }).catch(e=>{ console.error(e); alert('Erreur réseau ou création'); });
          };

          bx.querySelector('.ignoreSuggestion').onclick = function(){ bx.style.display='none'; };
        })
        .catch(err=>{ console.error('suggest fetch error', err); });
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
<?php endif; ?>
</body>
</html>
