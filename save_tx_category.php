<?php
// API AJAX : mettre à jour le critère d'une transaction
header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = require __DIR__ . '/mon-site/api/db.php';
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB error']);
    exit;
}

$txId = $_POST['tx_id'] ?? null;
$field = $_POST['field'] ?? null;  // cat1_id, cat2_id, cat3_id, cat4_id
$value = $_POST['value'] ?? null;

$allowed = ['cat1_id', 'cat2_id', 'cat3_id', 'cat4_id'];

if (!$txId || !in_array($field, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Paramètres invalides']);
    exit;
}

$val = ($value === '' || $value === null) ? null : (int)$value;

// Use whitelisted field name directly (validated above)
$stmt = $pdo->prepare("UPDATE transactions SET {$field} = :val WHERE id = :id");
$stmt->execute([':val' => $val, ':id' => $txId]);

echo json_encode(['ok' => true]);
