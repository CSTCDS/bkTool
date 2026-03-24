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
    <a href="graph.php" target="_self"<?php echo ($current === 'graph.php') ? ' class="active"' : ''; ?>>Dashboard</a>
    <a href="transactions.php" target="_self"<?php echo ($current === 'transactions.php') ? ' class="active"' : ''; ?>>Transactions</a>
    <a href="categories.php" target="_self"<?php echo ($current === 'categories.php') ? ' class="active"' : ''; ?>>Paramètres</a>
    <a href="terms.htm" target="_self"<?php echo ($current === 'terms.htm') ? ' class="active"' : ''; ?>>Termes et conditions</a>
    <a href="choix.php" target="_self" class="bank-link"<?php echo ($current === 'choix.php') ? ' class="active"' : ''; ?>>Banque</a>
    <a href="synchsmart.php" target="_self"<?php echo ($current === 'synchsmart.php') ? ' class="active"' : ''; ?>>Synchro</a>
  </nav>
</div>
<style>
  /* hide Banque on small screens (mobile) */
  @media (max-width: 600px) {
    .site-header .tabs a.bank-link { display: none !important; }
  }
</style>
<script>
document.getElementById('hamburgerBtn').addEventListener('click', function(){
  document.getElementById('navTabs').classList.toggle('tabs-open');
});

// When installed as a standalone web app (Add to Home Screen), iOS/Safari
// may open links in a new window which looks like a separate screen with
// a close button. Detect standalone mode and force ALL links to
// navigate in the same window to keep a single app experience.
(function(){
  function isStandalone(){
    return (window.matchMedia('(display-mode: standalone)').matches) || (window.navigator.standalone === true);
  }
  if (!isStandalone()) return;
  
  // Global click interceptor for ALL links in standalone PWA mode.
  // This prevents iOS/Safari from opening new windows/tabs for any clicked link.
  document.addEventListener('click', function(ev){
    var a = ev.target;
    // walk up DOM to find first <a> tag
    while (a && a.tagName !== 'A') {
      a = a.parentNode;
    }
    if (!a || a.tagName !== 'A') return;
    
    var href = a.getAttribute('href');
    if (!href) return;
    
    // prevent default link behavior to avoid opening new window
    ev.preventDefault();
    
    // force navigation in same window (not new tab/window)
    window.location.href = href;
  }, true);
})();
</script>
