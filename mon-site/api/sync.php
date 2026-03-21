<?php
// mon-site/api/sync.php
// Reusable sync logic: fetch balances and transactions from Enable Banking session

require_once __DIR__ . '/EnableBankingClient.php';

function upsertAccount($pdo, $acc)
{
    $id = $acc['id'] ?? $acc['uid'] ?? null;
    $name = $acc['name'] ?? $acc['accountName'] ?? null;
    $balance = $acc['balance'] ?? 0;
    $currency = $acc['currency'] ?? 'EUR';
    $raw = json_encode($acc);

    // Check existence to count insert vs update
    $exists = false;
    if ($id !== null) {
        $q = $pdo->prepare('SELECT 1 FROM accounts WHERE id = :id LIMIT 1');
        $q->execute([':id' => $id]);
        $exists = (bool)$q->fetchColumn();
    }

    if (!$exists) {
        $stmt = $pdo->prepare(
            'INSERT INTO accounts (id, name, balance, currency, raw, updated_at) VALUES (:id, :name, :balance, :currency, :raw, NOW())'
        );
        $stmt->execute([
            ':id' => $id,
            ':name' => $name,
            ':balance' => $balance,
            ':currency' => $currency,
            ':raw' => $raw
        ]);
        return ['action' => 'insert'];
    } else {
        $stmt = $pdo->prepare(
            'UPDATE accounts SET balance = :balance, currency = :currency, raw = :raw, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute([
            ':id' => $id,
            ':balance' => $balance,
            ':currency' => $currency,
            ':raw' => $raw
        ]);
        return ['action' => 'update'];
    }
}

function insertTransaction($pdo, $tx)
{
    // Determine sign: Enable Banking uses credit_debit_indicator (CRDT / DBIT)
    $amount = (float)($tx['transaction_amount']['amount'] ?? 0);
    $indicator = strtoupper($tx['credit_debit_indicator'] ?? '');
    if ($indicator === 'DBIT' && $amount > 0) {
        $amount = -$amount;
    }

    // Date: use transaction_date; if empty, set to 31 December of current year
    $bookingDate = isset($tx['transaction_date']) && $tx['transaction_date'] !== null && $tx['transaction_date'] !== '' ? $tx['transaction_date'] : (date('Y') . '-12-31');
    // Status: prefer API-provided status (store it). If absent, consider BOOK when booking_date exists, else PENDING.
    $apiStatus = strtoupper(trim((string)($tx['status'] ?? '')));
    if ($apiStatus === '') {
        $apiStatus = !empty($tx['booking_date']) ? 'BOOK' : 'PENDING';
    }
    $status = $apiStatus;

    // Account id available from upstream
    $accountId = $tx['_account_id'] ?? null;
    $currency = $tx['transaction_amount']['currency'] ?? 'EUR';
    $description = is_array($tx['remittance_information'] ?? null) ? implode(' ', $tx['remittance_information']) : ($tx['remittance_information'] ?? null);
    // Normalize description for ID derivation: trim, lowercase, collapse spaces
    $descForId = '';
    if ($description !== null && $description !== '') {
        $tmp = trim((string)$description);
        $tmp = preg_replace('/\s+/', ' ', $tmp);
        $descForId = strtolower($tmp);
    }

    $id = $tx['entry_reference'] ?? $tx['transaction_id'] ?? null;
    // If the upstream id is missing, derive one from account identifier + the booking date used in table + amount + normalized description
    if ($id === null) {
        $acctPart = (string)($accountId ?? '');
        $datePart = (string)($bookingDate ?? '');
        $amtPart = number_format((float)$amount, 4, '.', '');
        $id = sha1($acctPart . '|' . $datePart . '|' . $amtPart . '|' . $descForId);
    }
    $raw = json_encode($tx);

    // Check existence to differentiate insert vs update
    $q = $pdo->prepare('SELECT 1 FROM transactions WHERE id = :id LIMIT 1');
    $q->execute([':id' => $id]);
    $exists = (bool)$q->fetchColumn();
    if (!$exists) {
        $stmt = $pdo->prepare(
            'INSERT INTO transactions (id, account_id, amount, currency, description, booking_date, status, raw, created_at) '
          . 'VALUES (:id, :account_id, :amount, :currency, :description, :booking_date, :status, :raw, NOW())'
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
        // After inserting a new transaction, check for older rows matching same account/date/description/amount with status 'OTHR'
        try {
            // also consider rows dated 31 December of the current year as potential matches
            $yearEnd = date('Y') . '-12-31';
            // Exclude the newly derived/received id itself when searching for older 'OTHR' rows
            $chk = $pdo->prepare('SELECT id FROM transactions WHERE account_id = :aid AND booking_date IN (:bdate, :yend) AND COALESCE(description,"") = :desc AND amount = :amt AND status = :st AND id <> :nid LIMIT 1');
            $chk->execute([':aid' => $accountId, ':bdate' => $bookingDate, ':yend' => $yearEnd, ':desc' => $description ?? '', ':amt' => $amount, ':st' => 'OTHR', ':nid' => $id]);
            $oldId = $chk->fetchColumn();
            if ($oldId) {
                $upd = $pdo->prepare('UPDATE transactions SET status = :newst WHERE id = :id');
                $upd->execute([':newst' => 'TODEL', ':id' => $oldId]);
            }
        } catch (Throwable $e) {
            // ignore, don't break the sync on this optional step
        }
        return ['action' => 'insert'];
    } else {
        $stmt = $pdo->prepare(
            'UPDATE transactions SET account_id = :account_id, amount = :amount, currency = :currency, description = :description, booking_date = :booking_date, status = :status, raw = :raw WHERE id = :id'
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
        return ['action' => 'update'];
    }
}

function run_sync($pdo, $config)
{
    $result = [
        'accounts' => 0,
        'accounts_insert' => 0,
        'accounts_update' => 0,
        'transactions' => 0,
        'transactions_insert' => 0,
        'transactions_update' => 0,
        'errors' => []
    ];

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

        $r = upsertAccount($pdo, [
            'id' => $uid,
            'name' => $accData['identification_hash'] ?? $uid,
            'balance' => $balance,
            'currency' => 'EUR',
        ]);
        $result['accounts']++;
        if (!empty($r['action']) && $r['action'] === 'insert') $result['accounts_insert']++;
        if (!empty($r['action']) && $r['action'] === 'update') $result['accounts_update']++;

        // Fetch transactions
        $txRes = $client->getAccountTransactions($uid);
        if (isset($txRes['error'])) {
            $result['errors'][] = $txRes['error'];
            continue;
        }
        if ($txRes['status'] >= 200 && $txRes['status'] < 300 && !empty($txRes['body']['transactions'])) {
            foreach ($txRes['body']['transactions'] as $tx) {
                $tx['_account_id'] = $uid;
                $r2 = insertTransaction($pdo, $tx);
                $result['transactions']++;
                if (!empty($r2['action']) && $r2['action'] === 'insert') $result['transactions_insert']++;
                if (!empty($r2['action']) && $r2['action'] === 'update') $result['transactions_update']++;
            }
        }
    }

    return $result;
}
