<?php
header('Content-Type: application/json; charset=utf-8');
try {
    $pdo = require __DIR__ . '/db.php';
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection']);
    exit;
}

$ruleId = $_POST['rule_id'] ?? null;
$txId = $_POST['tx_id'] ?? null;
if (!$ruleId || !$txId) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'rule_id & tx_id required']);
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM auto_category_rules WHERE id = :id');
$stmt->execute([':id' => $ruleId]);
$rule = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$rule) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'rule not found']);
    exit;
}

$categoryId = (int)$rule['category_id'];
// find criterion for category
$cstmt = $pdo->prepare('SELECT criterion FROM categories WHERE id = :id');
$cstmt->execute([':id' => $categoryId]);
$crit = $cstmt->fetchColumn();
if ($crit === false) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'category not found']);
    exit;
}
$crit = (int)$crit;
if ($crit < 1 || $crit > 4) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'unsupported criterion']);
    exit;
}

$field = "cat{$crit}_id";

// fetch old value
$tstmt = $pdo->prepare("SELECT {$field} FROM transactions WHERE id = :txid");
$tstmt->execute([':txid' => $txId]);
$old = $tstmt->fetchColumn();

// update transaction
$ustmt = $pdo->prepare("UPDATE transactions SET {$field} = :cid WHERE id = :txid");
$ok = $ustmt->execute([':cid' => $categoryId, ':txid' => $txId]);

// log the change
$log = $pdo->prepare('INSERT INTO transaction_changes_log (tx_id, old_category_id, new_category_id, rule_id, user_id) VALUES (:tx, :old, :new, :rule, :user)');
$log->execute([':tx' => $txId, ':old' => $old ?: null, ':new' => $categoryId, ':rule' => $ruleId, ':user' => ($_SERVER['REMOTE_USER'] ?? null)]);

echo json_encode(['ok' => (bool)$ok, 'field' => $field, 'old' => $old, 'new' => $categoryId, 'criterion' => $crit]);
