<?php
// Public sync trigger (simple endpoint) — runs the same sync as the cron.
// WARNING: Exposing this publicly may be insecure. Consider protecting with a token.

header('Content-Type: application/json');

require __DIR__ . '/mon-site/api/db.php';
$pdo = require __DIR__ . '/mon-site/api/db.php';
$config = require __DIR__ . '/mon-site/config/database.php';
require __DIR__ . '/mon-site/api/sync.php';

try {
    $res = run_sync($pdo, $config);
    echo json_encode(['status'=>'ok', 'result'=>$res]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status'=>'error', 'message' => (string)$e]);
}
