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

// criterion names (label for Catégorie 1..4)
$criterionNames = [];
for ($i = 1; $i <= 4; $i++) {
  $s = $pdo->prepare('SELECT `value` FROM settings WHERE `key` = :k');
  $s->execute([':k' => "criterion_{$i}_name"]);
  $criterionNames[$i] = $s->fetchColumn() ?: "Catégorie $i";
}

// Handle POST actions: update or delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  if ($action === 'update' && !empty($_POST['id'])) {
    $id = (int)$_POST['id'];
    $pattern = $_POST['pattern'] ?? '';
    $is_regex = !empty($_POST['is_regex']) ? 1 : 0;
    // New fields: category_level (1..4) and valeur_a_affecter (category id to set)
    $category_level = isset($_POST['category_level']) ? (int)$_POST['category_level'] : 0;
    $valeur_a_affecter = isset($_POST['valeur_a_affecter']) ? (int)$_POST['valeur_a_affecter'] : 0;
    // Backwards-compat: use valeur_a_affecter as category_id for older code
    $category_id = $valeur_a_affecter ?: (isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0);
    $scope_account_id = $_POST['scope_account_id'] !== '' ? ($_POST['scope_account_id'] === 'NULL' ? null : (int)$_POST['scope_account_id']) : null;
    $priority = isset($_POST['priority']) ? (int)$_POST['priority'] : 100;
    $active = !empty($_POST['active']) ? 1 : 0;
    // If pattern contains % treat it as wildcard -> build regex
    if (strpos($pattern, '%') !== false) {
      $is_regex = 1;
      $parts = explode('%', $pattern);
      $escaped = array_map(function($p){ return preg_quote($p, '/'); }, $parts);
      $joined = implode('.*', $escaped);
      $pattern = '/' . $joined . '/i';
    }
    // prefer to update new columns if present (valeur_a_affecter, category_level)
    $cols = ['pattern = :p', 'is_regex = :ir', 'category_id = :cid', 'scope_account_id = :scope', 'priority = :prio', 'active = :act'];
    $params = [':p'=>$pattern,':ir'=>$is_regex,':cid'=>$category_id,':scope'=>$scope_account_id,':prio'=>$priority,':act'=>$active,':id'=>$id];
    // detect additional columns
    $hasVal = false; $hasLevel = false;
    try {
      $desc = $pdo->query("DESCRIBE auto_category_rules")->fetchAll(PDO::FETCH_COLUMN);
      $hasVal = in_array('valeur_a_affecter', $desc, true);
      $hasLevel = in_array('category_level', $desc, true);
    } catch (Throwable $e) { /* ignore - older schema */ }
    if ($hasVal) { $cols[] = 'valeur_a_affecter = :val'; $params[':val'] = $valeur_a_affecter; }
    if ($hasLevel) { $cols[] = 'category_level = :clevel'; $params[':clevel'] = $category_level; }
    $sql = 'UPDATE auto_category_rules SET ' . implode(', ', $cols) . ' WHERE id = :id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
  } elseif ($action === 'delete' && !empty($_POST['id'])) {
    $id = (int)$_POST['id'];
    $stmt = $pdo->prepare('DELETE FROM auto_category_rules WHERE id = :id');
    $stmt->execute([':id'=>$id]);
  } elseif ($action === 'create') {
    $pattern = $_POST['pattern'] ?? '';
    $is_regex = !empty($_POST['is_regex']) ? 1 : 0;
    $category_level = isset($_POST['category_level']) ? (int)$_POST['category_level'] : 0;
    $valeur_a_affecter = isset($_POST['valeur_a_affecter']) ? (int)$_POST['valeur_a_affecter'] : 0;
    $category_id = $valeur_a_affecter ?: (isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0);
    $scope_account_id = $_POST['scope_account_id'] !== '' ? ($_POST['scope_account_id'] === 'NULL' ? null : (int)$_POST['scope_account_id']) : null;
    $priority = isset($_POST['priority']) ? (int)$_POST['priority'] : 100;
    // If pattern contains % treat it as wildcard -> build regex
    if (strpos($pattern, '%') !== false) {
      $is_regex = 1;
      $parts = explode('%', $pattern);
      $escaped = array_map(function($p){ return preg_quote($p, '/'); }, $parts);
      $joined = implode('.*', $escaped);
      $pattern = '/' . $joined . '/i';
    }
    if ($pattern !== '' && $category_id > 0) {
      // insert, prefer to include new columns when present
      $cols = ['pattern','is_regex','category_id','scope_account_id','priority','active','created_by'];
      $placeholders = [':p',':ir',':cid',':scope',':prio',':act',':cb'];
      $params = [':p'=>$pattern,':ir'=>$is_regex,':cid'=>$category_id,':scope'=>$scope_account_id,':prio'=>$priority,':act'=>1,':cb'=>($_SERVER['REMOTE_USER'] ?? null)];
      try {
        $desc = $pdo->query("DESCRIBE auto_category_rules")->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('valeur_a_affecter',$desc,true)) { $cols[]='valeur_a_affecter'; $placeholders[]=':val'; $params[':val']=$valeur_a_affecter; }
        if (in_array('category_level',$desc,true)) { $cols[]='category_level'; $placeholders[]=':clevel'; $params[':clevel']=$category_level; }
      } catch (Throwable $e) { /* ignore */ }
      $sql = 'INSERT INTO auto_category_rules (' . implode(',',$cols) . ') VALUES (' . implode(',',$placeholders) . ')';
      $stmt = $pdo->prepare($sql);
      $stmt->execute($params);
    }
  }
  // redirect to GET to avoid re-submission
  header('Location: rules.php');
  exit;
}

