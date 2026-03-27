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

$sel = $pdo->query('SELECT id, account_id, booking_date, accounting_date, status, badge, CountInVirtual FROM transactions');
$upd = $pdo->prepare('UPDATE transactions SET badge = :badge, CountInVirtual = :countinvirtual, raw = COALESCE(raw, raw) WHERE id = :id');

$total = 0; $changed = 0; $errors = 0;
while ($tx = $sel->fetch(PDO::FETCH_ASSOC)) {
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

    if ($currentBadge !== $expectedBadge || $currentCount !== $expectedCount) {
        try {
            $upd->execute([':badge' => $expectedBadge, ':countinvirtual' => $expectedCount, ':id' => $id]);
            $changed++;
        } catch (Throwable $e) {
            fwrite(STDERR, "Failed update tx {$id}: " . $e->getMessage() . "\n");
            $errors++;
        }
    }
}

fwrite(STDOUT, "Backfill complete: scanned={$total}, updated={$changed}, errors={$errors}\n");
return 0;

?>
