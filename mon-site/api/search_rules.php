<?php
header('Content-Type: application/json; charset=utf-8');
try {
    $pdo = require __DIR__ . '/db.php';
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB']);
    exit;
}

$q = $_GET['q'] ?? '';
$account = isset($_GET['account_id']) ? $_GET['account_id'] : null;
$clevel = isset($_GET['category_level']) ? (int)$_GET['category_level'] : 0;

if ($q === '' || !$clevel) {
    echo json_encode(['ok' => true, 'rules' => []]);
    exit;
}

try {
    $sql = "SELECT r.id, r.pattern, r.category_level, r.valeur_a_affecter, r.scope_account_id, r.priority, r.active, c.label AS valeur_label
            FROM auto_category_rules r
            LEFT JOIN categories c ON c.id = r.valeur_a_affecter
            WHERE r.category_level = :clevel AND r.active = 1
              AND (r.scope_account_id IS NULL OR r.scope_account_id = :acct)
              AND ((LOCATE('%', r.pattern) > 0 AND :q LIKE r.pattern) OR (LOCATE('%', r.pattern) = 0 AND :q = r.pattern))
            ORDER BY r.priority ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':clevel', $clevel, PDO::PARAM_INT);
    $stmt->bindValue(':acct', $account, PDO::PARAM_STR);
    $stmt->bindValue(':q', $q, PDO::PARAM_STR);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok' => true, 'rules' => $rows]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => (string)$e->getMessage()]);
}

?>
