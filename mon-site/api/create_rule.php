<?php
// create_rule.php - minimal endpoint to insert a rule
header('Content-Type: application/json; charset=utf-8');
try {
    $pdo = require __DIR__ . '/db.php';
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB error']);
    exit;
}

$pattern = $_POST['pattern'] ?? '';
$is_regex = !empty($_POST['is_regex']) ? 1 : 0;
$category_id = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;
$scope_account_id = $_POST['scope_account_id'] ?? null;
$priority = isset($_POST['priority']) ? (int)$_POST['priority'] : 100;

if ($pattern === '' || $category_id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'pattern & category_id required']);
    exit;
}

$stmt = $pdo->prepare('INSERT INTO auto_category_rules (pattern, is_regex, category_id, scope_account_id, priority, active, created_by) VALUES (:p, :ir, :cid, :scope, :prio, 1, :cb)');
$ok = $stmt->execute([
    ':p' => $pattern,
    ':ir' => $is_regex,
    ':cid' => $category_id,
    ':scope' => $scope_account_id,
    ':prio' => $priority,
    ':cb' => ($_SERVER['REMOTE_USER'] ?? null)
]);

echo json_encode(['ok' => (bool)$ok, 'rule_id' => $ok ? (int)$pdo->lastInsertId() : null]);
