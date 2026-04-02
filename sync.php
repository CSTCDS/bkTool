<?php
// Public sync trigger (simple endpoint) — runs the same sync as the cron.
// WARNING: Exposing this publicly may be insecure. Consider protecting with a token.

header('Content-Type: application/json');

$pdo = require __DIR__ . '/mon-site/api/db.php';
$config = require __DIR__ . '/mon-site/config/database.php';
require __DIR__ . '/mon-site/api/sync.php';

// Token protection: expected token can be in config['sync_token'] or env BKTOOL_SYNC_TOKEN
$expected = $config['sync_token'] ?? getenv('BKTOOL_SYNC_TOKEN') ?: null;
// Retrieve provided token from header X-Sync-Token or GET param 'token'
$provided = null;
// Check header (Apache may prefix with HTTP_)
foreach (getallheaders() as $k => $v) {
    if (strtolower($k) === 'x-sync-token') { $provided = $v; break; }
}
if ($provided === null && isset($_GET['token'])) { $provided = $_GET['token']; }

if ($expected) {
    if (!$provided || !hash_equals((string)$expected, (string)$provided)) {
        http_response_code(403);
        echo json_encode(['status'=>'error', 'message' => 'Forbidden: invalid token']);
        exit;
    }
}

try {
    $res = run_sync($pdo, $config);
    echo json_encode(['status'=>'ok', 'result'=>$res]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status'=>'error', 'message' => (string)$e]);
}
