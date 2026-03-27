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
    $account_type = $acc['account_type'] ?? null;
    $reference_date = $acc['reference_date'] ?? null;

    // helper: check if column exists in accounts table
    $hasAccountTypeCol = null;
    try {
        $chk = $pdo->prepare("SHOW COLUMNS FROM accounts LIKE 'account_type'");
        $chk->execute();
        $hasAccountTypeCol = (bool)$chk->fetchColumn();
    } catch (Throwable $e) {
        $hasAccountTypeCol = false;
    }

    // Check existence to count insert vs update
    $exists = false;
    if ($id !== null) {
        $q = $pdo->prepare('SELECT 1 FROM accounts WHERE id = :id LIMIT 1');
        $q->execute([':id' => $id]);
        $exists = (bool)$q->fetchColumn();
    }

    if (!$exists) {
        if ($hasAccountTypeCol && $account_type !== null) {
            $stmt = $pdo->prepare(
                'INSERT INTO accounts (id, name, balance, currency, raw, account_type, reference_date, updated_at) VALUES (:id, :name, :balance, :currency, :raw, :account_type, :reference_date, NOW())'
            );
            $stmt->execute([
                ':id' => $id,
                ':name' => $name,
                ':balance' => $balance,
                ':currency' => $currency,
                ':raw' => $raw,
                ':account_type' => $account_type,
                ':reference_date' => $reference_date
            ]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO accounts (id, name, balance, currency, raw, reference_date, updated_at) VALUES (:id, :name, :balance, :currency, :raw, :reference_date, NOW())'
            );
            $stmt->execute([
                ':id' => $id,
                ':name' => $name,
                ':balance' => $balance,
                ':currency' => $currency,
                ':raw' => $raw,
                ':reference_date' => $reference_date
            ]);
        }
        return ['action' => 'insert'];
    } else {
        if ($hasAccountTypeCol && $account_type !== null) {
            $stmt = $pdo->prepare(
                'UPDATE accounts SET balance = :balance, currency = :currency, raw = :raw, account_type = :account_type, reference_date = :reference_date, updated_at = NOW() WHERE id = :id'
            );
            $stmt->execute([
                ':id' => $id,
                ':balance' => $balance,
                ':currency' => $currency,
                ':raw' => $raw,
                ':account_type' => $account_type,
                ':reference_date' => $reference_date
            ]);
        } else {
            $stmt = $pdo->prepare(
                'UPDATE accounts SET balance = :balance, currency = :currency, raw = :raw, reference_date = :reference_date, updated_at = NOW() WHERE id = :id'
            );
            $stmt->execute([
                ':id' => $id,
                ':balance' => $balance,
                ':currency' => $currency,
                ':raw' => $raw,
                ':reference_date' => $reference_date
            ]);
        }
        return ['action' => 'update'];
    }
}

