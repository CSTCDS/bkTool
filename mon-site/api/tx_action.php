<?php
header('Content-Type: application/json');
try {
    $pdo = require __DIR__ . '/db.php';
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB error']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? null;
$id = $_POST['id'] ?? $_GET['id'] ?? null;
if (!$action || !$id) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing parameters']);
    exit;
}

try {
    if ($action === 'delete') {
        $stmt = $pdo->prepare('DELETE FROM transactions WHERE id = :id');
        $stmt->execute([':id' => $id]);
        echo json_encode(['ok' => true, 'action' => 'deleted']);
        exit;
    } elseif ($action === 'todel') {
        // mark transaction as to-delete (soft mark)
        $stmt = $pdo->prepare('UPDATE transactions SET status = :st WHERE id = :id');
        $stmt->execute([':st' => 'TODEL', ':id' => $id]);
        echo json_encode(['ok' => true, 'action' => 'marked_todel']);
        exit;
    } elseif ($action === 'restore') {
        $stmt = $pdo->prepare('UPDATE transactions SET status = :st WHERE id = :id');
        $stmt->execute([':st' => 'OTHR', ':id' => $id]);
        echo json_encode(['ok' => true, 'action' => 'restored']);
        exit;
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown action']);
        exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => (string)$e]);
    exit;
}
