<?php
// Transactions — affichage avec bandeau, filtre par compte, traduction FR
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

try {
  $pdo = require __DIR__ . '/mon-site/api/db.php';
} catch (Throwable $e) {
  echo '<h1>Erreur BDD</h1><pre>' . htmlspecialchars((string)$e) . '</pre>';
  exit;
}

// Liste des comptes pour le dropdown
$accs = $pdo->query('SELECT id, name FROM accounts ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$accMap = [];
foreach ($accs as $a) { $accMap[$a['id']] = $a['name']; }

$where = [];
$params = [];
if (!empty($_GET['account'])) { $where[] = 't.account_id = :account'; $params[':account'] = $_GET['account']; }
if (!empty($_GET['from']))    { $where[] = 't.booking_date >= :from';  $params[':from'] = $_GET['from']; }
if (!empty($_GET['to']))      { $where[] = 't.booking_date <= :to';    $params[':to'] = $_GET['to']; }

$sql = 'SELECT t.*, a.name AS account_name FROM transactions t LEFT JOIN accounts a ON a.id = t.account_id';
if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
$sql .= ' ORDER BY t.booking_date DESC LIMIT 1000';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$txs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Export CSV
if (!empty($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="transactions.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Compte','Date','Montant','Devise','Description']);
    foreach ($txs as $t) {
        fputcsv($out, [$t['account_name'] ?? $t['account_id'], $t['booking_date'], $t['amount'], $t['currency'], $t['description']]);
    }
    exit;
}
?><!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Transactions — bkTool</title>
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<main>
  <div class="site-header">
    <div class="site-title">bkTool</div>
    <nav class="tabs">
      <a href="index.php">Dashboard</a>
      <a href="accounts.php">Comptes</a>
      <a href="transactions.php" class="active">Transactions</a>
      <a href="choix.php">Connecter banque</a>
    </nav>
  </div>

  <h1>Transactions</h1>

  <form method="get" style="margin-bottom:16px;display:flex;gap:12px;flex-wrap:wrap;align-items:end">
    <label>Compte :
      <select name="account">
        <option value="">— Tous —</option>
        <?php foreach ($accs as $a): ?>
          <option value="<?php echo htmlspecialchars($a['id']); ?>" <?php echo (($_GET['account'] ?? '') === $a['id']) ? 'selected' : ''; ?>>
            <?php echo htmlspecialchars($a['name'] ?: $a['id']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Du : <input type="date" name="from" value="<?php echo htmlspecialchars($_GET['from'] ?? ''); ?>"></label>
    <label>Au : <input type="date" name="to" value="<?php echo htmlspecialchars($_GET['to'] ?? ''); ?>"></label>
    <button type="submit">Filtrer</button>
    <button type="submit" name="export" value="csv">Exporter CSV</button>
  </form>

  <table>
    <thead>
      <tr><th>Compte</th><th>Date</th><th>Montant</th><th>Devise</th><th>Description</th></tr>
    </thead>
    <tbody>
    <?php foreach ($txs as $t): ?>
      <tr>
        <td><?php echo htmlspecialchars($t['account_name'] ?? $t['account_id']); ?></td>
        <td><?php echo htmlspecialchars($t['booking_date']); ?></td>
        <td style="text-align:right;<?php echo ($t['amount'] < 0) ? 'color:#c62828' : 'color:#2e7d32'; ?>"><?php echo htmlspecialchars(number_format((float)$t['amount'], 2, ',', ' ')); ?></td>
        <td><?php echo htmlspecialchars($t['currency']); ?></td>
        <td><?php echo htmlspecialchars($t['description']); ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</main>
</body>
</html>
