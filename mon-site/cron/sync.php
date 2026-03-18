<?php
// cron/sync.php — récupère comptes et transactions depuis Enable Banking et les enregistre en BDD

require __DIR__ . '/../api/EnableBankingClient.php';
$pdo = require __DIR__ . '/../api/db.php';
$config = require __DIR__ . '/../config/database.php';

$client = new EnableBankingClient($config);

function upsertAccount($pdo, $acc)
{
    $stmt = $pdo->prepare('REPLACE INTO accounts (id, name, balance, currency, raw, updated_at) VALUES (:id, :name, :balance, :currency, :raw, NOW())');
    $stmt->execute([
        ':id' => $acc['id'] ?? $acc['accountId'] ?? null,
        ':name' => $acc['name'] ?? $acc['accountName'] ?? null,
        ':balance' => $acc['balance'] ?? 0,
        ':currency' => $acc['currency'] ?? 'EUR',
        ':raw' => json_encode($acc)
    ]);
}

function insertTransaction($pdo, $tx)
{
    $stmt = $pdo->prepare('INSERT IGNORE INTO transactions (id, account_id, amount, currency, description, booking_date, raw, created_at) VALUES (:id, :account_id, :amount, :currency, :description, :booking_date, :raw, NOW())');
    $stmt->execute([
        ':id' => $tx['id'] ?? null,
        ':account_id' => $tx['accountId'] ?? $tx['account_id'] ?? null,
        ':amount' => $tx['amount'] ?? 0,
        ':currency' => $tx['currency'] ?? 'EUR',
        ':description' => $tx['description'] ?? $tx['remittanceInformation'] ?? null,
        ':booking_date' => $tx['bookingDate'] ?? null,
        ':raw' => json_encode($tx)
    ]);
}

// Get accounts
$res = $client->getAccounts();
if ($res['status'] >= 200 && $res['status'] < 300 && is_array($res['body'])) {
    $accounts = $res['body']['data'] ?? $res['body'] ?? [];
    foreach ($accounts as $acc) {
        upsertAccount($pdo, $acc);

        // Fetch transactions per account
        $aid = $acc['id'] ?? $acc['accountId'] ?? null;
        if ($aid) {
            $tres = $client->getAccountTransactions($aid);
            if ($tres['status'] >= 200 && $tres['status'] < 300 && is_array($tres['body'])) {
                $txs = $tres['body']['data'] ?? $tres['body'] ?? [];
                foreach ($txs as $tx) {
                    insertTransaction($pdo, array_merge($tx, ['accountId' => $aid]));
                }
            }
        }
    }
}

echo "Sync complete\n";
<?php
// cron/sync.php — récupère comptes et transactions depuis Enable Banking et les enregistre en BDD

require __DIR__ . '/../api/db.php';
$pdo = require __DIR__ . '/../api/db.php';
$config = require __DIR__ . '/../config/database.php';
require __DIR__ . '/../api/EnableBankingClient.php';

$client = new EnableBankingClient($config);

function upsertAccount($pdo, $acc)
{
    $stmt = $pdo->prepare('REPLACE INTO accounts (id, name, balance, currency, raw, updated_at) VALUES (:id, :name, :balance, :currency, :raw, NOW())');
    $stmt->execute([
        ':id' => $acc['id'] ?? $acc['accountId'] ?? null,
        ':name' => $acc['name'] ?? $acc['accountName'] ?? null,
        ':balance' => $acc['balance'] ?? 0,
        ':currency' => $acc['currency'] ?? 'EUR',
        ':raw' => json_encode($acc)
    ]);
}

function insertTransaction($pdo, $tx)
{
    $stmt = $pdo->prepare('INSERT IGNORE INTO transactions (id, account_id, amount, currency, description, booking_date, raw, created_at) VALUES (:id, :account_id, :amount, :currency, :description, :booking_date, :raw, NOW())');
    $stmt->execute([
        ':id' => $tx['id'] ?? null,
        ':account_id' => $tx['accountId'] ?? $tx['account_id'] ?? null,
        ':amount' => $tx['amount'] ?? 0,
        ':currency' => $tx['currency'] ?? 'EUR',
        ':description' => $tx['description'] ?? $tx['remittanceInformation'] ?? null,
        ':booking_date' => $tx['bookingDate'] ?? null,
        ':raw' => json_encode($tx)
    ]);
}

// Get accounts
$res = $client->getAccounts();
if ($res['status'] >= 200 && $res['status'] < 300 && is_array($res['body'])) {
    $accounts = $res['body']['data'] ?? $res['body'] ?? [];
    foreach ($accounts as $acc) {
        upsertAccount($pdo, $acc);

        // Fetch transactions per account
        $aid = $acc['id'] ?? $acc['accountId'] ?? null;
        if ($aid) {
            $tres = $client->getAccountTransactions($aid);
            if ($tres['status'] >= 200 && $tres['status'] < 300 && is_array($tres['body'])) {
                $txs = $tres['body']['data'] ?? $tres['body'] ?? [];
                foreach ($txs as $tx) {
                    insertTransaction($pdo, array_merge($tx, ['accountId' => $aid]));
                }
            }
        }
    }
}

echo "Sync complete\n";
