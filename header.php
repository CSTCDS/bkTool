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

// When installed as a standalone web app (Add to Home Screen), iOS/Safari
// may open links in a new window which looks like a separate screen with
// a close button. Detect standalone mode and force header links to
// navigate in the same window to keep a single app experience.
(function(){
  function isStandalone(){
    return (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || window.navigator.standalone === true;
  }
  if (!isStandalone()) return;
  var nav = document.getElementById('navTabs');
  if (!nav) return;
  nav.querySelectorAll('a').forEach(function(a){
    // ensure links open in same context
    a.target = '_self';
    a.rel = (a.rel || '') + ' noopener';
    a.addEventListener('click', function(e){
      e.preventDefault();
      var href = a.getAttribute('href');
      if (href) location.href = href;
    });
  });
})();
</script>
