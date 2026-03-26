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

// accept account_id as string (could be 'g:NN')
$account_id = isset($_POST['account_id']) ? trim($_POST['account_id']) : '';
$amount = isset($_POST['amount']) ? (float)str_replace(',', '.', $_POST['amount']) : null;
$currency = isset($_POST['currency']) ? trim($_POST['currency']) : '';
$description = isset($_POST['description']) ? trim($_POST['description']) : '';
$booking_date = isset($_POST['booking_date']) ? trim($_POST['booking_date']) : null;
// debug container for troubleshooting
$debug = ['POST' => $_POST];
$status = 'MANUAL'; // always create manual transactions with MANUAL status
// categories
$cat1 = isset($_POST['cat1']) && $_POST['cat1'] !== '' ? (int)$_POST['cat1'] : null;
$cat2 = isset($_POST['cat2']) && $_POST['cat2'] !== '' ? (int)$_POST['cat2'] : null;
$cat3 = isset($_POST['cat3']) && $_POST['cat3'] !== '' ? (int)$_POST['cat3'] : null;
$cat4 = isset($_POST['cat4']) && $_POST['cat4'] !== '' ? (int)$_POST['cat4'] : null;

if ($amount === null || $booking_date === null || $booking_date === '') {
    http_response_code(400);
    $debug['stage'] = 'validation';
    echo json_encode(['ok' => false, 'error' => 'Missing required fields', 'debug' => $debug]);
    exit;
}
// description (libellé) mandatory
if ($description === '') {
    http_response_code(400);
    $debug['stage'] = 'validation_description';
    echo json_encode(['ok' => false, 'error' => 'Le libellé est obligatoire', 'debug' => $debug]);
    exit;
}

try {
    // Resolve account id: if group prefix g:NN, pick first child label from categories where criterion=0
    if ($account_id === '' && !empty($_COOKIE['selected_account'])) {
        $account_id = $_COOKIE['selected_account'];
    }
    $debug['resolved_account_before'] = $account_id;
    if (strpos($account_id, 'g:') === 0) {
        $gid = (int)substr($account_id, 2);
        $q = $pdo->prepare('SELECT label FROM categories WHERE criterion = 0 AND parent_id = :pid ORDER BY id LIMIT 1');
        $q->execute([':pid' => $gid]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            http_response_code(400);
            $debug['stage'] = 'resolve_group_failed';
            echo json_encode(['ok' => false, 'error' => 'Groupe de comptes invalide', 'debug' => $debug]);
            exit;
        }
        $account_id = $row['label'];
    }

    if ($account_id === '') {
        http_response_code(400);
        $debug['stage'] = 'no_account';
        echo json_encode(['ok' => false, 'error' => 'Compte non spécifié', 'debug' => $debug]);
        exit;
    }

    // invert amount for storage/update
    $storeAmount = -1 * $amount;
    $debug['storeAmount'] = $storeAmount;

    // use base.NextNumber to compute id
    $pdo->beginTransaction();
    $row = $pdo->query('SELECT NextNumber FROM base WHERE id = 1 FOR UPDATE')->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        // ensure base row exists
        $pdo->exec('INSERT IGNORE INTO base (id, version, NextNumber) VALUES (1, 0, 0)');
        $next = 0;
    } else {
        $next = (int)$row['NextNumber'];
    }
    $newIdNum = $next + 1;
    // update NextNumber
    $upd = $pdo->prepare('UPDATE base SET NextNumber = :n WHERE id = 1');
    $upd->execute([':n' => $newIdNum]);
    $debug['next_before'] = $next;
    $debug['next_after'] = $newIdNum;
    $txId = (string)$newIdNum;

    $stmt = $pdo->prepare('INSERT INTO transactions (id, account_id, amount, currency, description, booking_date, status, cat1_id, cat2_id, cat3_id, cat4_id, created_at) VALUES (:id, :account_id, :amount, :currency, :description, :booking_date, :status, :cat1, :cat2, :cat3, :cat4, NOW())');
    $stmt->execute([
        ':id' => $txId,
        ':account_id' => $account_id,
        ':amount' => $storeAmount,
        ':currency' => $currency,
        ':description' => $description,
        ':booking_date' => $booking_date,
        ':status' => $status,
        ':cat1' => $cat1,
        ':cat2' => $cat2,
        ':cat3' => $cat3,
        ':cat4' => $cat4,
    ]);
    $pdo->commit();
    $debug['txId'] = $txId;
    error_log('add_tx debug: ' . json_encode($debug));
    echo json_encode(['ok' => true, 'id' => $txId, 'debug' => $debug]);
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    $debug['exception'] = (string)$e;
    error_log('add_tx exception: ' . (string)$e);
    echo json_encode(['ok' => false, 'error' => (string)$e, 'debug' => $debug]);
    exit;
}
