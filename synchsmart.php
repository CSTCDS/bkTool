<?php
// synchsmart.php — run sync and show balances + last 3 BOOK entries for accounts with alert_threshold
ini_set('display_errors', 1);
error_reporting(E_ALL);
try {
  $pdo = require __DIR__ . '/mon-site/api/db.php';
  $config = require __DIR__ . '/mon-site/config/database.php';
} catch (Throwable $e) {
  echo '<h1>Erreur BDD</h1><pre>' . htmlspecialchars((string)$e) . '</pre>';
  exit;
}
require __DIR__ . '/mon-site/api/sync.php';

// Run sync (may be slow)
$syncResult = [];
try {
  $syncResult = run_sync($pdo, $config);
} catch (Throwable $e) {
  $syncResult = ['errors' => [(string)$e]];
}

// Fetch all accounts (include those with NULL alert_threshold)
$stmt = $pdo->prepare('SELECT id, name, balance, alert_threshold FROM accounts ORDER BY name');
$stmt->execute();
$accs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Determine current import number (most recent NumImport)
$currentImport = (int)$pdo->query('SELECT COALESCE(MAX(NumImport), 0) FROM transactions')->fetchColumn();

// For each account, fetch received transactions (current import) and last 3 BOOK transactions
foreach ($accs as &$a) {
  // Received in current import batch (NumImport == currentImport) — only if currentImport > 0
  $a['received'] = [];
  if ($currentImport > 0) {
    $rstmt = $pdo->prepare('SELECT booking_date, amount, description FROM transactions WHERE account_id = :aid AND NumImport = :imp ORDER BY booking_date DESC');
    $rstmt->execute([':aid' => $a['id'], ':imp' => $currentImport]);
    $a['received'] = $rstmt->fetchAll(PDO::FETCH_ASSOC);
  }

  // Last 3 BOOK transactions (fallback)
  $tstmt = $pdo->prepare('SELECT booking_date, amount, description FROM transactions WHERE account_id = :aid AND UPPER(status) = "BOOK" ORDER BY booking_date DESC LIMIT 3');
  $tstmt->execute([':aid' => $a['id']]);
  $a['txs'] = $tstmt->fetchAll(PDO::FETCH_ASSOC);
}
unset($a);

?><!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Synchro mobile — bkTool</title>
  <link rel="stylesheet" href="assets/css/style.css">
  <style>body{font-family:Arial,Helvetica,sans-serif;padding:12px}</style>
</head>
<body>
  <h1>Synchro mobile</h1>
  <?php if (!empty($syncResult['errors'])): ?>
    <div style="color:#c62828"><strong>Erreurs:</strong>
      <ul><?php foreach ($syncResult['errors'] as $err) { echo '<li>' . htmlspecialchars((string)$err) . '</li>'; } ?></ul>
    </div>
  <?php endif; ?>

  <?php if (empty($accs)): ?>
    <p>Aucun compte avec seuil d'alerte configuré.</p>
  <?php else: ?>
    <div id="alerts">
    <?php foreach ($accs as $a):
      $balance = (float)$a['balance'];
      $th = (float)$a['alert_threshold'];
      $below = ($balance < $th);
    ?>
      <div class="mobile-card" style="margin-bottom:12px" data-threshold="<?php echo htmlspecialchars($th); ?>">
        <div class="mobile-card-row"><strong><?php echo htmlspecialchars($a['name']); ?></strong><span><?php echo htmlspecialchars(number_format($balance,2,',',' ')); ?> €</span></div>
        <div style="padding:8px 0">
          <?php if (!empty($a['received'])): ?>
            <strong>Écritures reçues</strong>
            <ul>
              <?php foreach ($a['received'] as $t) {
                $amt = (float)$t['amount'];
                $fmt = htmlspecialchars(number_format($amt,2,',',' '));
                $amtHtml = ($amt < 0) ? '<strong style="color:#c62828">' . $fmt . ' €</strong>' : '<strong>' . $fmt . ' €</strong>';
                echo '<li>' . htmlspecialchars($t['booking_date']) . ' — ' . $amtHtml . ' — ' . htmlspecialchars(substr($t['description'],0,80)) . '</li>';
              } ?>
            </ul>
          <?php else: ?>
            <div style="color:#666"><strong>Aucune nouvelles écritures</strong></div>
            <div style="margin-top:8px"><strong>Dernières écritures</strong>
              <?php if (empty($a['txs'])): ?><div style="color:#666">Aucune écriture BOOK</div><?php else: ?>
                <ul><?php foreach ($a['txs'] as $t) {
                  $amt = (float)$t['amount'];
                  $fmt = htmlspecialchars(number_format($amt,2,',',' '));
                  $amtHtml = ($amt < 0) ? '<strong style="color:#c62828">' . $fmt . ' €</strong>' : '<strong>' . $fmt . ' €</strong>';
                  echo '<li>' . htmlspecialchars($t['booking_date']) . ' — ' . $amtHtml . ' — ' . htmlspecialchars(substr($t['description'],0,80)) . '</li>';} ?></ul>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <script>
    // Request notification permission and create notifications for low balances
    (function(){
      if (!('Notification' in window)) return;
      function notify(title, body){
        if (Notification.permission === 'granted') {
          new Notification(title, { body: body });
        } else if (Notification.permission !== 'denied') {
          Notification.requestPermission().then(function(p){ if (p === 'granted') new Notification(title, { body: body }); });
        }
      }
      // build alerts
      var cards = document.querySelectorAll('.mobile-card');
      cards.forEach(function(card){
        var name = card.querySelector('strong').innerText || 'Compte';
        var balSpan = card.querySelector('.mobile-card-row span:last-child');
        var b = NaN;
        if (balSpan) {
          var bText = balSpan.innerText.replace(/\./g,'').replace(',','.').replace(/[^0-9.\-]/g,'');
          b = parseFloat(bText);
        }
        var t = parseFloat(card.dataset.threshold);
        if (!isNaN(b) && !isNaN(t) && b < t) {
          notify('Alerte solde: ' + name, 'Solde ' + b.toFixed(2) + ' € < seuil ' + t.toFixed(2) + ' €');
        }
      });
    })();
  </script>
</body>
</html>
