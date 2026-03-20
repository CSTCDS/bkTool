<?php
// choix_callback.php — Enable Banking callback: exchange code for session, store accounts
session_start();

$cfgPath = __DIR__ . '/mon-site/config/database.php';
$config = require $cfgPath;

$expected = $_SESSION['eb_state'] ?? null;
$received = $_GET['state'] ?? null;
$code = $_GET['code'] ?? null;
$error = $_GET['error'] ?? null;
$errorDesc = $_GET['error_description'] ?? null;

$sessionData = null;
$apiError = null;

// Validate state
$stateOk = ($expected && $received && hash_equals((string)$expected, (string)$received));

if (!$error && $code && $stateOk) {
    // Exchange code for session
    require_once __DIR__ . '/mon-site/api/EnableBankingClient.php';

    try {
        $client = new EnableBankingClient($config);
        $res = $client->createSession($code);

        if (isset($res['error'])) {
            $apiError = $res['error'];
        } elseif ($res['status'] >= 200 && $res['status'] < 300 && !empty($res['body'])) {
            $sessionData = $res['body'];
            $_SESSION['eb_session_id'] = $sessionData['session_id'] ?? null;

            // Store accounts in DB
            $pdo = require __DIR__ . '/mon-site/api/db.php';
            if (!empty($sessionData['accounts'])) {
                $stmt = $pdo->prepare('REPLACE INTO accounts (id, name, currency, raw, updated_at) VALUES (:id, :name, :currency, :raw, NOW())');
                foreach ($sessionData['accounts'] as $acc) {
                    $uid = $acc['uid'] ?? null;
                    $iban = $acc['account_id']['iban'] ?? null;
                    $accId = $uid ?? $iban ?? null;
                    if (!$accId) continue;
                    $stmt->execute([
                        ':id' => $accId,
                        ':name' => $acc['name'] ?? $iban ?? 'Compte',
                        ':currency' => $acc['currency'] ?? 'EUR',
                        ':raw' => json_encode($acc)
                    ]);
                }
            }

            // Store session_id in settings for later sync
            $pdo->prepare('REPLACE INTO settings (`key`, `value`) VALUES (:k, :v)')
                ->execute([':k' => 'eb_session_id', ':v' => $sessionData['session_id']]);

            // Clear state
            unset($_SESSION['eb_state']);
        } else {
            $apiError = 'API response ' . $res['status'] . ': ' . json_encode($res['body'] ?? $res['raw'] ?? '');
        }
    } catch (Throwable $e) {
        $apiError = $e->getMessage();
    }
}
?><!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Callback — bkTool</title>
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<main>
  <h1>Résultat de l'autorisation</h1>

  <?php if ($error): ?>
    <p style="color:red"><strong>Erreur :</strong> <?= htmlspecialchars($error) ?></p>
    <?php if ($errorDesc): ?>
      <p><?= htmlspecialchars($errorDesc) ?></p>
    <?php endif; ?>

  <?php elseif (!$stateOk): ?>
    <p style="color:orangered"><strong>Erreur :</strong> Le paramètre state ne correspond pas — possible attaque CSRF.</p>

  <?php elseif ($apiError): ?>
    <p style="color:red"><strong>Erreur API :</strong> <?= htmlspecialchars($apiError) ?></p>

  <?php elseif ($sessionData): ?>
    <p style="color:green"><strong>Autorisation réussie !</strong></p>
    <p>Session ID : <code><?= htmlspecialchars($sessionData['session_id'] ?? '—') ?></code></p>
    <p>Banque : <?= htmlspecialchars(($sessionData['aspsp']['name'] ?? '') . ' (' . ($sessionData['aspsp']['country'] ?? '') . ')') ?></p>

    <?php if (!empty($sessionData['accounts'])): ?>
      <h2>Comptes autorisés (<?= count($sessionData['accounts']) ?>)</h2>
      <table>
        <tr><th>UID</th><th>IBAN</th><th>Nom</th><th>Devise</th></tr>
        <?php foreach ($sessionData['accounts'] as $acc): ?>
        <tr>
          <td><?= htmlspecialchars($acc['uid'] ?? '—') ?></td>
          <td><?= htmlspecialchars($acc['account_id']['iban'] ?? '—') ?></td>
          <td><?= htmlspecialchars($acc['name'] ?? '—') ?></td>
          <td><?= htmlspecialchars($acc['currency'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>

    <p>Vous pouvez maintenant synchroniser vos comptes depuis le <a href="index.php">tableau de bord</a>.</p>

  <?php else: ?>
    <p>Aucune donnée reçue.</p>
  <?php endif; ?>

  <p><a href="index.php">Retour au tableau de bord</a></p>
</main>
</body>
</html>
