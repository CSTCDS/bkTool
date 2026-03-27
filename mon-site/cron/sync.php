<?php
// cron/sync.php — récupère comptes et transactions depuis Enable Banking et les enregistre en BDD

require __DIR__ . '/../api/db.php';
$pdo = require __DIR__ . '/../api/db.php';
$config = require __DIR__ . '/../config/database.php';
require __DIR__ . '/../api/sync.php';

// Purge logs older than 1 month to avoid table growth
try {
    $purge = $pdo->prepare("DELETE FROM logs WHERE log_date < DATE_SUB(CURDATE(), INTERVAL 1 MONTH)");
    $purge->execute();
} catch (Throwable $e) {
    // ignore purge errors
}

// Log start of cron
try {
    $stmt = $pdo->prepare('INSERT INTO logs (log_date, log_time, code_programme, libelle, payload, created_at) VALUES (CURDATE(), CURTIME(), :code, :lib, :payload, NOW())');
    $stmt->execute([':code' => 'Cron Sync.php', ':lib' => 'start', ':payload' => null]);
} catch (Throwable $e) { /* ignore logging errors */ }

$out = run_sync($pdo, $config);

// If sync produced errors, save the full JSON output in the logs table
if (!empty($out['errors']) || !empty($out['skipped_transactions']) ) {
    try {
        // Build a concise French libellé from the sync result
        $accounts = isset($out['accounts']) ? (int)$out['accounts'] : 0;
        $tx_read = isset($out['transactions']) ? (int)$out['transactions'] : 0;
        $tx_ins = isset($out['transactions_insert']) ? (int)$out['transactions_insert'] : 0;
        $tx_upd = isset($out['transactions_update']) ? (int)$out['transactions_update'] : 0;
        $tx_sk = isset($out['transactions_skipped']) ? (int)$out['transactions_skipped'] : 0;
        $sep = ' · ';
        $lib = 'Comptes lus: ' . $accounts . $sep . 'Opérations lues: ' . $tx_read . $sep . 'Créées: ' . $tx_ins . $sep . 'Modifiées: ' . $tx_upd . $sep . 'En attente: ' . $tx_sk;

        $stmt = $pdo->prepare('INSERT INTO logs (log_date, log_time, code_programme, libelle, payload, created_at) VALUES (CURDATE(), CURTIME(), :code, :lib, :payload, NOW())');
        $payload = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt->execute([':code' => 'Cron Sync.php', ':lib' => $lib, ':payload' => $payload]);
    } catch (Throwable $e) { /* ignore logging errors */ }
}

echo date('Y-m-d H:i:s')." Sync complete: accounts=" . ($out['accounts'] ?? 0) . " transactions=" . ($out['transactions'] ?? 0) . "\n";
