<?php
// Gestion des catégories de classement (4 critères, 2 niveaux hiérarchiques)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

try {
  $pdo = require __DIR__ . '/mon-site/api/db.php';
} catch (Throwable $e) {
  echo '<h1>Erreur BDD</h1><pre>' . htmlspecialchars((string)$e) . '</pre>';
  exit;
}

// Noms des critères (paramétrables dans settings)
$criterionNames = [];
for ($i = 1; $i <= 4; $i++) {
  $s = $pdo->prepare('SELECT `value` FROM settings WHERE `key` = :k');
  $s->execute([':k' => "criterion_{$i}_name"]);
  $criterionNames[$i] = $s->fetchColumn() ?: "Critère $i";
}

$notice = null;

// --- Actions POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Renommer un critère
    if ($action === 'rename_criterion') {
        $crit = (int)($_POST['criterion'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        if ($crit >= 1 && $crit <= 4 && $name !== '') {
            $stmt = $pdo->prepare('REPLACE INTO settings (`key`, `value`) VALUES (:k, :v)');
            $stmt->execute([':k' => "criterion_{$crit}_name", ':v' => $name]);
            $criterionNames[$crit] = $name;
            $notice = 'Critère renommé.';
        }
    }

    // Ajouter un niveau 1 (parent)
    if ($action === 'add_level1') {
        $crit = (int)($_POST['criterion'] ?? 0);
        $label = trim((string)($_POST['label'] ?? ''));
        if ($crit >= 1 && $crit <= 4 && $label !== '') {
            $stmt = $pdo->prepare('INSERT INTO categories (criterion, parent_id, label, sort_order) VALUES (:c, NULL, :l, :s)');
            $max = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0)+1 FROM categories WHERE criterion = :c AND parent_id IS NULL');
            $max->execute([':c' => $crit]);
            $stmt->execute([':c' => $crit, ':l' => $label, ':s' => $max->fetchColumn()]);
            $notice = 'Niveau 1 ajouté.';
        }
    }

    // Ajouter un niveau 2 (enfant)
    if ($action === 'add_level2') {
        $parentId = (int)($_POST['parent_id'] ?? 0);
        $label = trim((string)($_POST['label'] ?? ''));
        if ($parentId > 0 && $label !== '') {
            // Récupérer le critère du parent
            $p = $pdo->prepare('SELECT criterion FROM categories WHERE id = :id');
            $p->execute([':id' => $parentId]);
            $crit = (int)$p->fetchColumn();
            if ($crit) {
                $max = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0)+1 FROM categories WHERE parent_id = :pid');
                $max->execute([':pid' => $parentId]);
                $stmt = $pdo->prepare('INSERT INTO categories (criterion, parent_id, label, sort_order) VALUES (:c, :pid, :l, :s)');
                $stmt->execute([':c' => $crit, ':pid' => $parentId, ':l' => $label, ':s' => $max->fetchColumn()]);
                $notice = 'Niveau 2 ajouté.';
            }
        }
    }

    // Modifier un libellé
    if ($action === 'edit') {
        $id = (int)($_POST['cat_id'] ?? 0);
        $label = trim((string)($_POST['label'] ?? ''));
        if ($id > 0 && $label !== '') {
            $stmt = $pdo->prepare('UPDATE categories SET label = :l WHERE id = :id');
            $stmt->execute([':l' => $label, ':id' => $id]);
            $notice = 'Libellé modifié.';
        }
    }

    // Supprimer
    if ($action === 'delete') {
        $id = (int)($_POST['cat_id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM categories WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $notice = 'Catégorie supprimée.';
        }
    }
}

// Charger toutes les catégories regroupées par critère
$allCats = $pdo->query('SELECT * FROM categories ORDER BY criterion, sort_order, label')->fetchAll(PDO::FETCH_ASSOC);
$tree = []; // criterion => [ parents => [ ...children ] ]
foreach ($allCats as $c) {
    $cr = $c['criterion'];
    if (!isset($tree[$cr])) $tree[$cr] = [];
    if ($c['parent_id'] === null) {
        if (!isset($tree[$cr][$c['id']])) $tree[$cr][$c['id']] = ['info' => $c, 'children' => []];
        else $tree[$cr][$c['id']]['info'] = $c;
    } else {
        if (!isset($tree[$cr][$c['parent_id']])) $tree[$cr][$c['parent_id']] = ['info' => null, 'children' => []];
        $tree[$cr][$c['parent_id']]['children'][] = $c;
    }
}
?><!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Catégories — bkTool</title>
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="site-header">
  <div class="site-title">bkTool</div>
  <nav class="tabs">
    <a href="index.php">Dashboard</a>
    <a href="accounts.php">Comptes</a>
    <a href="transactions.php">Transactions</a>
    <a href="categories.php" class="active">Catégories</a>
    <a href="choix.php">Connecter banque</a>
  </nav>
