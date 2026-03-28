<?php
header('Content-Type: application/json; charset=utf-8');
try {
    $pdo = require __DIR__ . '/db.php';
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB']);
    exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$pattern = $_POST['pattern'] ?? '';
$category_level = isset($_POST['category_level']) ? (int)$_POST['category_level'] : 0;
$valeur = isset($_POST['valeur_a_affecter']) ? (int)$_POST['valeur_a_affecter'] : 0;
$scope = isset($_POST['scope_account_id']) && $_POST['scope_account_id'] !== '' ? $_POST['scope_account_id'] : null;
$priority = isset($_POST['priority']) ? (int)$_POST['priority'] : 100;
$active = isset($_POST['active']) && ($_POST['active'] == '1' || $_POST['active'] === 'on') ? 1 : 0;

if (!$id || $pattern === '' || $category_level <= 0 || $valeur <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'id, pattern, category_level and valeur_a_affecter required']);
    exit;
}

// Build update statement including optional columns when present
try {
    $desc = $pdo->query("DESCRIBE auto_category_rules")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) { $desc = []; }

$cols = ['pattern = :p', 'is_regex = :ir', 'scope_account_id = :scope', 'priority = :prio', 'active = :act'];
$params = [':p' => $pattern, ':ir' => 0, ':scope' => $scope, ':prio' => $priority, ':act' => $active, ':id' => $id];
if (in_array('category_level', $desc, true)) { $cols[] = 'category_level = :clevel'; $params[':clevel'] = $category_level; }
if (in_array('valeur_a_affecter', $desc, true)) { $cols[] = 'valeur_a_affecter = :val'; $params[':val'] = $valeur; }

$sql = 'UPDATE auto_category_rules SET ' . implode(', ', $cols) . ' WHERE id = :id';
$stmt = $pdo->prepare($sql);
$ok = $stmt->execute($params);
echo json_encode(['ok' => (bool)$ok, 'rule_id' => $id]);

?>
