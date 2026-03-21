<?php
// Shared header included by all pages. Computes active tab from current script name.
$current = basename($_SERVER['SCRIPT_NAME'] ?? '');
?>
<div class="site-header">
  <div class="site-title">bkTool</div>
  <nav class="tabs">
    <a href="index.php"<?php echo ($current === 'index.php') ? ' class="active"' : ''; ?>>Dashboard</a>
    <a href="transactions.php"<?php echo ($current === 'transactions.php') ? ' class="active"' : ''; ?>>Transactions</a>
    <a href="categories.php"<?php echo ($current === 'categories.php') ? ' class="active"' : ''; ?>>Paramètres</a>
    <a href="choix.php"<?php echo ($current === 'choix.php') ? ' class="active"' : ''; ?>>Banque</a>
  </nav>
</div>