</div>
<main>
  <h1>Catégories de classement</h1>
  <?php if ($notice): ?>
    <p><strong><?php echo htmlspecialchars($notice); ?></strong></p>
  <?php endif; ?>

  <!-- Sélection du critère à afficher -->
  <?php $selectedCrit = isset($_GET['crit']) ? (int)$_GET['crit'] : 0; ?>
  <form method="get" style="margin-bottom:16px;display:flex;gap:12px;align-items:center">
    <label><strong>Critère :</strong>
      <select name="crit" onchange="this.form.submit()">
        <option value="0">— Tous —</option>
        <?php for ($i = 1; $i <= 4; $i++): ?>
          <option value="<?php echo $i; ?>" <?php echo ($selectedCrit === $i) ? 'selected' : ''; ?>>
            <?php echo htmlspecialchars($criterionNames[$i]); ?>
          </option>
        <?php endfor; ?>
      </select>
    </label>
  </form>

  <?php for ($crit = 1; $crit <= 4; $crit++):
    if ($selectedCrit > 0 && $selectedCrit !== $crit) continue;
  ?>
  <section class="cat-section">
    <h2>
      <?php echo htmlspecialchars($criterionNames[$crit]); ?>
      <small style="font-weight:normal;font-size:.8rem;color:#999">(critère <?php echo $crit; ?>)</small>
    </h2>
    <!-- Renommer le critère -->
    <form method="post" style="display:inline-flex;gap:6px;margin-bottom:8px">
      <input type="hidden" name="action" value="rename_criterion">
      <input type="hidden" name="criterion" value="<?php echo $crit; ?>">
      <input type="text" name="name" value="<?php echo htmlspecialchars($criterionNames[$crit]); ?>" style="width:160px" required>
      <button type="submit" title="Renommer">✏️ Renommer</button>
    </form>

    <?php if (!empty($tree[$crit])): ?>
    <table style="width:100%;margin-bottom:6px">
      <thead><tr><th style="width:40%">Niveau 1</th><th style="width:40%">Niveau 2</th><th style="width:20%">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($tree[$crit] as $parentId => $node):
        if (!$node['info']) continue;
        $parent = $node['info'];
        $children = $node['children'];
        $rowspan = max(1, count($children) + 1);
      ?>
        <tr>
          <td rowspan="<?php echo $rowspan; ?>" style="vertical-align:top;font-weight:600;background:#f9f9f9">
            <form method="post" style="display:inline-flex;gap:4px">
              <input type="hidden" name="action" value="edit">
              <input type="hidden" name="cat_id" value="<?php echo $parent['id']; ?>">
              <input type="text" name="label" value="<?php echo htmlspecialchars($parent['label']); ?>" style="width:130px" required>
              <button type="submit" title="Modifier">💾</button>
            </form>
            <form method="post" style="display:inline">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="cat_id" value="<?php echo $parent['id']; ?>">
              <button type="submit" title="Supprimer" onclick="return confirm('Supprimer ce niveau et ses sous-niveaux ?')">🗑️</button>
            </form>
          </td>
          <?php if (empty($children)): ?>
          <td colspan="2"><em>Aucun sous-niveau</em></td>
          <?php else: $first = true; foreach ($children as $child): if (!$first) echo '</tr><tr>'; $first = false; ?>
          <td>
            <form method="post" style="display:inline-flex;gap:4px">
              <input type="hidden" name="action" value="edit">
              <input type="hidden" name="cat_id" value="<?php echo $child['id']; ?>">
              <input type="text" name="label" value="<?php echo htmlspecialchars($child['label']); ?>" style="width:130px" required>
              <button type="submit" title="Modifier">💾</button>
            </form>
          </td>
          <td>
            <form method="post" style="display:inline">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="cat_id" value="<?php echo $child['id']; ?>">
              <button type="submit" title="Supprimer" onclick="return confirm('Supprimer ?')">🗑️</button>
            </form>
          </td>
          <?php endforeach; endif; ?>
        </tr>
        <!-- Ajouter niveau 2 sous ce parent -->
        <tr>
          <td colspan="2">
            <form method="post" style="display:inline-flex;gap:4px">
              <input type="hidden" name="action" value="add_level2">
              <input type="hidden" name="parent_id" value="<?php echo $parent['id']; ?>">
              <input type="text" name="label" placeholder="Nouveau sous-niveau…" style="width:140px" required>
              <button type="submit">+ Niveau 2</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

    <!-- Ajouter niveau 1 -->
    <form method="post" style="display:inline-flex;gap:6px;margin-top:4px">
      <input type="hidden" name="action" value="add_level1">
      <input type="hidden" name="criterion" value="<?php echo $crit; ?>">
      <input type="text" name="label" placeholder="Nouveau niveau 1…" style="width:160px" required>
      <button type="submit">+ Niveau 1</button>
    </form>
  </section>
  <hr>
  <?php endfor; ?>
</main>
</body>
</html>
