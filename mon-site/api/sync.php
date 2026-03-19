<?php
// mon-site/api/sync.php
// Reusable sync logic: fetch accounts and transactions from Enable Banking and store in DB

require_once __DIR__ . '/EnableBankingClient.php';

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

function run_sync($pdo, $config)
{
    $client = new EnableBankingClient($config);
    $result = ['accounts' => 0, 'transactions' => 0, 'errors' => []];

    // Fetch accounts
    $res = $client->getAccounts();
    if (isset($res['error'])) {
        $result['errors'][] = $res['error'];
        return $result;
    }
    if (!isset($res['status']) || $res['status'] === 0) {
        $result['errors'][] = 'Unknown error fetching accounts';
        return $result;
    }
    if ($res['status'] >= 200 && $res['status'] < 300 && is_array($res['body'])) {
        $accounts = $res['body']['data'] ?? $res['body'] ?? [];
        foreach ($accounts as $acc) {
            upsertAccount($pdo, $acc);
            $result['accounts']++;

            $aid = $acc['id'] ?? $acc['accountId'] ?? null;
            if ($aid) {
                $tres = $client->getAccountTransactions($aid);
                if (isset($tres['error'])) {
                    $result['errors'][] = $tres['error'];
                    continue;
                }
                if ($tres['status'] >= 200 && $tres['status'] < 300 && is_array($tres['body'])) {
                    $txs = $tres['body']['data'] ?? $tres['body'] ?? [];
                    foreach ($txs as $tx) {
                        insertTransaction($pdo, array_merge($tx, ['accountId' => $aid]));
                        $result['transactions']++;
                    }
                }
            }
        }
    }

    return $result;
}
