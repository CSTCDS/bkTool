<?php
// Shared header included by all pages. Computes active tab from current script name.
$current = basename($_SERVER['SCRIPT_NAME'] ?? '');
?>
<div class="site-header">
  <div class="site-header-top">
    <div class="site-title">bkTool</div>
    <button class="hamburger" id="hamburgerBtn" aria-label="Menu">&#9776;</button>
  </div>
  <nav class="tabs" id="navTabs">
    <a href="index.php"<?php echo ($current === 'index.php') ? ' class="active"' : ''; ?>>Dashboard</a>
    <a href="transactions.php"<?php echo ($current === 'transactions.php') ? ' class="active"' : ''; ?>>Transactions</a>
    <a href="categories.php"<?php echo ($current === 'categories.php') ? ' class="active"' : ''; ?>>Paramètres</a>
    <a href="choix.php"<?php echo ($current === 'choix.php') ? ' class="active"' : ''; ?>>Banque</a>
  </nav>
</div>
<script>
document.getElementById('hamburgerBtn').addEventListener('click', function(){
  document.getElementById('navTabs').classList.toggle('tabs-open');
});
</script>