$accountFilter = $_GET['account'] ?? ''; // default: no account selected
$categoryFilter = isset($_GET['category']) ? (int)$_GET['category'] : 0;

// Load accounts (ordered by numero_affichage then name) and categories
$accounts = $pdo->query('SELECT id, name FROM accounts ORDER BY (numero_affichage IS NULL), numero_affichage ASC, name ASC')->fetchAll(PDO::FETCH_ASSOC);

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

$catMap = [];
foreach ($allCats as $c) $catMap[$c['id']] = $c['label'];

// Build WHERE (account filter: 'global' => scope_account_id IS NULL, 'any' => no account filter)
$where = [];
$params = [];
if ($accountFilter === 'global') {
  $where[] = 'scope_account_id IS NULL';
} elseif ($accountFilter === 'any') {
  // no account filtering: include both global and account-specific rules
} elseif ($accountFilter !== '') {
  // specific account selected
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
    <select name="account" onchange="this.form.submit()">
      <option value=""<?php echo ($accountFilter==='') ? ' selected' : ''; ?>>-- pas de sélection --</option>
      <option value="global"<?php echo ($accountFilter==='global') ? ' selected' : ''; ?>>Globales (Tous comptes)</option>
      <?php foreach ($accounts as $a): ?>
        <option value="<?php echo $a['id']; ?>"<?php echo ((string)$accountFilter === (string)$a['id']) ? ' selected' : ''; ?>><?php echo htmlspecialchars($a['name']); ?></option>
      <?php endforeach; ?>
      <option value="any"<?php echo ($accountFilter==='any')?' selected':''; ?>>Toutes (globales+comptes)</option>
    </select>
  </label>
  <button class="btn" type="submit">Filtrer</button>
</form>

<h2>Nouvelle règle</h2>
<form method="post" style="margin-bottom:12px">
  <input type="hidden" name="action" value="create">
  <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px">
    <label style="display:flex;align-items:center;gap:6px"><input type="checkbox" name="active" value="1" checked> Actif</label>
    <select name="category_level" class="select-category-level" style="width:160px">
      <option value="0">Choisir critère (1..4)</option>
      <?php for ($ci=1;$ci<=4;$ci++): ?>
        <option value="<?php echo $ci; ?>"><?php echo $ci . ' - ' . htmlspecialchars($criterionNames[$ci]); ?></option>
      <?php endfor; ?>
    </select>
    <input name="pattern" placeholder="Motif / libellé" style="flex:2;padding:8px">
    <label style="margin-left:6px"><input type="checkbox" name="is_regex" value="1"> regexp</label>
  </div>
  <div style="display:flex;gap:8px;align-items:center">
    <select name="scope_account_id">
      <option value="">-- pas de sélection --</option>
      <option value="NULL">Global (tous les comptes)</option>
      <?php foreach ($accounts as $a): ?>
        <option value="<?php echo $a['id']; ?>"><?php echo htmlspecialchars($a['name']); ?></option>
      <?php endforeach; ?>
    </select>
    <input name="priority" value="100" style="width:70px;padding:6px">
    <select name="valeur_a_affecter" class="select-valeur" style="min-width:180px;margin-left:auto">
      <option value="0">Valeur à affecter (sélectionner critère)</option>
    </select>
    <button class="btn" type="submit">Créer</button>
  </div>
</form>

<?php if ($accountFilter !== ''): ?>
<table>
  <thead>
    <tr style="background:#ccc;color:#111">
      <th rowspan="2">N°</th>
      <th>Critère</th>
      <th colspan="2">Motif</th>
      <th>Regexp</th>
      <th>Actions</th>
    </tr>
    <tr style="background:#ddd;color:#111">
      <th>Actif</th>
      <th>Compte</th>
      <th>Priorité</th>
      <th>Valeur</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
  <?php $i = 0; foreach ($rules as $r): 
        $i++;
        $r_level = isset($r['category_level']) ? (int)$r['category_level'] : 0;
        $r_valeur = isset($r['valeur_a_affecter']) ? (int)$r['valeur_a_affecter'] : ((isset($r['category_id']) ? (int)$r['category_id'] : 0));
        $bg = ($i % 2 === 0) ? 'silver' : 'gray';
  ?>
    <tr data-r-level="<?php echo $r_level; ?>" data-r-valeur="<?php echo $r_valeur; ?>" style="background:<?php echo $bg; ?>">
      <td rowspan="2" class="small" style="font-size:26px;font-weight:bold;color:#000;text-align:center;vertical-align:middle"><?php echo $r['id']; ?></td>
      <td>
        Critère<br>
        <select name="category_level[<?php echo $r['id']; ?>]" class="select-category-level-row">
          <option value="0">Choisir critère</option>
          <?php for ($ci=1;$ci<=4;$ci++): ?>
            <option value="<?php echo $ci; ?>"<?php echo ($r_level === $ci) ? ' selected' : ''; ?>><?php echo $ci . ' - ' . htmlspecialchars($criterionNames[$ci]); ?></option>
          <?php endfor; ?>
        </select>
      </td>
      <td colspan="2">
        Motif<br>
        <input name="pattern[<?php echo $r['id']; ?>]" value="<?php echo htmlspecialchars($r['pattern']); ?>" style="width:100%;padding:6px">
      </td>
      <td>
        Regexp<br>
        <input type="checkbox" name="is_regex[<?php echo $r['id']; ?>]" value="1" <?php echo (!empty($r['is_regex']) ? 'checked' : ''); ?>>
      </td>
      <td>
        <!-- Placeholder cell for actions: unified form is in the second row -->
      </td>
    </tr>
    <tr style="background:<?php echo $bg; ?>">
      <td>
        Actif<br>
        <input type="checkbox" name="active[<?php echo $r['id']; ?>]" value="1" <?php echo ((int)$r['active']===1)?'checked':''; ?>>
      </td>
      <td>
        Compte<br>
        <select name="scope_account_id[<?php echo $r['id']; ?>]">
          <option value="">-- pas de sélection --</option>
          <option value="NULL"<?php echo ($r['scope_account_id'] === null ? ' selected' : ''); ?>>Global</option>
          <?php foreach ($accounts as $a): ?>
            <option value="<?php echo $a['id']; ?>"<?php echo ((string)$r['scope_account_id'] === (string)$a['id']) ? ' selected' : ''; ?>><?php echo htmlspecialchars($a['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </td>
      <td>
        Priorité<br>
        <input name="priority[<?php echo $r['id']; ?>]" value="<?php echo (int)$r['priority']; ?>" style="width:70px;padding:6px">
      </td>
      <td>
        Valeur à affecter<br>
        <select name="valeur_a_affecter[<?php echo $r['id']; ?>]" class="select-valeur-row" style="min-width:180px">
          <option value="0">Choisir valeur</option>
        </select>
      </td>
      <td>
        <form id="update-form-<?php echo $r['id']; ?>" method="post" style="display:inline">
          <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="pattern" value="">
          <input type="hidden" name="is_regex" value="0">
          <input type="hidden" name="category_level" value="0">
          <input type="hidden" name="scope_account_id" value="">
          <input type="hidden" name="priority" value="0">
          <input type="hidden" name="valeur_a_affecter" value="0">
          <input type="hidden" name="active" value="0">
          <button class="btn" type="button" onclick="showRuleSql(<?php echo $r['id']; ?>)">Modifier</button>
          <button class="btn danger" type="submit" name="action" value="delete" onclick="return confirm('Supprimer la règle ?');">Supprimer</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

</body></html>
<script>
// Auto-submit filter form once on first page load in this tab to trigger initial search
(function(){
  try {
    var qs = location.search || '';
    var applied = sessionStorage.getItem('rules_auto_submitted');
    if (!applied) {
      // if no query string or no explicit filter params, submit to apply defaults
      if (!/([?&](account|category)=)/.test(qs)) {
        var f = document.querySelector('form.controls');
        if (f) { sessionStorage.setItem('rules_auto_submitted','1'); f.submit(); }
      }
    }
  } catch(e) { /* ignore */ }
})();
</script>
<script>
function submitRuleUpdate(id){
  try{
    var form = document.getElementById('update-form-'+id);
    if(!form) return;
    // collect inputs/selects from the two-row block that contains the form
    var formRow = form.closest('tr');
    var otherRow = formRow && formRow.previousElementSibling ? formRow.previousElementSibling : null;
    var nodes = Array.from(formRow.querySelectorAll('input[name],select[name],textarea[name]'));
    if (otherRow) nodes = nodes.concat(Array.from(otherRow.querySelectorAll('input[name],select[name],textarea[name]')));
    var map = {};
    nodes.forEach(function(n){
      var nm = n.getAttribute('name');
      if (!nm) return;
      // handle names like 'pattern[123]' -> base 'pattern'
      var m = nm.match(/^(.*)\[\d+\]$/);
      var key = m ? m[1] : nm;
      if (!(key in map)) map[key] = n;
    });

    console.log('submitRuleUpdate map for', id, map);

    // helper to read value for different control types
    function readVal(el){
      if (!el) return '';
      if (el.type === 'checkbox') return el.checked ? '1' : '0';
      if (el.tagName === 'SELECT') return el.value;
      return el.value;
    }

    var patternVal = readVal(map['pattern']) || '';
    var lvlVal = readVal(map['category_level']) || 0;
    var valeurVal = readVal(map['valeur_a_affecter']) || 0;
    var scopeVal = readVal(map['scope_account_id']);
    var prioVal = readVal(map['priority']) || 0;
    var isRegexChecked = readVal(map['is_regex']) || 0;
    var activeChecked = readVal(map['active']) || 0;

    // normalize scope for submission: keep 'NULL' literal, empty string for none, numeric id otherwise
    if (scopeVal === 'NULL') {
      form.elements['scope_account_id'].value = 'NULL';
    } else if (scopeVal === '') {
      form.elements['scope_account_id'].value = '';
    } else {
      var n = parseInt(scopeVal,10);
      form.elements['scope_account_id'].value = isNaN(n) ? '' : String(n);
    }

    form.elements['pattern'].value = patternVal;
    form.elements['category_level'].value = lvlVal;
    form.elements['valeur_a_affecter'].value = valeurVal;
    form.elements['priority'].value = prioVal;
    form.elements['is_regex'].value = isRegexChecked;
    form.elements['active'].value = activeChecked;
    form.submit();
  }catch(e){ console.error(e); alert('Erreur soumission'); }
}

function showRuleSql(id){
  try{
    function byName(n){ var els = document.getElementsByName(n+'['+id+']'); return (els && els.length>0)?els[0]:null; }
    var patternEl = byName('pattern');
    var pattern = patternEl ? patternEl.value.replace(/'/g, "''") : '';
    var isRegexEl = document.getElementsByName('is_regex['+id+']')[0] || document.querySelector('[name="is_regex['+id+']"]');
    var is_regex = (isRegexEl && isRegexEl.checked) ? 1 : 0;
    var lvlEl = byName('category_level');
    var category_level = lvlEl ? lvlEl.value : 0;
    var valEl = document.getElementsByName('valeur_a_affecter['+id+']')[0] || document.querySelector('select[name="valeur_a_affecter['+id+']"]');
    var valeur = valEl ? valEl.value : 0;
    var scopeEl = document.getElementsByName('scope_account_id['+id+']')[0] || document.querySelector('select[name="scope_account_id['+id+']"]');
    var scope = scopeEl ? scopeEl.value : '';
    var prioEl = byName('priority');
    var priority = prioEl ? prioEl.value : 0;
    var activeEl = document.getElementsByName('active['+id+']')[0] || document.querySelector('[name="active['+id+']"]');
    var active = (activeEl && activeEl.checked) ? 1 : 0;

    // normalize scope for SQL: empty -> NULL, 'NULL' -> NULL, numeric -> number
    var scope_sql = 'NULL';
    if (scope && scope !== 'NULL') {
      var n = parseInt(scope,10);
      scope_sql = isNaN(n) ? 'NULL' : n;
    }

    var sql = "UPDATE auto_category_rules SET pattern = '" + pattern + "', is_regex = " + is_regex + ", category_id = " + (valeur || 0) + ", scope_account_id = " + scope_sql + ", priority = " + (priority || 0) + ", active = " + active + ", valeur_a_affecter = " + (valeur || 0) + ", category_level = " + (category_level || 0) + " WHERE id = " + id + ";";

    if (confirm('SQL généré:\n\n' + sql + '\n\nExécuter cette mise à jour ?')){
      submitRuleUpdate(id);
    }
  }catch(e){ console.error(e); alert('Erreur génération SQL'); }
}
</script>
<script>
// Build JS map of categories per criterion for populating "valeur_a_affecter" selects
(function(){
  var catMap = <?php
    $out = [];
    for ($ci=1;$ci<=4;$ci++) {
      $out[$ci] = [];
      if (!empty($catTree[$ci])) {
        foreach ($catTree[$ci] as $pid => $node) {
          if (!$node['info']) continue;
          $out[$ci][] = ['id' => (int)$node['info']['id'], 'label' => $node['info']['label']];
          foreach ($node['children'] as $child) { $out[$ci][] = ['id' => (int)$child['id'], 'label' => '  ' . $child['label']]; }
        }
      }
    }
    echo json_encode($out);
  ?>;

  function populateVal(selectEl, crit, selectedVal) {
    if (!selectEl) return;
    while (selectEl.firstChild) selectEl.removeChild(selectEl.firstChild);
    var opt = document.createElement('option'); opt.value = '0'; opt.textContent = 'Choisir valeur'; selectEl.appendChild(opt);
    var list = catMap[crit] || [];
    list.forEach(function(it){ var o = document.createElement('option'); o.value = String(it.id); o.textContent = it.label; if (String(it.id) === String(selectedVal)) o.selected = true; selectEl.appendChild(o); });
  }

  // Hook create form selector
  var levelSel = document.querySelector('select.select-category-level');
  var valSel = document.querySelector('select.select-valeur');
  if (levelSel && valSel) {
    levelSel.addEventListener('change', function(){ populateVal(valSel, this.value, 0); });
  }

  // For each row, wire level->valeur population and initialize with server values
  document.querySelectorAll('select.select-category-level-row').forEach(function(lsel){
    var row = lsel.closest('tr');
    var vsel = row.querySelector('select.select-valeur-row');
    var selectedVal = row && row.dataset && row.dataset.rValeur ? row.dataset.rValeur : 0;
    lsel.addEventListener('change', function(){ populateVal(vsel, this.value, 0); });
    // initialize using either the select value or the data attribute
    var initCrit = lsel.value && lsel.value !== '0' ? lsel.value : (row && row.dataset && row.dataset.rLevel ? row.dataset.rLevel : 0);
    populateVal(vsel, initCrit, selectedVal);
  });

  // Also initialize any existing rows by triggering change to populate valeurs with selected option
  document.querySelectorAll('select.select-category-level-row').forEach(function(s){ s.dispatchEvent(new Event('change')); });
})();
</script>
