<?php
// mon-site/api/sync.php
// Reusable sync logic: fetch balances and transactions from Enable Banking session

require_once __DIR__ . '/EnableBankingClient.php';

function upsertAccount($pdo, $acc)
{
    $stmt = $pdo->prepare('REPLACE INTO accounts (id, name, balance, currency, raw, updated_at) VALUES (:id, :name, :balance, :currency, :raw, NOW())');
    $stmt->execute([
        ':id' => $acc['id'] ?? $acc['uid'] ?? null,
        ':name' => $acc['name'] ?? $acc['accountName'] ?? null,
        ':balance' => $acc['balance'] ?? 0,
        ':currency' => $acc['currency'] ?? 'EUR',
        ':raw' => json_encode($acc)
    ]);
}

function insertTransaction($pdo, $tx)
{
    // Determine sign: Enable Banking uses credit_debit_indicator (CRDT / DBIT)
    $amount = (float)($tx['transaction_amount']['amount'] ?? 0);
    $indicator = strtoupper($tx['credit_debit_indicator'] ?? '');
    if ($indicator === 'DBIT' && $amount > 0) {
        $amount = -$amount;
    }

    // Date: prefer booking_date, fallback to value_date, then today
    $bookingDate = $tx['booking_date'] ?? $tx['value_date'] ?? null;
    // Status: pending if no booking_date in original data
    $status = !empty($tx['booking_date']) ? 'booked' : 'pending';

    $id = $tx['entry_reference'] ?? $tx['transaction_id'] ?? bin2hex(random_bytes(8));
    $accountId = $tx['_account_id'] ?? null;
    $currency = $tx['transaction_amount']['currency'] ?? 'EUR';
    $description = is_array($tx['remittance_information'] ?? null) ? implode(' ', $tx['remittance_information']) : ($tx['remittance_information'] ?? null);
    $raw = json_encode($tx);

    // Use ON DUPLICATE KEY UPDATE so pending transactions get updated on next sync
    $stmt = $pdo->prepare(
        'INSERT INTO transactions (id, account_id, amount, currency, description, booking_date, status, raw, created_at) '
      . 'VALUES (:id, :account_id, :amount, :currency, :description, :booking_date, :status, :raw, NOW()) '
      . 'ON DUPLICATE KEY UPDATE amount = VALUES(amount), booking_date = VALUES(booking_date), status = VALUES(status), description = VALUES(description), raw = VALUES(raw)'
    );
    $stmt->execute([
        ':id' => $id,
        ':account_id' => $accountId,
        ':amount' => $amount,
        ':currency' => $currency,
        ':description' => $description,
        ':booking_date' => $bookingDate,
        ':status' => $status,
        ':raw' => $raw
    ]);
}

function run_sync($pdo, $config)
{
    $result = ['accounts' => 0, 'transactions' => 0, 'errors' => []];

    // Get stored session_id
    $stmt = $pdo->prepare('SELECT `value` FROM settings WHERE `key` = :k');
    $stmt->execute([':k' => 'eb_session_id']);
    $sessionId = $stmt->fetchColumn();

    if (!$sessionId) {
        $result['errors'][] = 'Aucune session Enable Banking trouvée. Connectez d\'abord une banque via choix.php.';
        return $result;
    }

    try {
        $client = new EnableBankingClient($config);
    } catch (Throwable $e) {
        $result['errors'][] = 'Erreur client: ' . $e->getMessage();
        return $result;
    }

    // Get session info to retrieve account UIDs
    $sessionRes = $client->getSession($sessionId);
    if (isset($sessionRes['error'])) {
        $result['errors'][] = $sessionRes['error'];
        return $result;
    }
    if ($sessionRes['status'] < 200 || $sessionRes['status'] >= 300) {
        $result['errors'][] = 'Session error (' . $sessionRes['status'] . '): ' . json_encode($sessionRes['body'] ?? '');
        return $result;
    }

    $accounts = $sessionRes['body']['accounts'] ?? [];
    $accountsData = $sessionRes['body']['accounts_data'] ?? [];

    // Process each account
    foreach ($accountsData as $accData) {
        $uid = $accData['uid'] ?? null;
        if (!$uid) continue;

        // Fetch balances
        $balRes = $client->getAccountBalances($uid);
        $balance = 0;
        if ($balRes['status'] >= 200 && $balRes['status'] < 300 && !empty($balRes['body']['balances'])) {
            foreach ($balRes['body']['balances'] as $bal) {
                if (in_array($bal['balance_type'] ?? '', ['CLAV', 'ITAV', 'CLBD', 'ITBD'])) {
                    $balance = $bal['balance_amount']['amount'] ?? 0;
                    break;
                }
            }
            if ($balance == 0 && !empty($balRes['body']['balances'][0]['balance_amount']['amount'])) {
                $balance = $balRes['body']['balances'][0]['balance_amount']['amount'];
            }
        }

        upsertAccount($pdo, [
            'id' => $uid,
            'name' => $accData['identification_hash'] ?? $uid,
            'balance' => $balance,
            'currency' => 'EUR',
        ]);
        $result['accounts']++;

        // Fetch transactions
        $txRes = $client->getAccountTransactions($uid);
        if (isset($txRes['error'])) {
            $result['errors'][] = $txRes['error'];
            continue;
        }
        if ($txRes['status'] >= 200 && $txRes['status'] < 300 && !empty($txRes['body']['transactions'])) {
            foreach ($txRes['body']['transactions'] as $tx) {
                $tx['_account_id'] = $uid;
                insertTransaction($pdo, $tx);
                $result['transactions']++;
            }
        }
    }

    return $result;
}
