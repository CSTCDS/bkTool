<?php
header('Content-Type: application/json; charset=utf-8');
try {
    $pdo = require __DIR__ . '/db.php';
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'DB']);
    exit;
}

$acct = $_GET['account_id'] ?? null;
$q = $_GET['q'] ?? '';
$category_level = isset($_GET['category_level']) ? (int)$_GET['category_level'] : 0;
$valeur = isset($_GET['valeur']) ? (int)$_GET['valeur'] : null;
if (!$acct) { echo json_encode(['ok'=>false,'error'=>'account_id required']); exit; }
// decide whether to use LIKE or exact equality
$useLike = (strpos($q, '%') !== false);
if ($useLike) {
    $like = $q; // user provided wildcard(s)
    $stmt = $pdo->prepare('SELECT id, account_id, booking_date, amount, description, cat1_id, cat2_id, cat3_id, cat4_id FROM transactions WHERE account_id = :acct AND description LIKE :like ORDER BY booking_date DESC, id DESC LIMIT 500');
    $stmt->execute([':acct'=>$acct, ':like'=>$like]);
} else {
    $stmt = $pdo->prepare('SELECT id, account_id, booking_date, amount, description, cat1_id, cat2_id, cat3_id, cat4_id FROM transactions WHERE account_id = :acct AND description = :desc ORDER BY booking_date DESC, id DESC LIMIT 500');
    $stmt->execute([':acct'=>$acct, ':desc'=>$q]);
}
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$ruleExists = false;
if ($category_level > 0 && $valeur !== null && $q !== '') {
    $chk = $pdo->prepare('SELECT id FROM auto_category_rules WHERE pattern = :p AND category_level = :clevel AND (scope_account_id <=> :scope) AND (valeur_a_affecter <=> :val) LIMIT 1');
    $chk->execute([':p' => $q, ':clevel' => $category_level, ':scope' => $acct, ':val' => $valeur]);
    $ruleExists = (bool)$chk->fetchColumn();
}

echo json_encode(['ok'=>true,'rows'=>$rows,'rule_exists'=>$ruleExists]);

?>
