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

// Build WHERE for account filter
$where = ["UPPER(t.status) = 'BOOK'"];
$params = [];
if ($acctSel !== '') {
  $where[] = 't.account_id = :account';
  $params[':account'] = $acctSel;
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
  <div class="m-header">
    <span class="site-title" style="font-weight:800;color:#5c6bc0">bkTool</span>
    <a href="transactions.php" style="font-size:.85rem;color:#5c6bc0">Version bureau</a>
  </div>

  <div class="m-account">
    <select onchange="document.cookie='selected_account='+encodeURIComponent(this.value)+';path=/;max-age=31536000'; location.href='mobile.php?account='+encodeURIComponent(this.value)">
      <option value="">— Tous les comptes —</option>
      <?php foreach ($accs as $a): ?>
        <option value="<?php echo htmlspecialchars($a['id']); ?>"<?php echo ((string)$acctSel === (string)$a['id']) ? ' selected' : ''; ?>>
          <?php echo htmlspecialchars($a['name'] ?: $a['id']); ?>
        </option>
      <?php endforeach; ?>
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
  </div>

  <div class="m-cats">
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
