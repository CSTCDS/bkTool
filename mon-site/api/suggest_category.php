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

$debug = !empty($_GET['debug']);
$acr = new AutoCategoryRule($pdo);
$rules = $acr->fetchActiveRules();
$matched = null;
foreach ($rules as $r) {
    // Respect scope
    if (!empty($r['scope_account_id']) && $accountId !== null && (string)$r['scope_account_id'] !== (string)$accountId) continue;
    if ($r['is_regex']) {
        // try-catch in DAO but double-check
        $ok = false;
        try { $ok = (@preg_match($r['pattern'], $description) === 1); } catch (Throwable $e) { $ok = false; }
        if ($ok) { $matched = $r; break; }
    } else {
        if (stripos($description, $r['pattern']) !== false) { $matched = $r; break; }
    }
}

if ($debug) {
    echo json_encode(['suggestion' => $matched ? $matched : null, 'rules_count' => count($rules), 'rules' => $rules, 'description' => $description, 'accountId' => $accountId]);
    exit;
}

if ($matched) {
    echo json_encode(['suggestion' => [
        'rule_id' => $matched['id'],
        'category_id' => $matched['category_id'],
        'pattern' => $matched['pattern'],
        'is_regex' => (bool)$matched['is_regex'],
        'priority' => (int)$matched['priority']
    ]]);
} else {
    echo json_encode(['suggestion' => null]);
}
