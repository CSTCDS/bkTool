<?php
header('Content-Type: application/json');
try {
    $pdo = require __DIR__ . '/db.php';
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection error']);
    exit;
}

// Expect POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$account_id = isset($_POST['account_id']) ? (int)$_POST['account_id'] : 0;
$amount = isset($_POST['amount']) ? (float)str_replace(',', '.', $_POST['amount']) : null;
$currency = isset($_POST['currency']) ? trim($_POST['currency']) : '';
$description = isset($_POST['description']) ? trim($_POST['description']) : '';
$booking_date = isset($_POST['booking_date']) ? trim($_POST['booking_date']) : null;
$status = isset($_POST['status']) ? trim($_POST['status']) : 'BOOK';

if ($account_id <= 0 || $amount === null || $booking_date === null || $booking_date === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
    exit;
}

try {
    $stmt = $pdo->prepare('INSERT INTO transactions (account_id, amount, currency, description, booking_date, status, created_at) VALUES (:account_id, :amount, :currency, :description, :booking_date, :status, NOW())');
    $stmt->execute([
        ':account_id' => $account_id,
        ':amount' => $amount,
        ':currency' => $currency,
        ':description' => $description,
        ':booking_date' => $booking_date,
        ':status' => $status,
    ]);
    $id = $pdo->lastInsertId();
    echo json_encode(['ok' => true, 'id' => $id]);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => (string)$e]);
    exit;
}
