<?php
// Terms page that includes the shared header and displays terms.htm
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$termsFile = __DIR__ . '/terms.php';
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
// Include the content from the HTML source if present, otherwise show a message.
// Since we've moved to `terms.php` as the canonical page, show a small link back.
echo '<section><p>Consultez la <a href="terms.php">page des termes et conditions</a> pour les détails.</p></section>';
?>
</main>
</body>
</html>
