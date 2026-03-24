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
$accs = $pdo->query('SELECT id, name, balance, currency, numero_affichage FROM accounts ORDER BY (numero_affichage IS NULL), numero_affichage ASC, name ASC')->fetchAll(PDO::FETCH_ASSOC);
$acctSel = $_GET['account'] ?? ($_COOKIE['selected_account'] ?? '');

// Build quick maps for balances and names
$accBalances = [];
foreach ($accs as $a) { $accBalances[$a['id']] = (float)($a['balance'] ?? 0.0); }

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
    $groupChildren[(int)$c['parent_id']][] = (int)$c['label'];
  }
}
// Load the transaction at index $idx (mobile card navigation)
$idx = isset($_GET['idx']) ? (int)$_GET['idx'] : 0;
$showPending = isset($_GET['show_pending']) ? ($_GET['show_pending'] === '1') : true;
$where = [];
$params = [];
if (!empty($acctSel) && strpos((string)$acctSel, 'g:') !== 0) {
  $where[] = 't.account_id = :account';
  $params[':account'] = $acctSel;
}
if (!$showPending) {
  $where[] = "UPPER(t.status) = 'BOOK'";
}
$countSql = 'SELECT COUNT(*) FROM transactions t' . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
$cntStmt = $pdo->prepare($countSql);
foreach ($params as $k => $v) $cntStmt->bindValue($k, $v);
$cntStmt->execute();
$total = (int)$cntStmt->fetchColumn();

$sql = 'SELECT t.*, a.name AS account_name FROM transactions t LEFT JOIN accounts a ON a.id = t.account_id' . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
$sql .= ' ORDER BY t.booking_date DESC, t.amount DESC LIMIT 1 OFFSET :offset';
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':offset', max(0, $idx), PDO::PARAM_INT);
$stmt->execute();
$tx = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

