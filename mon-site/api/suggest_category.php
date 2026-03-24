<?php
// suggest_category.php
// Params: tx_id OR description + account_id
header('Content-Type: application/json; charset=utf-8');
try {
    $pdo = require __DIR__ . '/db.php';
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB error', 'detail' => $e->getMessage()]);
    exit;
}

require_once __DIR__ . '/AutoCategoryRule.php';

$input = $_REQUEST;
$description = null;
$accountId = null;

if (!empty($input['tx_id'])) {
    $tx = $pdo->prepare('SELECT id, description, account_id FROM transactions WHERE id = :id');
    $tx->execute([':id' => $input['tx_id']]);
    $row = $tx->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo json_encode(['suggestion' => null]);
        exit;
    }
    $description = (string)($row['description'] ?? '');
    $accountId = (string)($row['account_id'] ?? null);
} else {
    if (empty($input['description'])) {
        echo json_encode(['error' => 'Missing parameters: tx_id or description required']);
        exit;
    }
    $description = (string)$input['description'];
    $accountId = isset($input['account_id']) ? (string)$input['account_id'] : null;
}

$acr = new AutoCategoryRule($pdo);
$rule = $acr->findMatchingRule($description, $accountId);
if ($rule) {
    echo json_encode(['suggestion' => [
        'rule_id' => $rule['id'],
        'category_id' => $rule['category_id'],
        'pattern' => $rule['pattern'],
        'is_regex' => (bool)$rule['is_regex'],
        'priority' => (int)$rule['priority']
    ]]);
} else {
    echo json_encode(['suggestion' => null]);
}
