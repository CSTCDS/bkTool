<?php
// backfill_badges.php
// Scan existing transactions and update badge and CountInVirtual when their
// expected values (based on account_type, reference_date, accounting_date)
// differ from stored values.

require_once __DIR__ . '/db.php';

try {
    $pdo = bkt_db();
} catch (Throwable $e) {
    fwrite(STDERR, "DB connection error: " . $e->getMessage() . "\n");
    exit(2);
}

$today = date('Y-m-d');

// cache accounts to avoid per-row queries
$accounts = [];
function getAccountInfo(PDO $pdo, $aid, array &$cache) {
    if (isset($cache[$aid])) return $cache[$aid];
    $stmt = $pdo->prepare('SELECT account_type, reference_date FROM accounts WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $aid]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $cache[$aid] = $r;
    return $r;
}

// CLI options: --debug (print per-row), --dry-run (don't update), --limit=N, --id=TXID
$opts = ['debug'=>false, 'dry'=>false, 'limit'=>0, 'id'=>null];
foreach ($argv ?? [] as $a) {
    if ($a === '--debug') $opts['debug'] = true;
    if ($a === '--dry-run') $opts['dry'] = true;
    if (strpos($a,'--limit=') === 0) $opts['limit'] = (int)substr($a,8);
    if (strpos($a,'--id=') === 0) $opts['id'] = substr($a,5);
}

$sql = 'SELECT id, account_id, booking_date, accounting_date, status, badge, CountInVirtual FROM transactions';
if ($opts['id']) { $sql .= ' WHERE id = ' . $pdo->quote($opts['id']); }
$sel = $pdo->query($sql);
$upd = $pdo->prepare('UPDATE transactions SET badge = :badge, CountInVirtual = :countinvirtual, raw = COALESCE(raw, raw) WHERE id = :id');

$total = 0; $changed = 0; $errors = 0; $printed = 0;
while ($tx = $sel->fetch(PDO::FETCH_ASSOC)) {
    if ($opts['limit'] > 0 && $printed >= $opts['limit']) break;
    $printed++;
    $total++;
    $id = $tx['id'];
    $accId = $tx['account_id'];
    $bookingDate = $tx['booking_date'];
    $accountingDate = $tx['accounting_date'];
    $status = strtoupper((string)$tx['status']);

    $accInfo = getAccountInfo($pdo, $accId, $accounts);
    $account_type = $accInfo['account_type'] ?? null;
    $account_ref_date = $accInfo['reference_date'] ?? null;

    $expectedBadge = null;
    $expectedCount = 0;

    if ($status === 'OTHR') {
        if ($account_type === 'card' && !empty($account_ref_date) && $bookingDate >= $account_ref_date) {
            $expectedBadge = 'nextmonth';
            $expectedCount = 0;
        } else {
            if (!empty($accountingDate)) {
                if ($today === $accountingDate) { $expectedBadge = 'today'; $expectedCount = 0; }
                elseif ($today < $accountingDate) { $expectedBadge = 'pending'; $expectedCount = 1; }
                else { $expectedBadge = 'paid'; $expectedCount = 0; }
            } else {
                $expectedBadge = 'pending';
                $expectedCount = 0;
            }
        }
    } else {
        // For non-OTHR, keep badge NULL and CountInVirtual 0 by default
        $expectedBadge = null;
        $expectedCount = 0;
    }

    $currentBadge = $tx['badge'] ?? null;
    $currentCount = (int)($tx['CountInVirtual'] ?? 0);

    // Normalize null vs empty string
    if ($currentBadge === '') $currentBadge = null;

    $need = ($currentBadge !== $expectedBadge || $currentCount !== $expectedCount);

    if ($opts['debug']) {
        fwrite(STDOUT, "TX={$id} account={$accId} booking={$bookingDate} accounting={$accountingDate} status={$status}\n");
        fwrite(STDOUT, "  current: badge=" . var_export($currentBadge, true) . ", CountInVirtual=" . var_export($currentCount, true) . "\n");
        fwrite(STDOUT, "  expected: badge=" . var_export($expectedBadge, true) . ", CountInVirtual=" . var_export($expectedCount, true) . "\n");
        fwrite(STDOUT, "  update_needed=" . ($need ? 'YES' : 'NO') . "\n");
    }

    if ($need && !$opts['dry']) {
        try {
            $upd->execute([':badge' => $expectedBadge, ':countinvirtual' => $expectedCount, ':id' => $id]);
            $changed++;
            if ($opts['debug']) fwrite(STDOUT, "  -> UPDATED\n");
        } catch (Throwable $e) {
            fwrite(STDERR, "Failed update tx {$id}: " . $e->getMessage() . "\n");
            $errors++;
        }
    }
}

fwrite(STDOUT, "Backfill complete: scanned={$total}, updated={$changed}, errors={$errors}\n");
return 0;

?>
