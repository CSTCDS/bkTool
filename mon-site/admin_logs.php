<?php
// Simple admin viewer for client-side logs written by client_log.php
$logFile = __DIR__ . '/logs/client_logs.log';
$lines = (int)($_GET['lines'] ?? 200);
if ($lines < 10) $lines = 10;
if ($lines > 2000) $lines = 2000;

$tail = [];
if (is_readable($logFile)) {
  $content = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  if ($content !== false) {
    $tail = array_slice($content, max(0, count($content) - $lines));
  }
}
?><!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Logs clients — Admin</title>
  <link rel="stylesheet" href="/assets/css/style.css">
  <style>body{font-family:system-ui,Arial;padding:12px} pre{white-space:pre-wrap;word-break:break-word;background:#fff;border:1px solid #ddd;padding:12px;border-radius:6px}</style>
</head>
<body>
<?php include __DIR__ . '/../header.php'; ?>

<h2>Logs clients (dernier <?php echo htmlspecialchars($lines); ?> lignes)</h2>
<form method="get" style="margin-bottom:12px">
  <label>Afficher <input type="number" name="lines" value="<?php echo htmlspecialchars($lines); ?>" min="10" max="2000" style="width:90px"> lignes</label>
  <button>Rafraîchir</button>
</form>
<?php if (empty($tail)): ?>
  <p>Aucun log disponible ou fichier introuvable: <code><?php echo htmlspecialchars($logFile); ?></code></p>
<?php else: ?>
  <pre><?php echo htmlspecialchars(implode("\n", $tail)); ?></pre>
<?php endif; ?>

</body>
</html>
