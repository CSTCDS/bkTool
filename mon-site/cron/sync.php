<?php
// cron/sync.php — récupère comptes et transactions depuis Enable Banking et les enregistre en BDD

require __DIR__ . '/../api/db.php';
$pdo = require __DIR__ . '/../api/db.php';
$config = require __DIR__ . '/../config/database.php';
require __DIR__ . '/../api/sync.php';

// Log start of cron
try {
    $stmt = $pdo->prepare('INSERT INTO logs (log_date, log_time, code_programme, libelle, payload, created_at) VALUES (CURDATE(), CURTIME(), :code, :lib, :payload, NOW())');
    $stmt->execute([':code' => 'Cron Sync.php', ':lib' => 'start', ':payload' => null]);
} catch (Throwable $e) { /* ignore logging errors */ }

$out = run_sync($pdo, $config);

// If sync produced errors, save the full JSON output in the logs table
if (!empty($out['errors']) || !empty($out['skipped_transactions']) ) {
    try {
        $stmt = $pdo->prepare('INSERT INTO logs (log_date, log_time, code_programme, libelle, payload, created_at) VALUES (CURDATE(), CURTIME(), :code, :lib, :payload, NOW())');
        $payload = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt->execute([':code' => 'Cron Sync.php', ':lib' => 'error', ':payload' => $payload]);
    } catch (Throwable $e) { /* ignore logging errors */ }
}

echo "Sync complete: accounts=" . ($out['accounts'] ?? 0) . " transactions=" . ($out['transactions'] ?? 0) . "\n";