// compute displayBalance and groupVirtualBalance for this tx (similar to transactions.php)
$displayBalance = null;
$groupVirtualBalance = null;
if ($tx) {
  $acctId = $tx['account_id'];
  $startBal = $accBalances[$acctId] ?? 0.0;
  $sumNewer = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE account_id = :aid AND (booking_date > :bdate OR (booking_date = :bdate AND id > :id)) AND UPPER(status) = 'BOOK'");
  $sumNewer->execute([':aid' => $acctId, ':bdate' => $tx['booking_date'], ':id' => $tx['id']]);
  $newer = (float)$sumNewer->fetchColumn();
  $displayBalance = $startBal - $newer;

  // group virtual balance if account selection is a group 'g:ID'
  if (is_string($acctSel) && strpos($acctSel, 'g:') === 0) {
    $gid = (int)substr($acctSel, 2);
    $acctIds = $groupChildren[$gid] ?? [];
    $groupStart = 0.0;
    foreach ($acctIds as $aid) { if (isset($accBalances[$aid])) $groupStart += $accBalances[$aid]; }
    if (!empty($acctIds)) {
      $placeholders = [];
      $bindings = [];
      foreach ($acctIds as $i => $aid) { $ph = ':g' . $i; $placeholders[] = $ph; $bindings[$ph] = $aid; }
      $q = 'SELECT COALESCE(SUM(amount),0) FROM transactions WHERE account_id IN (' . implode(',', $placeholders) . ') AND (booking_date > :bdate OR (booking_date = :bdate AND id > :id)) AND UPPER(status) = "BOOK"';
      $stmtg = $pdo->prepare($q);
      $stmtg->bindValue(':bdate', $tx['booking_date']); $stmtg->bindValue(':id', $tx['id']);
      foreach ($bindings as $k => $v) $stmtg->bindValue($k, $v);
      $stmtg->execute();
      $groupNewer = (float)$stmtg->fetchColumn();
      $groupVirtualBalance = $groupStart - $groupNewer;
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
      <label style="font-size:.95rem;margin-left:8px;display:inline-block"><input type="checkbox" id="showPending" <?php echo $showPending ? 'checked' : ''; ?>> opérations en attente</label>

      <!-- balances moved into the transaction card below; keep this area compact -->
  </div>

  <div class="mobile-nav" style="display:flex;align-items:center;justify-content:space-between;margin:12px 0">
    <div>
      <button class="arrow-btn" <?php echo ($idx <= 0) ? 'disabled' : ''; ?> onclick="location.href='mobile.php?idx=0<?php echo ($acctSel !== '' ? '&account=' . urlencode($acctSel) : ''); ?>&show_pending=' + (<?php echo $showPending ? '1' : '0'; ?>)">&lt;&lt;</button>
      <button class="arrow-btn" <?php echo ($idx <= 0) ? 'disabled' : ''; ?> onclick="location.href='mobile.php?idx=<?php echo max(0,$idx-1) . ($acctSel !== '' ? '&account=' . urlencode($acctSel) : ''); ?>&show_pending=' + (<?php echo $showPending ? '1' : 0; ?>)">&lt;</button>
      <span id="mcCounter" style="margin:0 8px"><?php echo ($idx + 1) . ' / ' . $total; ?></span>
      <button class="arrow-btn" <?php echo ($idx >= $total - 1) ? 'disabled' : ''; ?> onclick="location.href='mobile.php?idx=<?php echo min($total - 1,$idx+1) . ($acctSel !== '' ? '&account=' . urlencode($acctSel) : ''); ?>&show_pending=' + (<?php echo $showPending ? '1' : 0; ?>)">&gt;</button>
    </div>
    
  </div>

<?php if (!$tx): ?>
  <div class="m-empty">Aucune écriture trouvée.</div>
<?php else: ?>
  <div class="mobile-card">
    <div class="mobile-card-row"><span class="mobile-card-label">Compte</span><span class="m-value"><?php echo htmlspecialchars($tx['account_name'] ?? $tx['account_id']); ?></span></div>
    <div class="mobile-card-row"><span class="mobile-card-label">Date</span><span class="m-value"><?php if ($isPending) echo '<span class="badge-pending">en attente</span><br>'; ?><?php echo htmlspecialchars($tx['booking_date'] ?? ''); ?></span></div>
    <div class="mobile-card-row"><span class="mobile-card-label">Montant</span><span class="m-value" style="color:<?php echo ($tx['amount'] < 0) ? '#c62828' : '#2e7d32'; ?>"><?php echo htmlspecialchars(number_format((float)$tx['amount'], 2, ',', ' ')); ?></span></div>
    <div class="mobile-card-row"><span class="mobile-card-label">Devise</span><span class="m-value"><?php echo htmlspecialchars($tx['currency'] ?? ''); ?></span></div>
    <div class="mobile-card-row mc-desc"><span class="mobile-card-label">Commentaire</span><span class="m-value"><?php echo htmlspecialchars($tx['description'] ?? ''); ?></span></div>
    <?php if ($displayBalance !== null): ?>
    <div class="mobile-card-row"><span class="mobile-card-label">Solde</span><span class="m-value"><?php echo htmlspecialchars(number_format($displayBalance, 2, ',', ' ')); ?></span></div>
    <?php endif; ?>
    <?php if ($groupVirtualBalance !== null): ?>
    <div class="mobile-card-row"><span class="mobile-card-label">Solde virtuel</span><span class="m-value" style="color:#5c6bc0"><?php echo htmlspecialchars(number_format($groupVirtualBalance, 2, ',', ' ')); ?></span></div>
    <?php endif; ?>
  </div>
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
          <button class="createRule btn">Créer une règle</button>
          <button class="ignoreSuggestion btn">Ignorer</button>
        </div>
      </div>
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
                  var crit = (<?php echo json_encode($catCriteria); ?>[r.category_id] || 0);
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
                  const sugCrit = parseInt(catCriteria[s.category_id] || 0, 10);
                  if (sugCrit === crit) {
                    const catLabel = catLabels[s.category_id] || ('#' + s.category_id);
                    if (lbl) lbl.textContent = catLabel + (s.is_regex ? ' (regex)' : '');
                    if (dbg) { dbg.style.display = 'block'; dbg.textContent = JSON.stringify(data, null, 2); }
                    // enable apply
                    const applyBtn = bx.querySelector('.applySuggestion'); if (applyBtn) { applyBtn.disabled = false; applyBtn.dataset.cat = s.category_id; }
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

                // Create rule handler: prompt for target category when no suggestion available
                bx.querySelector('.createRule').onclick = function(){
                  let targetCat = null;
                  // If there is an applied suggestion, reuse its category id
                  const applyBtn = bx.querySelector('.applySuggestion');
                  if (applyBtn && applyBtn.dataset && applyBtn.dataset.cat) targetCat = applyBtn.dataset.cat;
                  if (!targetCat) {
                    targetCat = prompt('ID de la catégorie cible pour la nouvelle règle (numéro) :');
                    if (!targetCat) return;
                  }
                  const fd = new FormData();
                  fd.append('pattern', txDescription || '');
                  fd.append('is_regex', '0');
                  fd.append('category_id', targetCat);
                  fd.append('scope_account_id', txAccount);
                  fd.append('priority', '100');
                  fetch('./mon-site/api/create_rule.php', { method: 'POST', body: fd })
                    .then(r=>r.json()).then(resp=>{
                      if (resp && resp.ok && resp.rule_id) {
                        showToast('Règle créée', 'success');
                      } else throw new Error('create failed');
                    }).catch(e=>{ console.error(e); alert('Erreur création règle'); });
                };

                bx.querySelector('.ignoreSuggestion').onclick = function(){ bx.style.display='none'; };
              }
            }).catch(e => { console.debug('suggest debug fetch error', e); logDebug('suggest debug fetch error', String(e)); });
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