function insertTransaction($pdo, $tx, $importNum, $hasNumImport = true)
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
    $accountingDate = isset($tx['accounting_date']) && $tx['accounting_date'] !== '' ? $tx['accounting_date'] : null;

    // Check existence to differentiate insert vs update; fetch full row if exists
    $q = $pdo->prepare('SELECT amount, booking_date, status, COALESCE(description,"") AS description FROM transactions WHERE id = :id LIMIT 1');
    $q->execute([':id' => $id]);
    $existing = $q->fetch(PDO::FETCH_ASSOC);
    if (!$existing) {
                $stmt = $pdo->prepare(
                        'INSERT INTO transactions (id, account_id, amount, currency, NumImport, description, booking_date, status, accounting_date, raw, created_at) '
                    . 'VALUES (:id, :account_id, :amount, :currency, :num_import, :description, :booking_date, :status, :accounting_date, :raw, NOW())'
                );
                $stmt->execute([
                        ':id' => $id,
                        ':account_id' => $accountId,
                        ':amount' => $amount,
                        ':currency' => $currency,
                        ':num_import' => $importNum,
                        ':description' => $description,
                        ':booking_date' => $bookingDate,
                        ':status' => $status,
                        ':accounting_date' => $accountingDate,
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
                // Remove older placeholder OTHR row immediately instead of two-step TODEL marking
                try {
                    $del = $pdo->prepare('DELETE FROM transactions WHERE id = :id');
                    $del->execute([':id' => $oldId]);
                } catch (Throwable $e) { /* ignore deletion errors */ }
            }
        } catch (Throwable $e) {
            // ignore, don't break the sync on this optional step
        }
        return ['action' => 'insert'];
    } else {
        // Compare fields: booking_date, amount, status, description
        $existsDesc = (string)($existing['description'] ?? '');
        $existsAmt = (float)($existing['amount'] ?? 0);
        $existsDate = (string)($existing['booking_date'] ?? '');
        $existsStatus = (string)($existing['status'] ?? '');

        $same = ($existsDate === (string)$bookingDate) && ((float)$existsAmt === (float)$amount) && ($existsStatus === (string)$status) && ($existsDesc === (string)($description ?? ''));
        if ($same) {
            return ['action' => 'noop'];
        }

            // On updates, ne pas modifier NumImport (conserver la valeur existante)
            $stmt = $pdo->prepare(
                'UPDATE transactions SET account_id = :account_id, amount = :amount, currency = :currency, description = :description, booking_date = :booking_date, status = :status, accounting_date = :accounting_date, raw = :raw WHERE id = :id'
            );
            $stmt->execute([
                ':id' => $id,
                ':account_id' => $accountId,
                ':amount' => $amount,
                ':currency' => $currency,
                ':description' => $description,
                ':booking_date' => $bookingDate,
                ':status' => $status,
                ':accounting_date' => $accountingDate,
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
        'transactions_skipped' => 0,
        'skipped_transactions' => [],
        'errors' => []
    ];

    // Get stored session_id
    $stmt = $pdo->prepare('SELECT `value` FROM settings WHERE `key` = :k');
    $stmt->execute([':k' => 'eb_session_id']);
    $sessionId = $stmt->fetchColumn();

    if (!$sessionId) {
        $result['errors'][] = 'Aucune session Enable Banking trouvée. Connectez d\'abord une banque via bank.php.';
        return $result;
    }

    // Create a new import number for this sync: max(NumImport)+1
    try {
        $r = $pdo->query('SELECT COALESCE(MAX(NumImport), 0) FROM transactions');
        $maxImp = (int)$r->fetchColumn();
        $importNum = $maxImp + 1;
    } catch (Throwable $e) {
        $importNum = 1;
    }
    // Expose le numéro d'import calculé dans le résultat pour affichage côté client
    $result['import_num'] = $importNum;

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

    // prepared map of accounting ranges for card accounts: account_id => [ ['date_du'=>'Y-m-d','date_au'=>'Y-m-d','date_ref'=>'Y-m-d'], ... ]
    $accountAccountingRanges = [];

    // Process each account
        foreach ($accountsData as $accData) {
        $uid = $accData['uid'] ?? null;
        // default reference date must be null for non-card accounts
        $accRefDate = null;
        if (!$uid) continue;

        // Fetch balances
        $balRes = $client->getAccountBalances($uid);
        $balance = 0;
        $account_type = null;
        // Determine account type and choose which balance to keep
        if ($balRes['status'] >= 200 && $balRes['status'] < 300 && !empty($balRes['body']['balances'])) {
            $balances = $balRes['body']['balances'];
            $allOthr = true;
            foreach ($balances as $b) {
                if (strtoupper($b['balance_type'] ?? '') !== 'OTHR') { $allOthr = false; break; }
            }
            if ($allOthr) {
                // Card account: display all OTHR lines; keep first for numeric balance
                $account_type = 'card';
                $first = $balances[0];
                $balance = $first['balance_amount']['amount'] ?? 0;
                // extract accounting ranges (date_du/date_au from name, and reference_date)
                $ranges = [];
                foreach ($balances as $bline) {
                    $name = $bline['name'] ?? '';
                    $date_ref = $bline['reference_date'] ?? null;
                    // attempt to parse 'du DD/MM/YYYY au DD/MM/YYYY'
                    if (preg_match('/du\s+(\d{2}[\/\-]\d{2}[\/\-]\d{4})\s+au\s+(\d{2}[\/\-]\d{2}[\/\-]\d{4})/i', $name, $m)) {
                        $d_du = DateTime::createFromFormat('d/m/Y', str_replace('-', '/', $m[1]));
                        if (!$d_du) $d_du = DateTime::createFromFormat('d/m/Y', $m[1]);
                        $d_au = DateTime::createFromFormat('d/m/Y', str_replace('-', '/', $m[2]));
                        if (!$d_au) $d_au = DateTime::createFromFormat('d/m/Y', $m[2]);
                        if ($d_du && $d_au) {
                            $ranges[] = ['date_du' => $d_du->format('Y-m-d'), 'date_au' => $d_au->format('Y-m-d'), 'date_ref' => $date_ref];
                        }
                    }
                }
                if (!empty($ranges)) {
                    $accountAccountingRanges[$uid] = $ranges;
                    // Compute reference_date: pick 'date_au' (end date) closest to today and strictly > today.
                    // If none found, fallback to the 20th of current month.
                    $today = new DateTime();
                    $candidates = [];
                    foreach ($ranges as $rg) {
                        $dau = $rg['date_au'] ?? null;
                        if (!$dau) continue;
                        try {
                            // Accept formats with '/' or '-'
                            $d = DateTime::createFromFormat('d/m/Y', str_replace('-', '/', $dau));
                            if (!$d) $d = new DateTime($dau);
                            if ($d) $candidates[] = $d;
                        } catch (Throwable $e) { /* ignore parse errors */ }
                    }
                    // filter candidates strictly greater than today
                    $future = array_filter($candidates, function($d) use ($today){ return $d > $today; });
                    if (!empty($future)) {
                        usort($future, function($a,$b){ return $a <=> $b; });
                        $chosen = $future[0];
                    } else {
                        // fallback to 20th of current month
                        $chosen = new DateTime('first day of this month');
                        $chosen->setDate((int)$chosen->format('Y'), (int)$chosen->format('m'), 20);
                    }
                    $accRefDate = $chosen->format('Y-m-d');
                }
            } else {
                // Current account: prefer CLBD if present, else fall back to first recognised type
                $account_type = 'current';
                $found = false;
                foreach ($balances as $b) {
                    $bt = strtoupper($b['balance_type'] ?? '');
                    if ($bt === 'CLBD') { $balance = $b['balance_amount']['amount'] ?? 0; $found = true; break; }
                }
                if (!$found) {
                    // fallback to any of these types
                    foreach ($balances as $b) {
                        $bt = strtoupper($b['balance_type'] ?? '');
                        if (in_array($bt, ['CLAV','ITAV','ITBD','CLBD'])) { $balance = $b['balance_amount']['amount'] ?? 0; break; }
                    }
                }
            }
        }

        $r = upsertAccount($pdo, [
            'id' => $uid,
            'name' => $accData['identification_hash'] ?? $uid,
            'balance' => $balance,
            'currency' => 'EUR',
            'account_type' => $account_type,
            // include the raw balances for reference
            'balances_raw' => $balRes['raw'] ?? json_encode($balRes['body'] ?? null),
            'reference_date' => $accRefDate ?? null,
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
                // If transaction_date is missing or null, skip storing and collect JSON for reporting
                if (!isset($tx['transaction_date']) || $tx['transaction_date'] === null || $tx['transaction_date'] === '') {
                    $result['transactions_skipped']++;
                    // store raw tx for display (keep as array/object)
                    $result['skipped_transactions'][] = $tx;
                    continue;
                }

                // Assign accounting_date based on accountAccountingRanges if present (for card accounts)
                if (isset($accountAccountingRanges[$uid]) && !empty($accountAccountingRanges[$uid])) {
                    try {
                        $txDateStr = $tx['transaction_date'];
                        $txDate = new DateTime($txDateStr);
                        foreach ($accountAccountingRanges[$uid] as $rng) {
                            if (empty($rng['date_du']) || empty($rng['date_au']) || empty($rng['date_ref'])) continue;
                            $du = new DateTime($rng['date_du']);
                            $au = new DateTime($rng['date_au']);
                            // inclusive range
                            if ($txDate >= $du && $txDate <= $au) {
                                $tx['accounting_date'] = $rng['date_ref'];
                                break;
                            }
                        }
                    } catch (Throwable $e) { /* ignore parse errors */ }
                }
                $r2 = insertTransaction($pdo, $tx, $importNum);
                $result['transactions']++;
                if (!empty($r2['action']) && $r2['action'] === 'insert') $result['transactions_insert']++;
                if (!empty($r2['action']) && $r2['action'] === 'update') $result['transactions_update']++;
            }
        }
    }

    return $result;
}
