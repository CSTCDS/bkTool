<?php
require __DIR__ . '/../api/db.php';
$pdo = require __DIR__ . '/../api/db.php';

// remember account selection from cookie if GET not provided
$acct = $_GET['account'] ?? ($_COOKIE['selected_account'] ?? '');

$where = [];
$params = [];
if (!empty($acct)) { $where[] = 'account_id = :account'; $params[':account'] = $acct; }
if (!empty($_GET['from'])) { $where[] = 'booking_date >= :from'; $params[':from'] = $_GET['from']; }
if (!empty($_GET['to'])) { $where[] = 'booking_date <= :to'; $params[':to'] = $_GET['to']; }

$sql = 'SELECT * FROM transactions';
if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
$sql .= ' ORDER BY booking_date DESC LIMIT 1000';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$txs = $stmt->fetchAll();

if (!empty($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="transactions.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id','account_id','amount','currency','description','booking_date']);
    foreach ($txs as $t) {
        fputcsv($out, [$t['id'],$t['account_id'],$t['amount'],$t['currency'],$t['description'],$t['booking_date']]);
    }
    exit;
}
?>
<!doctype html>
<html><head>
<meta charset="utf-8"><title>Transactions</title>
<link rel="stylesheet" href="/assets/css/style.css">
</head><body>
<h1>Transactions</h1>
<form method="get">
    <label>Account: <input name="account" value="<?php echo htmlspecialchars($acct ?? ''); ?>" onchange="document.cookie='selected_account='+encodeURIComponent(this.value)+';path=/;max-age=31536000'"></label>
    <label>From: <input type="date" name="from" value="<?php echo htmlspecialchars($_GET['from'] ?? ''); ?>"></label>
    <label>To: <input type="date" name="to" value="<?php echo htmlspecialchars($_GET['to'] ?? ''); ?>"></label>
    <button type="submit">Filter</button>
    <button type="submit" name="export" value="csv">Export CSV</button>
</form>

<table>
    <thead><tr><th>Date</th><th>Account</th><th>Amount</th><th>Currency</th><th>Description</th></tr></thead>
    <tbody>
    <?php foreach ($txs as $t): ?>
        <tr>
            <td><?php echo htmlspecialchars($t['booking_date']); ?></td>
            <td><?php echo htmlspecialchars($t['account_id']); ?></td>
            <td><?php echo htmlspecialchars($t['amount']); ?></td>
            <td><?php echo htmlspecialchars($t['currency']); ?></td>
            <td><?php echo htmlspecialchars($t['description']); ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

</body></html>
