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

$accounts = $pdo->query('SELECT id, name, currency, balance, color, alert_threshold, numero_affichage, updated_at FROM accounts ORDER BY (numero_affichage IS NULL), numero_affichage, name')->fetchAll(PDO::FETCH_ASSOC);
$accMap = [];
foreach ($accounts as $ac) $accMap[$ac['id']] = $ac['name'];
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
      $critRaw = $_POST['criterion'] ?? '';
      $crit = ($critRaw === 'group') ? 0 : (int)$critRaw;
      $label = trim((string)($_POST['label'] ?? ''));
      if (($crit === 0 || ($crit >= 1 && $crit <= 4)) && $label !== '') {
        $stmt = $pdo->prepare('INSERT INTO categories (criterion, parent_id, label, sort_order) VALUES (:c, NULL, :l, :s)');
        $max = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0)+1 FROM categories WHERE criterion = :c AND parent_id IS NULL');
        $max->execute([':c' => $crit]);
        $stmt->execute([':c' => $crit, ':l' => $label, ':s' => $max->fetchColumn()]);
        $newId = $pdo->lastInsertId();
        $notice = 'Niveau 1 ajouté.';
        if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
          header('Content-Type: application/json');
          echo json_encode(['ok' => true, 'id' => (int)$newId, 'label' => $label, 'criterion' => $crit]);
          exit;
        }
      }
    }

    // Ajouter un niveau 2 (enfant)
    if ($action === 'add_level2') {
      $parentId = (int)($_POST['parent_id'] ?? 0);
      if ($parentId > 0) {
        // Récupérer le critère du parent
        $p = $pdo->prepare('SELECT criterion FROM categories WHERE id = :id');
        $p->execute([':id' => $parentId]);
        $crit = $p->fetchColumn();
        $crit = ($crit === null) ? null : (int)$crit;
        if ($crit !== null) {
          $max = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0)+1 FROM categories WHERE parent_id = :pid');
          $max->execute([':pid' => $parentId]);
          if ($crit === 0) {
            // For group type, expect an account_id field
            $accountId = trim((string)($_POST['account_id'] ?? ''));
            if ($accountId !== '') {
              $stmt = $pdo->prepare('INSERT INTO categories (criterion, parent_id, label, sort_order) VALUES (0, :pid, :l, :s)');
              $stmt->execute([':pid' => $parentId, ':l' => $accountId, ':s' => $max->fetchColumn()]);
              $newId = $pdo->lastInsertId();
              $notice = 'Compte ajouté au regroupement.';
              if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'id' => (int)$newId, 'label' => $accountId, 'criterion' => 0]);
                exit;
              }
            }
          } else {
            $label = trim((string)($_POST['label'] ?? ''));
            if ($label !== '') {
              $stmt = $pdo->prepare('INSERT INTO categories (criterion, parent_id, label, sort_order) VALUES (:c, :pid, :l, :s)');
              $stmt->execute([':c' => $crit, ':pid' => $parentId, ':l' => $label, ':s' => $max->fetchColumn()]);
              $newId = $pdo->lastInsertId();
              $notice = 'Niveau 2 ajouté.';
              if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'id' => (int)$newId, 'label' => $label, 'criterion' => $crit]);
                exit;
              }
            }
          }
        }
      }
    }

    // Modifier un libellé / associer un compte pour regroupement
    if ($action === 'edit') {
      $id = (int)($_POST['cat_id'] ?? 0);
      if ($id > 0) {
        // déterminer crit
        $q = $pdo->prepare('SELECT criterion, parent_id FROM categories WHERE id = :id');
        $q->execute([':id' => $id]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if ($row) {
          $crit = (int)$row['criterion'];
          if ($crit === 0 && $row['parent_id'] !== null) {
            // child of group: expect account_id
            $accountId = trim((string)($_POST['account_id'] ?? ''));
            if ($accountId !== '') {
              $stmt = $pdo->prepare('UPDATE categories SET label = :l WHERE id = :id');
              $stmt->execute([':l' => $accountId, ':id' => $id]);
              $notice = 'Association compte modifiée.';
              if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'id' => $id, 'label' => $accountId, 'criterion' => 0]);
                exit;
              }
            }
          } else {
            $label = trim((string)($_POST['label'] ?? ''));
            if ($label !== '') {
              // If this is a level 2 (child) entry, concat parent label + '/' + child label for storage
              if ($row['parent_id'] !== null) {
                $pstmt = $pdo->prepare('SELECT label FROM categories WHERE id = :pid');
                $pstmt->execute([':pid' => $row['parent_id']]);
                $parentLabel = $pstmt->fetchColumn();
                if ($parentLabel !== false && $parentLabel !== null && $parentLabel !== '') {
                  // If user sent a label already prefixed with parentLabel/, strip it to avoid double-prefix
                  if (strpos($label, $parentLabel . '/') === 0) {
                    $label = substr($label, strlen($parentLabel) + 1);
                  }
                  $labelToStore = $parentLabel . '/' . $label;
                } else {
                  $labelToStore = $label;
                }
              } else {
                $labelToStore = $label;
              }
              $stmt = $pdo->prepare('UPDATE categories SET label = :l WHERE id = :id');
              $stmt->execute([':l' => $labelToStore, ':id' => $id]);
              $notice = 'Libellé modifié.';
              if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'id' => $id, 'label' => $labelToStore, 'criterion' => $crit]);
                exit;
              }
            }
          }
        }
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

    // Edit account parameter (color) from Paramètres -> Comptes
    if ($action === 'edit_account_param') {
      $accountId = trim((string)($_POST['account_id'] ?? ''));
      $color = trim((string)($_POST['color'] ?? '')) ?: null;
      if ($accountId !== '') {
        $stmt = $pdo->prepare('UPDATE accounts SET color = :color, updated_at = NOW() WHERE id = :id');
        $stmt->execute([':color' => $color, ':id' => $accountId]);
        $notice = 'Paramètre compte mis à jour.';
        // refresh accounts list/map
        $accounts = $pdo->query('SELECT id, name FROM accounts ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
        $accMap = [];
        foreach ($accounts as $ac) $accMap[$ac['id']] = $ac['name'];
      }
    }
    
    // Edit full account fields (name, currency, color) from Paramètres -> Comptes
    if ($action === 'edit_account') {
      $accountId = trim((string)($_POST['account_id'] ?? ''));
      if ($accountId !== '') {
        // load existing values
        $q = $pdo->prepare('SELECT name, currency, color, alert_threshold, numero_affichage FROM accounts WHERE id = :id');
        $q->execute([':id' => $accountId]);
        $existing = $q->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
          // determine new values: use posted values only when present, otherwise keep existing
          $name = array_key_exists('name', $_POST) ? trim((string)($_POST['name'] ?? '')) : $existing['name'];
          $currency = array_key_exists('currency', $_POST) ? trim((string)($_POST['currency'] ?? '')) : $existing['currency'];
          $color = array_key_exists('color', $_POST) ? (trim((string)($_POST['color'] ?? '')) ?: null) : $existing['color'];
          $alert = array_key_exists('alert_threshold', $_POST) ? (($_POST['alert_threshold'] !== '') ? (float)$_POST['alert_threshold'] : null) : $existing['alert_threshold'];
          $numeroAff = array_key_exists('numero_affichage', $_POST) ? (($_POST['numero_affichage'] !== '') ? (int)$_POST['numero_affichage'] : null) : $existing['numero_affichage'];

          // require a non-empty name
          if ($name !== '') {
            $stmt = $pdo->prepare('UPDATE accounts SET name = :name, currency = :currency, color = :color, alert_threshold = :alert, numero_affichage = :numaff, updated_at = NOW() WHERE id = :id');
            $stmt->execute([':name' => $name, ':currency' => $currency, ':color' => $color, ':alert' => $alert, ':numaff' => $numeroAff, ':id' => $accountId]);
            $notice = 'Compte mis à jour.';
            // refresh accounts list/map with full columns
            $accounts = $pdo->query('SELECT id, name, currency, balance, color, alert_threshold, numero_affichage, updated_at FROM accounts ORDER BY (numero_affichage IS NULL), numero_affichage, name')->fetchAll(PDO::FETCH_ASSOC);
            $accMap = [];
            foreach ($accounts as $ac) $accMap[$ac['id']] = $ac['name'];
          }
        }
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
  <title>Paramètres — bkTool</title>
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>
<main>
  <h1>Paramètres</h1>
  <?php if ($notice): ?>
    <p><strong><?php echo htmlspecialchars($notice); ?></strong></p>
  <?php endif; ?>

  <!-- Sélection du type de paramètre à afficher -->
  <?php $selectedCrit = isset($_GET['crit']) ? (string)$_GET['crit'] : ''; ?>
  <form method="get" style="margin-bottom:16px;display:flex;gap:12px;align-items:center">
    <label><strong>Type de paramètre :</strong>
      <select name="crit" onchange="this.form.submit()">
        <option value="">— Aucun —</option>
        <option value="account" <?php echo ($selectedCrit === 'account') ? 'selected' : ''; ?>>Paramètres de compte</option>
        <option value="group" <?php echo ($selectedCrit === 'group') ? 'selected' : ''; ?>>Regroupement compte</option>
        <?php for ($i = 1; $i <= 4; $i++): ?>
          <option value="<?php echo $i; ?>" <?php echo ($selectedCrit === (string)$i) ? 'selected' : ''; ?>>
            <?php echo htmlspecialchars($criterionNames[$i]); ?>
          </option>
        <?php endfor; ?>
      </select>
    </label>
  </form>

  <?php
  // Show group section
  if ($selectedCrit === 'group'):
  ?>
  <section class="cat-section">
    <h2>Regroupement compte</h2>
    <?php if (!empty($tree[0])): ?>
    <table style="width:100%;margin-bottom:6px">
      <thead><tr><th style="width:40%">Groupe</th><th style="width:40%">Membre (compte)</th><th style="width:20%">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($tree[0] as $parentId => $node):
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
              <button type="submit" title="Supprimer" onclick="return confirm('Supprimer ce groupe et ses membres ?')">🗑️</button>
            </form>
          </td>
          <?php if (empty($children)): ?>
          <td colspan="2"><em>Aucun compte associé</em></td>
          <?php else: $first = true; foreach ($children as $child): if (!$first) echo '</tr><tr>'; $first = false; ?>
          <td>
            <form method="post" style="display:inline-flex;gap:4px">
              <input type="hidden" name="action" value="edit">
              <input type="hidden" name="cat_id" value="<?php echo $child['id']; ?>">
              <select name="account_id" required>
                <?php foreach ($accounts as $ac): ?>
                  <option value="<?php echo htmlspecialchars($ac['id']); ?>" <?php echo ($child['label'] === $ac['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($ac['name'] ?: $ac['id']); ?></option>
                <?php endforeach; ?>
              </select>
              <button type="submit" title="Modifier">💾</button>
            </form>
          </td>
          <td>
            <form method="post" style="display:inline">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="cat_id" value="<?php echo $child['id']; ?>">
              <button type="submit" title="Supprimer" onclick="return confirm('Supprimer ce membre ?')">🗑️</button>
            </form>
          </td>
          <?php endforeach; endif; ?>
        </tr>
        <!-- Ajouter membre sous ce groupe -->
        <tr>
          <td colspan="2">
            <form method="post" style="display:inline-flex;gap:4px">
              <input type="hidden" name="action" value="add_level2">
              <input type="hidden" name="parent_id" value="<?php echo $parent['id']; ?>">
              <select name="account_id" required>
                <option value="">— Choisir un compte —</option>
                <?php foreach ($accounts as $ac): ?>
                  <option value="<?php echo htmlspecialchars($ac['id']); ?>"><?php echo htmlspecialchars($ac['name'] ?: $ac['id']); ?></option>
                <?php endforeach; ?>
              </select>
              <button type="submit">+ Ajouter au groupe</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

    <!-- Ajouter groupe -->
    <form method="post" style="display:inline-flex;gap:6px;margin-top:4px">
      <input type="hidden" name="action" value="add_level1">
      <input type="hidden" name="criterion" value="group">
      <input type="text" name="label" placeholder="Nouveau groupe…" style="width:160px" required>
      <button type="submit">+ Nouveau groupe</button>
    </form>
  </section>
  <hr>
  <?php
  endif;

  // Paramètres de compte
  if ($selectedCrit === 'account'):
?>
  <section class="cat-section">
    <h2>Paramètres de comptes</h2>
    <p>Modifier les paramètres par compte (libellé, devise, couleur).</p>
    <?php if (!empty($accounts)): ?>
      <style>
        .accounts-accordion .acc-item { border:1px solid #eee; margin-bottom:8px; border-radius:6px; overflow:hidden; }
        .accounts-accordion .acc-header { width:100%; text-align:left; padding:10px; background:#e6e6e6; border:none; display:flex; justify-content:space-between; align-items:center; cursor:pointer; }
        .accounts-accordion .acc-header .acc-title a { font-weight:700; color:inherit; text-decoration:none; }
        .accounts-accordion .acc-body { padding:12px; background:#fff; }
        .acc-form label { display:block; font-weight:600; margin-bottom:4px; }
        .acc-form .field { margin-bottom:8px; }
        .acc-form input[type="text"], .acc-form input[type="number"], .acc-form select, .acc-form input[type="color"] { width:100%; padding:6px; box-sizing:border-box; }
        .acc-grid { display:grid; grid-template-columns: 220px 1fr; gap:12px; align-items:center; }
        .acc-meta { font-weight:400; color:#666; font-size:0.95rem }
      </style>

      <div class="accounts-accordion">
        <?php $first = true; foreach ($accounts as $ac): ?>
          <div class="acc-item<?php echo $first ? ' open' : ''; ?>" data-acc-id="<?php echo htmlspecialchars($ac['id']); ?>">
            <button type="button" class="acc-header">
              <span class="acc-title"><a href="#" class="acc-rename" data-acc-id="<?php echo htmlspecialchars($ac['id']); ?>"><?php echo htmlspecialchars($ac['name'] ?: $ac['id']); ?></a></span>
              <span class="acc-meta"><?php echo htmlspecialchars($ac['currency'] ?? ''); ?> — Solde <?php echo htmlspecialchars((string)($ac['balance'] ?? '0')); ?></span>
            </button>
            <div class="acc-body" <?php echo $first ? '' : 'style="display:none"'; ?>>
              <form method="post" class="acc-form">
                <div class="acc-grid">
                  <div class="cell label">Numéro affichage</div>
                  <div class="cell value"><input type="number" name="numero_affichage" value="<?php echo htmlspecialchars((string)($ac['numero_affichage'] ?? '')); ?>"></div>

                  <div class="cell label">Libellé</div>
                  <div class="cell value"><input type="text" name="name" value="<?php echo htmlspecialchars($ac['name'] ?? ''); ?>" required></div>

                  <div class="cell label">Devise</div>
                  <div class="cell value"><input type="text" name="currency" value="<?php echo htmlspecialchars($ac['currency'] ?? ''); ?>"></div>

                  <div class="cell label">Couleur</div>
                  <div class="cell value"><input type="color" name="color" value="<?php echo htmlspecialchars($ac['color'] ?? '#000000'); ?>"></div>

                  <div class="cell label">Seuil alerte</div>
                  <div class="cell value"><input type="number" step="0.01" name="alert_threshold" value="<?php echo htmlspecialchars((string)($ac['alert_threshold'] ?? '')); ?>" placeholder="ex: -50.00"></div>

                  <div class="cell label">Dernière MAJ</div>
                  <div class="cell value"><?php echo htmlspecialchars((string)($ac['updated_at'] ?? '')); ?></div>
                </div>
                <div style="margin-top:8px;display:flex;gap:8px;align-items:center"><input type="hidden" name="action" value="edit_account"><input type="hidden" name="account_id" value="<?php echo htmlspecialchars($ac['id']); ?>"><button type="submit">Enregistrer</button></div>
              </form>
            </div>
          </div>
        <?php $first = false; endforeach; ?>
      </div>

      <script>
        (function(){
          var acc = document.querySelectorAll('.accounts-accordion .acc-item');
          function closeAll(){ acc.forEach(function(item){ item.classList.remove('open'); var body = item.querySelector('.acc-body'); if(body) body.style.display='none'; }); }
          acc.forEach(function(item){ var header = item.querySelector('.acc-header'); header.addEventListener('click', function(){ var open = item.classList.contains('open'); if(open){ item.classList.remove('open'); item.querySelector('.acc-body').style.display='none'; } else { closeAll(); item.classList.add('open'); item.querySelector('.acc-body').style.display='block'; } });

            // rename handler on the account name link
            var renameLink = item.querySelector('.acc-rename');
            if(renameLink){ renameLink.addEventListener('click', function(ev){ ev.preventDefault(); ev.stopPropagation(); var accId = this.getAttribute('data-acc-id'); var current = this.textContent || ''; var newName = prompt('Nouveau libellé pour le compte', current); if(newName === null) return; newName = newName.trim(); if(newName === '' || newName === current) return; var fd = new FormData(); fd.append('action','edit_account'); fd.append('account_id', accId); fd.append('name', newName);
                fetch(location.pathname + location.search, { method: 'POST', body: fd }).then(function(r){ if(r.ok) location.reload(); else alert('Erreur lors du renommage'); }).catch(function(){ alert('Erreur réseau'); }); }); }
          });
        })();
      </script>
    <?php else: ?>
      <p>Aucun compte trouvé.</p>
    <?php endif; ?>
  </section>
  <hr>
  <?php
  endif;

  // Show criteria sections only when explicitly selected (hide on 'Aucun')
  for ($crit = 1; $crit <= 4; $crit++):
    if ($selectedCrit === '' || $selectedCrit !== (string)$crit) continue;
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
      <thead><tr><th style="width:30%">Niveau 1</th><th style="width:40%">Niveau 2</th><th style="width:30%">Actions</th></tr></thead>
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
              <?php
                // Prepare display label: if stored value contains "Parent/Child", strip the parent prefix for the editable field
                $displayLabel = $child['label'];
                if (isset($parent['label']) && $parent['label'] !== '' && strpos($child['label'], $parent['label'].'/') === 0) {
                  $displayLabel = substr($child['label'], strlen($parent['label']) + 1);
                }
              ?>
              <input type="text" name="label" value="<?php echo htmlspecialchars($displayLabel); ?>" style="width:170px" required>
              <button type="submit" title="Modifier">💾</button>
            </form>
          </td>
          <td>
            <form method="post" style="display:inline">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="cat_id" value="<?php echo $child['id']; ?>">
              <button type="submit" title="Supprimer" onclick="return confirm('Supprimer ?')">🗑️</button>
            </form>
            <?php
              // Display the raw stored value from DB (do not pre-concatenate here)
              $storedLabel = isset($child['label']) ? $child['label'] : '';
            ?>
            <div style="display:inline-block;margin-left:8px;vertical-align:middle;color:#333;max-width:320px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
              <?php echo htmlspecialchars($storedLabel); ?>
            </div>
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
