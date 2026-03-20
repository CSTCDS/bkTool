<?php
// choix_callback.php - receive redirect from Enable Banking widget
session_start();

$expected = $_SESSION['eb_state'] ?? null;
$received_state = $_GET['state'] ?? null;
$code = $_GET['code'] ?? null;
$error = $_GET['error'] ?? null;

// Simple page showing result; in production you would exchange the code server-side
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
  <h1>Résultat du widget</h1>

  <?php if ($error): ?>
    <p><strong>Erreur renvoyée par le widget:</strong> <?php echo htmlspecialchars($error); ?></p>
  <?php else: ?>
    <p><strong>Code reçu:</strong> <?php echo htmlspecialchars($code ?? '—'); ?></p>
  <?php endif; ?>

  <p><strong>State attendu:</strong> <?php echo htmlspecialchars($expected ?? '—'); ?></p>
  <p><strong>State reçu:</strong> <?php echo htmlspecialchars($received_state ?? '—'); ?></p>

  <?php if ($expected && $received_state && hash_equals((string)$expected, (string)$received_state)): ?>
    <p style="color:green">Le state correspond — vous pouvez maintenant échanger le code côté serveur.</p>
  <?php else: ?>
    <p style="color:orangered">Le state ne correspond pas — attention à une possible attaque CSRF ou redirection incorrecte.</p>
  <?php endif; ?>

  <p>Dans le backend il faut appeler l'endpoint d'échange d'Enable Banking pour obtenir des tokens en utilisant le `code`.</p>
  <p><a href="/">Retour</a></p>
</main>
</body>
</html>
