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
    $valeur_a_affecter = isset($_POST['valeur_a_affecter']) ? (int)$_POST['valeur_a_affecter'] : 0;
    $category_level = isset($_POST['category_level']) ? (int)$_POST['category_level'] : 0;
    // Target category id comes from valeur_a_affecter
    $category_id = $valeur_a_affecter;
    // scope_account_id can be non-numeric (string); preserve as string or null
    $scope_account_id = isset($_POST['scope_account_id']) ? ($_POST['scope_account_id'] === 'NULL' ? null : $_POST['scope_account_id']) : null;
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
      $valeur_a_affecter = isset($_POST['valeur_a_affecter']) ? (int)$_POST['valeur_a_affecter'] : 0;
      $category_level = isset($_POST['category_level']) ? (int)$_POST['category_level'] : 0;
      $category_id = $valeur_a_affecter;
    $scope_account_id = isset($_POST['scope_account_id']) ? ($_POST['scope_account_id'] === 'NULL' ? null : $_POST['scope_account_id']) : null;
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
        if (in_array('category_level',$desc,true)) { $cols[]='category_level'; $placeholders[]=':clevel'; $params[':clevel'] = $category_level; }
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

// Build linked categories map: for a parent category include parent+children; for a child include its parent+siblings+parent
// Build categories grouped by criterion for populate lists
$catsByCriterion = [];
for ($ci = 1; $ci <= 4; $ci++) {
  $catsByCriterion[$ci] = [];
  if (!empty($catTree[$ci])) {
    foreach ($catTree[$ci] as $pid => $node) {
      if (!$node['info']) continue;
      $catsByCriterion[$ci][] = ['id' => (int)$node['info']['id'], 'label' => $node['info']['label']];
      foreach ($node['children'] as $child) $catsByCriterion[$ci][] = ['id' => (int)$child['id'], 'label' => $child['label']];
    }
  }
}

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
        <button class="btn" type="button" onclick="showRuleSql(<?php echo $r['id']; ?>)">Modifier</button>
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
// Populate per-row "valeur_a_affecter" when the category select changes
(function(){
  var catsByCriterion = <?php echo json_encode($catsByCriterion); ?>;

  function populateVal(selectEl, items, selectedVal) {
    if (!selectEl) return;
    while (selectEl.firstChild) selectEl.removeChild(selectEl.firstChild);
    var opt0 = document.createElement('option'); opt0.value = '0'; opt0.textContent = 'Choisir valeur'; selectEl.appendChild(opt0);
    if (!items || items.length === 0) return;
    items.forEach(function(it){ var o = document.createElement('option'); o.value = String(it.id); o.textContent = it.label; if (String(it.id) === String(selectedVal)) o.selected = true; selectEl.appendChild(o); });
  }

  // create form: hook criterion -> valeur population
  var createLevel = document.querySelector('select.select-category-level');
  var createVal = document.querySelector('select.select-valeur');
  if (createLevel && createVal) {
    createLevel.addEventListener('change', function(){
      var crit = parseInt(this.value,10) || 0;
      populateVal(createVal, catsByCriterion[crit] || [], 0);
    });
  }

  // per-row: when criterion changes, populate the valeur select for that row
  document.querySelectorAll('select.select-category-level-row').forEach(function(critSel){
    var row = critSel.closest('tr');
    var vsel = row.querySelector('select.select-valeur-row');
    var initSelected = row && row.dataset && row.dataset.rValeur ? row.dataset.rValeur : 0;
    function refresh(){
      var crit = parseInt(critSel.value,10) || 0;
      populateVal(vsel, catsByCriterion[crit] || [], initSelected);
    }
    critSel.addEventListener('change', function(){ refresh(); });
    refresh();
  });
})();
</script>
<script>
function collectRuleData(id){
  // Direct lookup by exact name attribute — most reliable method
  function getEl(base){ return document.querySelector('[name="'+base+'['+id+']"]'); }
  function readVal(el){
    if(!el) return '';
    if(el.type === 'checkbox') return el.checked ? '1' : '0';
    return el.value;
  }

  var els = {
    pattern:            getEl('pattern'),
    is_regex:           getEl('is_regex'),
    category_level:     getEl('category_level'),
    valeur_a_affecter:  getEl('valeur_a_affecter'),
    scope_account_id:   getEl('scope_account_id'),
    priority:           getEl('priority'),
    active:             getEl('active')
  };

  // Debug: show which elements were found
  var debug = {};
  for(var k in els){ debug[k] = els[k] ? (els[k].tagName+' → "'+readVal(els[k])+'"') : 'NOT FOUND'; }
  console.log('collectRuleData id='+id, debug);

  var data = {
    action: 'update',
    id: String(id),
    pattern:            readVal(els.pattern),
    is_regex:           readVal(els.is_regex),
    category_level:     readVal(els.category_level) || '0',
    valeur_a_affecter:  readVal(els.valeur_a_affecter) || '0',
    scope_account_id:   readVal(els.scope_account_id),
    priority:           readVal(els.priority) || '0',
    active:             readVal(els.active)
  };

  // Show collected data as alert for verification
  alert('Données collectées (règle '+id+'):\n\n'+JSON.stringify(data, null, 2));

  return data;
}

function submitRuleUpdate(id){
  try{
    var data = collectRuleData(id);
    if(!data) return;
    // POST via fetch, then reload
    var body = new URLSearchParams(data);
    fetch('rules.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body.toString() })
      .then(function(r){ location.reload(); })
      .catch(function(e){ console.error(e); alert('Erreur réseau'); });
  }catch(e){ console.error(e); alert('Erreur soumission'); }
}

function showRuleSql(id){
  try{
    var data = collectRuleData(id);
    if(!data) return;

    var scope_sql = 'NULL';
    var raw_scope = data.scope_account_id || '';
    if (raw_scope === 'NULL' || raw_scope === '') {
      scope_sql = raw_scope === '' ? "''" : 'NULL';
    } else {
      var n = parseInt(raw_scope,10);
      if (!isNaN(n) && String(n) === String(raw_scope)) {
        scope_sql = n;
      } else {
        // non-numeric scope -> show as quoted string in preview
        scope_sql = "'" + String(raw_scope).replace(/'/g, "''") + "'";
      }
    }
    var pattern_esc = (data.pattern||'').replace(/'/g, "''");

    var catId = (parseInt(data.valeur_a_affecter,10) || 0);
    var sql = "UPDATE auto_category_rules SET"
      + " pattern = '" + pattern_esc + "'"
      + ", is_regex = " + (parseInt(data.is_regex,10) || 0)
      + ", category_id = " + catId
      + ", scope_account_id = " + scope_sql
      + ", priority = " + (parseInt(data.priority,10) || 0)
      + ", active = " + (parseInt(data.active,10) || 0)
      + ", valeur_a_affecter = " + (parseInt(data.valeur_a_affecter,10) || 0);
    sql += ", category_level = " + (parseInt(data.category_level,10) || 0);
    if (catId === 0) {
      sql += " -- NOTE: category_id is 0";
    }
    sql += " WHERE id = " + id + ";";

    if(confirm('SQL généré:\n\n' + sql + '\n\nExécuter cette mise à jour ?')){
      submitRuleUpdate(id);
    }
  }catch(e){ console.error(e); alert('Erreur génération SQL'); }
}
</script>
