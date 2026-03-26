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
status:;
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
    // resolved_account_before omitted in production
    if (strpos($account_id, 'g:') === 0) {
        $gid = (int)substr($account_id, 2);
        // find first child label under this group (labels contain account identifiers or names)
        $q = $pdo->prepare('SELECT label FROM categories WHERE parent_id = :pid AND criterion = 0 ORDER BY id LIMIT 1');
        $q->execute([':pid' => $gid]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            http_response_code(400);
            $debug['stage'] = 'resolve_group_failed';
            echo json_encode(['ok' => false, 'error' => 'Groupe de comptes invalide', 'debug' => $debug]);
            exit;
        }
        $candidate = $row['label'];
        // The candidate may be the account id or the account name; try to resolve to a real account id
        $q2 = $pdo->prepare('SELECT id FROM accounts WHERE id = :x OR name = :x LIMIT 1');
        $q2->execute([':x' => $candidate]);
        $r2 = $q2->fetch(PDO::FETCH_ASSOC);
        if (!$r2) {
            http_response_code(400);
            $debug['stage'] = 'resolve_group_no_account_match';
            $debug['candidate'] = $candidate;
            echo json_encode(['ok' => false, 'error' => 'Aucun compte trouvé pour le groupe', 'debug' => $debug]);
            exit;
        }
        $account_id = $r2['id'];
        $debug['resolved_account_from_group'] = $account_id;
    }

    if ($account_id === '') {
        http_response_code(400);
        $debug['stage'] = 'no_account';
        echo json_encode(['ok' => false, 'error' => 'Compte non spécifié', 'debug' => $debug]);
        exit;
    }

    // invert amount for storage/update
    $storeAmount = -1 * $amount;
    // storeAmount kept for computation

    // use base.NextNumber to compute id (but do not update on dry run)
    $row = $pdo->query('SELECT NextNumber FROM base WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        $next = 0;
    } else {
        $next = (int)$row['NextNumber'];
    }
    $newIdNum = $next + 1;
    $txId = (string)$newIdNum;

    // Persist: increment NextNumber and insert
    $pdo->beginTransaction();
    $upd = $pdo->prepare('UPDATE base SET NextNumber = :n WHERE id = 1');
    $upd->execute([':n' => $newIdNum]);

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
    echo json_encode(['ok' => true, 'id' => $txId]);
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    $debug['exception'] = (string)$e;
    error_log('add_tx exception: ' . (string)$e);
    echo json_encode(['ok' => false, 'error' => (string)$e, 'debug' => $debug]);
    exit;
}
