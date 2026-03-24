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

// Build WHERE for account filter
$where = ["UPPER(t.status) = 'BOOK'"];
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

// Count total BOOK rows
$countSql = 'SELECT COUNT(*) FROM transactions t WHERE ' . implode(' AND ', $where);
$stmt = $pdo->prepare($countSql);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

// Current index (0-based)
$idx = max(0, min((int)($_GET['idx'] ?? 0), $total - 1));

// Fetch single row at offset
$sql = 'SELECT t.*, a.name AS account_name, a.balance AS account_balance
        FROM transactions t LEFT JOIN accounts a ON a.id = t.account_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY t.booking_date DESC, t.amount DESC
        LIMIT 1 OFFSET :off';
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':off', $idx, PDO::PARAM_INT);
$stmt->execute();
$tx = $stmt->fetch(PDO::FETCH_ASSOC);

// Compute Solde (per-account balance at this position)
$displayBalance = null;
if ($tx) {
  $accountBalance = (float)($tx['account_balance'] ?? 0.0);
  $displayBalance = $accountBalance;
  if ($idx > 0) {
    $sumSql = 'SELECT COALESCE(SUM(sub.amount), 0) FROM (
      SELECT t.amount, t.account_id FROM transactions t
      WHERE ' . implode(' AND ', $where) . '
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

// Compute Solde virtuel (group total balance at this position)
$groupVirtualBalance = null;
if ($tx && $groupSelected) {
  $groupStartBalance = 0.0;
  foreach ($groupAccountIds as $aid) {
    $groupStartBalance += (float)($accBalances[$aid] ?? 0.0);
  }
  $groupVirtualBalance = $groupStartBalance;
  if ($idx > 0) {
    $gSumSql = 'SELECT COALESCE(SUM(sub.amount), 0) FROM (
      SELECT t.amount FROM transactions t
      WHERE ' . implode(' AND ', $where) . '
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
    .m-counter{font-size:.9rem;color:#666}
    .m-empty{text-align:center;padding:40px 0;color:#999}
  </style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>

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

<?php if (!$tx): ?>
  <div class="m-empty">Aucune écriture trouvée.</div>
<?php else: ?>
  <div class="m-card">
    <div class="m-row"><span class="m-label">Compte</span><span class="m-value"><?php echo htmlspecialchars($tx['account_name'] ?? $tx['account_id']); ?></span></div>
    <div class="m-row"><span class="m-label">Date</span><span class="m-value"><?php echo htmlspecialchars($tx['booking_date'] ?? ''); ?></span></div>
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
    <div id="suggestionBox" style="display:none;background:#eef7ff;border:1px solid #cfe8ff;padding:10px;border-radius:6px;margin-bottom:8px">
      <strong>Suggestion :</strong> <span id="suggestLabel"></span>
      <div style="margin-top:8px;display:flex;gap:8px">
        <button id="applySuggestion" class="btn">Appliquer</button>
        <button id="createRule" class="btn">Créer règle</button>
        <button id="ignoreSuggestion" class="btn">Ignorer</button>
      </div>
    </div>
    <?php for ($ci = 1; $ci <= 4; $ci++):
      $field = "cat{$ci}_id";
      $curVal = $tx[$field] ?? null;
    ?>
      <form method="post" style="margin:0">
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
      const txId = <?php echo json_encode($tx['id'] ?? ''); ?>;
      if (!txId) return;
      fetch('./mon-site/api/suggest_category.php?tx_id=' + encodeURIComponent(txId))
        .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(data => {
          const box = document.getElementById('suggestionBox');
          // show box even when no suggestion so user sees debug info
          box.style.display = 'block';
          if (!data || !data.suggestion) {
            document.getElementById('suggestLabel').textContent = 'Aucune suggestion';
          } else {
            const s = data.suggestion;
            const catLabel = <?php echo json_encode($catLabels); ?>[s.category_id] || ('#' + s.category_id);
            document.getElementById('suggestLabel').textContent = catLabel + (s.is_regex ? ' (regex)' : '');
            document.getElementById('applySuggestion').onclick = function(){
              const select = document.querySelector('.m-cats form select');
              if (select) { select.value = s.category_id; select.form.submit(); }
            };
            // attach create rule handler (uses suggested category)
            document.getElementById('createRule').onclick = function(){
              const fd = new FormData();
              fd.append('pattern', <?php echo json_encode($tx['description'] ?? ''); ?>);
              fd.append('is_regex', '0');
              fd.append('category_id', s.category_id);
              fd.append('scope_account_id', <?php echo json_encode($tx['account_id'] ?? null); ?>);
              fd.append('priority', '100');
              fetch('./mon-site/api/create_rule.php', { method: 'POST', body: fd }).then(r=>r.json()).then(resp=>{
                if (resp && resp.ok) { alert('Règle créée'); box.style.display='none'; }
                else alert('Erreur création règle');
              }).catch(()=>alert('Erreur réseau'));
            };
          }
          // If there's no suggestion, allow creating a rule manually: ask for category id
          document.getElementById('createRule').onclick = function(){
            const manualCat = prompt('ID de la catégorie à assigner (entier) — laisser vide pour annuler');
            if (!manualCat) return;
            const fd = new FormData();
            fd.append('pattern', <?php echo json_encode($tx['description'] ?? ''); ?>);
            fd.append('is_regex', '0');
            fd.append('category_id', manualCat);
            fd.append('scope_account_id', <?php echo json_encode($tx['account_id'] ?? null); ?>);
            fd.append('priority', '100');
            fetch('./mon-site/api/create_rule.php', { method: 'POST', body: fd }).then(r=>r.json()).then(resp=>{
              if (resp && resp.ok) { alert('Règle créée'); box.style.display='none'; }
              else alert('Erreur création règle');
            }).catch(()=>alert('Erreur réseau'));
          };

          document.getElementById('ignoreSuggestion').onclick = function(){ box.style.display='none'; };

          // show debug JSON below box for troubleshooting
          let dbg = document.getElementById('suggestDebug');
          if (!dbg) { dbg = document.createElement('pre'); dbg.id = 'suggestDebug'; dbg.style.padding='8px'; dbg.style.background='#f7f7f7'; dbg.style.border='1px solid #eee'; dbg.style.marginTop='8px'; document.getElementById('suggestionBox').appendChild(dbg); }
          dbg.textContent = JSON.stringify(data, null, 2);
        }).catch((err)=>{
          const box = document.getElementById('suggestionBox'); box.style.display='block';
          document.getElementById('suggestLabel').textContent = 'Erreur réseau: ' + (err && err.message ? err.message : '');
          console.error('suggest fetch error', err);
        });
    })();
    </script>

  <div class="m-nav">
    <?php
      $prevIdx = max(0, $idx - 1);
      $nextIdx = min($total - 1, $idx + 1);
      $qs = $acctSel !== '' ? '&account=' . urlencode($acctSel) : '';
    ?>
    <button <?php echo ($idx <= 0) ? 'disabled' : ''; ?> onclick="location.href='mobile.php?idx=<?php echo $prevIdx . $qs; ?>'">&larr; Préc.</button>
    <span class="m-counter"><?php echo ($idx + 1) . ' / ' . $total; ?></span>
    <button <?php echo ($idx >= $total - 1) ? 'disabled' : ''; ?> onclick="location.href='mobile.php?idx=<?php echo $nextIdx . $qs; ?>'">Suiv. &rarr;</button>
  </div>
<?php endif; ?>
</body>
</html>
