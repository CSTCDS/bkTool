<?php
// Page: Gestion des règles d'automatisation
ini_set('display_errors', 1);
error_reporting(E_ALL);

try {
  $pdo = require __DIR__ . '/mon-site/api/db.php';
} catch (Throwable $e) {
  echo '<pre>' . htmlspecialchars((string)$e) . '</pre>';
  exit;
}

// Handle POST actions: update or delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  if ($action === 'update' && !empty($_POST['id'])) {
    $id = (int)$_POST['id'];
    $pattern = $_POST['pattern'] ?? '';
    $is_regex = !empty($_POST['is_regex']) ? 1 : 0;
    $category_id = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;
    $scope_account_id = $_POST['scope_account_id'] !== '' ? ($_POST['scope_account_id'] === 'NULL' ? null : (int)$_POST['scope_account_id']) : null;
    $priority = isset($_POST['priority']) ? (int)$_POST['priority'] : 100;
    $active = !empty($_POST['active']) ? 1 : 0;
    $stmt = $pdo->prepare('UPDATE auto_category_rules SET pattern = :p, is_regex = :ir, category_id = :cid, scope_account_id = :scope, priority = :prio, active = :act WHERE id = :id');
    $stmt->execute([':p'=>$pattern,':ir'=>$is_regex,':cid'=>$category_id,':scope'=>$scope_account_id,':prio'=>$priority,':act'=>$active,':id'=>$id]);
  } elseif ($action === 'delete' && !empty($_POST['id'])) {
    $id = (int)$_POST['id'];
    $stmt = $pdo->prepare('DELETE FROM auto_category_rules WHERE id = :id');
    $stmt->execute([':id'=>$id]);
  } elseif ($action === 'create') {
    $pattern = $_POST['pattern'] ?? '';
    $is_regex = !empty($_POST['is_regex']) ? 1 : 0;
    $category_id = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;
    $scope_account_id = $_POST['scope_account_id'] !== '' ? ($_POST['scope_account_id'] === 'NULL' ? null : (int)$_POST['scope_account_id']) : null;
    $priority = isset($_POST['priority']) ? (int)$_POST['priority'] : 100;
    if ($pattern !== '' && $category_id > 0) {
      $stmt = $pdo->prepare('INSERT INTO auto_category_rules (pattern, is_regex, category_id, scope_account_id, priority, active, created_by) VALUES (:p,:ir,:cid,:scope,:prio,1,:cb)');
      $stmt->execute([':p'=>$pattern,':ir'=>$is_regex,':cid'=>$category_id,':scope'=>$scope_account_id,':prio'=>$priority,':cb'=>($_SERVER['REMOTE_USER'] ?? null)]);
    }
  }
  // redirect to GET to avoid re-submission
  header('Location: rules.php');
  exit;
}

// Filters
$accountFilter = $_GET['account'] ?? 'global'; // 'global' = rules with scope_account_id IS NULL
$categoryFilter = isset($_GET['category']) ? (int)$_GET['category'] : 0;

