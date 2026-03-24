<?php
// Terms page that includes the shared header and displays terms.htm
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$termsFile = __DIR__ . '/terms.htm';
?><!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Terms - bkTool</title>
  <link rel="manifest" href="/bkTool/manifest.json">
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>
<main style="padding:12px">
<?php
if (is_readable($termsFile)) {
  echo '<section>' . file_get_contents($termsFile) . '</section>';
} else {
  echo '<section><p>Fichier terms.htm introuvable.</p></section>';
}
?>
</main>
</body>
</html>
