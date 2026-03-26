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
$debugAttempts = [];
foreach ($rules as $r) {
    // Respect scope
    if (!empty($r['scope_account_id']) && $accountId !== null && (string)$r['scope_account_id'] !== (string)$accountId) continue;
    $pattern = $r['pattern'];
    $usedRegex = false;
    $ok = false;
    // Detect regex-like pattern even if is_regex flag is not set (e.g. stored with delimiters)
    $looksLikeRegex = is_string($pattern) && preg_match('#^/.+/[a-zA-Z]*$#', $pattern);
    if (!empty($r['is_regex']) || $looksLikeRegex) {
        $usedRegex = true;
        try {
            $ok = (@preg_match($pattern, $description) === 1);
        } catch (Throwable $e) {
            $ok = false;
        }
    } else {
        if ($pattern !== '' && stripos($description, $pattern) !== false) { $ok = true; }
    }
    if (!empty($_GET['debug'])) {
        $debugAttempts[] = ['rule_id' => $r['id'], 'pattern' => $pattern, 'is_regex_flag' => (bool)$r['is_regex'], 'used_regex' => $usedRegex, 'matched' => $ok];
    }
    if ($ok) { $matched = $r; break; }
}

if ($debug) {
    echo json_encode(['suggestion' => $matched ? $matched : null, 'rules_count' => count($rules), 'rules' => $rules, 'description' => $description, 'accountId' => $accountId, 'attempts' => $debugAttempts]);
    exit;
}

if ($matched) {
    echo json_encode(['suggestion' => [
        'rule_id' => $matched['id'],
        'category_level' => $matched['category_level'],
        'pattern' => $matched['pattern'],
        'is_regex' => (bool)$matched['is_regex'],
        'priority' => (int)$matched['priority']
    ]]);
} else {
    echo json_encode(['suggestion' => null]);
}