// Load accounts and categories
$accounts = $pdo->query('SELECT id, name FROM accounts ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$cats = $pdo->query('SELECT id, label FROM categories ORDER BY label')->fetchAll(PDO::FETCH_ASSOC);
$catMap = [];
foreach ($cats as $c) $catMap[$c['id']] = $c['label'];

// Build WHERE
$where = [];
$params = [];
if ($accountFilter === 'global') {
  $where[] = 'scope_account_id IS NULL';
} elseif ($accountFilter !== '' && $accountFilter !== 'any') {
  $where[] = 'scope_account_id = :acct';
  $params[':acct'] = (int)$accountFilter;
}
if ($categoryFilter > 0) {
  $where[] = 'category_id = :cid';
  $params[':cid'] = $categoryFilter;
}

$sql = 'SELECT * FROM auto_category_rules' . (empty($where) ? '' : (' WHERE ' . implode(' AND ', $where))) . ' ORDER BY priority ASC, created_at ASC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rules = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>bkTool — Règles</title>
<link rel="stylesheet" href="assets/css/style.css">
<style>
  body{padding:12px}
  .controls{display:flex;gap:8px;align-items:center;margin-bottom:12px}
  table{width:100%;border-collapse:collapse}
  th,td{padding:8px;border:1px solid #eee}
  .small{font-size:.9rem;color:#666}
  .btn{padding:6px 10px;border-radius:6px;border:1px solid #ccc;background:#f5f5f5;cursor:pointer}
  .danger{background:#ffecec;border-color:#f5c6cb}
</style>
</head><body>
<?php include __DIR__ . '/header.php'; ?>
<h1>Règles d'automatisation</h1>

<form method="get" class="controls">
  <label>Compte:
    <select name="account">
      <option value="global"<?php echo ($accountFilter==='global')?' selected':''; ?>>Tous les comptes (règles globales)</option>
      <option value="any"<?php echo ($accountFilter==='any')?' selected':''; ?>>Toutes (global + comptes)</option>
      <?php foreach ($accounts as $a): ?>
        <option value="<?php echo $a['id']; ?>"<?php echo ((string)$accountFilter === (string)$a['id']) ? ' selected' : ''; ?>><?php echo htmlspecialchars($a['name']); ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Catégorie:
    <select name="category">
      <option value="0">Toutes les catégories</option>
      <?php foreach ($cats as $c): ?>
        <option value="<?php echo $c['id']; ?>"<?php echo ($categoryFilter === (int)$c['id']) ? ' selected' : ''; ?>><?php echo htmlspecialchars($c['label']); ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <button class="btn" type="submit">Filtrer</button>
</form>

<h2>Nouvelle règle</h2>
<form method="post" style="margin-bottom:12px">
  <input type="hidden" name="action" value="create">
  <div style="display:flex;gap:8px;align-items:center">
    <input name="pattern" placeholder="Motif / libellé" style="flex:1;padding:8px">
    <select name="category_id">
      <option value="0">Choisir catégorie</option>
      <?php foreach ($cats as $c): ?>
        <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['label']); ?></option>
      <?php endforeach; ?>
    </select>
    <select name="scope_account_id">
      <option value="NULL">Global (tous les comptes)</option>
      <?php foreach ($accounts as $a): ?>
        <option value="<?php echo $a['id']; ?>"><?php echo htmlspecialchars($a['name']); ?></option>
      <?php endforeach; ?>
    </select>
    <input name="priority" value="100" style="width:70px;padding:6px">
    <button class="btn" type="submit">Créer</button>
  </div>
</form>

<h2>Liste des règles (<?php echo count($rules); ?>)</h2>
<table>
  <thead><tr><th>ID</th><th>Motif</th><th>Catégorie</th><th>Compte</th><th>Priorité</th><th>Actif</th><th>Actions</th></tr></thead>
  <tbody>
  <?php foreach ($rules as $r): ?>
    <tr>
      <td class="small"><?php echo $r['id']; ?></td>
      <td>
        <form method="post" style="display:flex;gap:6px;align-items:center">
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
          <input name="pattern" value="<?php echo htmlspecialchars($r['pattern']); ?>" style="flex:1;padding:6px">
      </td>
      <td>
          <select name="category_id">
            <?php foreach ($cats as $c): ?>
              <option value="<?php echo $c['id']; ?>"<?php echo ((int)$r['category_id'] === (int)$c['id']) ? ' selected' : ''; ?>><?php echo htmlspecialchars($c['label']); ?></option>
            <?php endforeach; ?>
          </select>
      </td>
      <td>
          <select name="scope_account_id">
            <option value="NULL"<?php echo ($r['scope_account_id'] === null ? ' selected' : ''); ?>>Global</option>
            <?php foreach ($accounts as $a): ?>
              <option value="<?php echo $a['id']; ?>"<?php echo ((string)$r['scope_account_id'] === (string)$a['id']) ? ' selected' : ''; ?>><?php echo htmlspecialchars($a['name']); ?></option>
            <?php endforeach; ?>
          </select>
      </td>
      <td><input name="priority" value="<?php echo (int)$r['priority']; ?>" style="width:70px;padding:6px"></td>
      <td><input type="checkbox" name="active" value="1" <?php echo ((int)$r['active']===1)?'checked':''; ?>></td>
      <td style="white-space:nowrap">
          <button class="btn" type="submit">Modifier</button>
        </form>
        <form method="post" style="display:inline" onsubmit="return confirm('Supprimer la règle ?');">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
          <button class="btn danger" type="submit">Supprimer</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

</body></html>
