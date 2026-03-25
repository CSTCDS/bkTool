<?php
// tmp_query.php — run a quick DB query and output JSON
try {
    $pdo = require __DIR__ . '/mon-site/api/db.php';
} catch (Throwable $e) {
    echo "ERROR: ";
    echo (string)$e;
    exit(1);
}

try {
    $sql = 'SELECT id, scope_account_id FROM auto_category_rules ORDER BY id LIMIT 50';
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    echo "QUERY ERROR: ";
    echo (string)$e;
    exit(2);
}
