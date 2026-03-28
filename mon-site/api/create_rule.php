<?php
// create_rule.php - minimal endpoint to insert a rule using category_level
header('Content-Type: application/json; charset=utf-8');
try {
    $pdo = require __DIR__ . '/db.php';
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB error']);
    exit;
}

 $pattern = $_POST['pattern'] ?? '';
 $is_regex = 0;
 $category_level = isset($_POST['category_level']) ? (int)$_POST['category_level'] : 0;
 $scope_account_id = isset($_POST['scope_account_id']) && $_POST['scope_account_id'] !== '' ? $_POST['scope_account_id'] : null;
 $priority = isset($_POST['priority']) ? (int)$_POST['priority'] : 100;
 $valeur = isset($_POST['valeur_a_affecter']) ? (int)$_POST['valeur_a_affecter'] : null;

if ($pattern === '' || $category_level <= 0 || $valeur === null || $valeur == 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'pattern & category_level required']);
    exit;
}

// Check if identical rule already exists (pattern + category_level + scope_account_id + valeur_a_affecter)
$chk = $pdo->prepare('SELECT id FROM auto_category_rules WHERE pattern = :p AND category_level = :clevel AND (scope_account_id <=> :scope) AND (valeur_a_affecter <=> :val) LIMIT 1');
$chk->execute([':p' => $pattern, ':clevel' => $category_level, ':scope' => $scope_account_id, ':val' => $valeur]);
$existing = $chk->fetchColumn();
if ($existing) {
    echo json_encode(['ok' => false, 'exists' => true, 'rule_id' => (int)$existing]);
    exit;
}

$stmt = $pdo->prepare('INSERT INTO auto_category_rules (pattern, is_regex, category_level, scope_account_id, valeur_a_affecter, priority, active, created_by) VALUES (:p, :ir, :clevel, :scope, :val, :prio, 1, :cb)');
$ok = $stmt->execute([
    ':p' => $pattern,
    ':ir' => $is_regex,
    ':clevel' => $category_level,
    ':scope' => $scope_account_id,
    ':val' => $valeur,
    ':prio' => $priority,
    ':cb' => ($_SERVER['REMOTE_USER'] ?? null)
]);

echo json_encode(['ok' => (bool)$ok, 'rule_id' => $ok ? (int)$pdo->lastInsertId() : null]);
