<?php
// Page de gestion des comptes: affichage et modification simple
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

try {
  $pdo = require __DIR__ . '/mon-site/api/db.php';
} catch (Throwable $e) {
  echo '<h1>Erreur BDD</h1><pre>' . htmlspecialchars((string)$e) . '</pre>';
  exit;
}

$notice = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['account_id'])) {
  // update basic fields: name, currency
  $id = $_POST['account_id'] ?? null;
  $name = trim((string)($_POST['name'] ?? ''));
  $currency = trim((string)($_POST['currency'] ?? ''));
  if ($id && $name !== '') {
    $stmt = $pdo->prepare('UPDATE accounts SET name = :name, currency = :currency, updated_at = NOW() WHERE id = :id');
    $stmt->execute([':name' => $name, ':currency' => $currency, ':id' => $id]);
    $notice = 'Compte mis à jour.';
  } else {
    $notice = 'Données invalides.';
  }
}

$stmt = $pdo->query('SELECT id, name, currency, balance, updated_at FROM accounts ORDER BY name');
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Gestion des comptes</title>
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="site-header">
  <div class="site-title">bkTool</div>
  <nav class="tabs">
    <a href="index.php">Dashboard</a>
    <a href="accounts.php" class="active">Comptes</a>
    <a href="transactions.php">Transactions</a>
    <a href="categories.php">Catégories</a>
    <a href="choix.php">Connecter banque</a>
  </nav>
</div>
<main>
  <h1>Comptes</h1>
  <?php if ($notice): ?>
    <p><strong><?php echo htmlspecialchars($notice); ?></strong></p>
  <?php endif; ?>

  <?php if (empty($accounts)): ?>
    <p>Aucun compte enregistré. Synchronisez d'abord via la page principale.</p>
  <?php else: ?>
    <table>
      <thead>
        <tr><th>Libellé</th><th>Devise</th><th>Solde</th><th>Dernière MAJ</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($accounts as $acc): ?>
        <tr>
          <form method="post">
            <td>
              <input type="text" name="name" value="<?php echo htmlspecialchars($acc['name'] ?? ''); ?>" required style="width:220px">
            </td>
            <td>
              <input type="text" name="currency" value="<?php echo htmlspecialchars($acc['currency'] ?? ''); ?>" style="width:60px">
            </td>
            <td><?php echo htmlspecialchars((string)($acc['balance'] ?? '0')); ?></td>
            <td><?php echo htmlspecialchars((string)($acc['updated_at'] ?? '')); ?></td>
            <td>
              <input type="hidden" name="account_id" value="<?php echo htmlspecialchars($acc['id']); ?>">
              <button type="submit">Enregistrer</button>
            </td>
          </form>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <p><a href="index.php">Retour au dashboard</a></p>
</main>
</body>
</html>
