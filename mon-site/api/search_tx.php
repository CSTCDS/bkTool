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
if (!$acct) { echo json_encode(['ok'=>false,'error'=>'account_id required']); exit; }
$like = '%' . str_replace('%','', $q) . '%';
$stmt = $pdo->prepare('SELECT id, account_id, booking_date, amount, description FROM transactions WHERE account_id = :acct AND description LIKE :like ORDER BY booking_date DESC, id DESC LIMIT 500');
$stmt->execute([':acct'=>$acct, ':like'=>$like]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['ok'=>true,'rows'=>$rows]);

?>
