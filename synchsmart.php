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

// AJAX handler: return accounts + received/last entries for a given import_num
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
  $importNum = isset($_GET['import_num']) && ctype_digit((string)$_GET['import_num']) ? (int)$_GET['import_num'] : (int)$pdo->query('SELECT COALESCE(MAX(NumImport), 0) FROM transactions')->fetchColumn();
  $stmt = $pdo->prepare('SELECT id, name, balance, raw, account_type, alert_threshold, numero_affichage FROM accounts ORDER BY (numero_affichage IS NULL), numero_affichage, name');
  $stmt->execute();
  $accs = $stmt->fetchAll(PDO::FETCH_ASSOC);
  foreach ($accs as &$a) {
    $a['received'] = [];
    if ($importNum > 0) {
      $rstmt = $pdo->prepare('SELECT booking_date, amount, description FROM transactions WHERE account_id = :aid AND NumImport = :imp ORDER BY booking_date DESC');
      $rstmt->execute([':aid' => $a['id'], ':imp' => $importNum]);
      $a['received'] = $rstmt->fetchAll(PDO::FETCH_ASSOC);
    }
    // Try last 3 BOOK transactions; if none, fallback to last 3 transactions regardless of status
    $tstmt = $pdo->prepare('SELECT booking_date, amount, description FROM transactions WHERE account_id = :aid AND UPPER(status) = "BOOK" ORDER BY booking_date DESC LIMIT 3');
    $tstmt->execute([':aid' => $a['id']]);
    $txs = $tstmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($txs)) {
      $tstmt2 = $pdo->prepare('SELECT booking_date, amount, description FROM transactions WHERE account_id = :aid ORDER BY booking_date DESC LIMIT 3');
      $tstmt2->execute([':aid' => $a['id']]);
      $txs = $tstmt2->fetchAll(PDO::FETCH_ASSOC);
    }
    $a['txs'] = $txs;
  }
  unset($a);
  header('Content-Type: application/json');
  echo json_encode(['accounts' => $accs, 'import_num' => $importNum]);
  exit;
}

?><!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>bkTool - Synchro</title>
  <link rel="stylesheet" href="assets/css/style.css">
  <style>body{font-family:Arial,Helvetica,sans-serif;padding:12px}</style>
</head>
<body>
  <?php include __DIR__ . '/header.php'; ?>
  <div style="margin-top:8px"><button id="restartSyncBtn" style="padding:6px 10px;border-radius:6px;border:1px solid #ccc;background:#f5f5f5;cursor:pointer">Relancer la synchro</button></div>
  <div id="syncArea">
    <div id="loader" style="margin-top:12px">🔄 <strong>Synchronisation en cours...</strong></div>
    <div id="alerts" style="margin-top:12px"></div>
  </div>
  

  <script>
  (function(){
    function escapeHtml(s){ return (s+'').replace(/[&<>\"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }

    function buildCard(a){
      var div = document.createElement('div');
      div.className = 'mobile-card';
      div.style.marginBottom = '12px';
      var th = (typeof a.alert_threshold !== 'undefined' && a.alert_threshold !== null && a.alert_threshold !== '') ? a.alert_threshold : '';
      div.dataset.threshold = th;
      // Determine balance display: support 'card' accounts with multiple OTHR balances
      var balance = parseFloat(a.balance) || 0;
      var nameEl = document.createElement('strong'); nameEl.textContent = a.name || '';
      var balancesToShow = [];
      try {
        var rawObj = null;
        if (a.raw) {
          try { rawObj = JSON.parse(a.raw); } catch(e) { rawObj = a.raw; }
        }
        var balances = [];
        if (rawObj) {
          if (Array.isArray(rawObj.balances)) balances = rawObj.balances;
          else if (rawObj.balances_raw) {
            try { var br = (typeof rawObj.balances_raw === 'string') ? JSON.parse(rawObj.balances_raw) : rawObj.balances_raw; balances = br.balances || br; } catch(e) { }
          }
        }
        if (balances && balances.length) {
          var allOthr = true;
          balances.forEach(function(b){ if (((b.balance_type||'') + '').toUpperCase() !== 'OTHR') allOthr = false; });
          if (allOthr) {
            balances.forEach(function(b){
              var amt = parseFloat((b.balance_amount && (b.balance_amount.amount || b.balance_amount.amount === 0)) ? b.balance_amount.amount : 0) || 0;
              balancesToShow.push({label: b.name || '', amount: amt});
            });
          } else {
            // find CLBD
            var cl = balances.find(function(b){ return ((b.balance_type||'') + '').toUpperCase() === 'CLBD'; });
            if (!cl) {
              cl = balances.find(function(b){ return ['CLAV','ITAV','ITBD','CLBD'].indexOf(((b.balance_type||'') + '').toUpperCase()) !== -1; });
            }
            if (!cl) cl = balances[0];
            var amt = parseFloat((cl.balance_amount && (cl.balance_amount.amount || cl.balance_amount.amount === 0)) ? cl.balance_amount.amount : 0) || 0;
            balancesToShow.push({label: 'Solde', amount: amt});
            // ensure main numeric 'balance' remains the stored value for threshold checks
            balance = parseFloat(a.balance) || amt;
          }
        } else {
          // no balances in raw -> fallback to single balance
          balancesToShow.push({label: 'Solde', amount: balance});
        }
      } catch(e) { balancesToShow.push({label: 'Solde', amount: balance}); }

      // render header row (account name + primary balance)
      var balRow = document.createElement('div'); balRow.className = 'mobile-card-row';
      var valSpan = document.createElement('span');
      var balFmt = balance.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
      valSpan.innerHTML = (balance < 0) ? '<strong style="color:#c62828">' + balFmt + '</strong>' : '<strong style="color:#2e7d32">' + balFmt + '</strong>';
      balRow.appendChild(nameEl); balRow.appendChild(valSpan);
      div.appendChild(balRow);

      // create body container for transaction lists and pending items
      var body = document.createElement('div'); body.style.padding = '8px 0';

      // render additional balance lines when applicable (e.g., card accounts)
      if (balancesToShow && balancesToShow.length > 0) {
        var list = document.createElement('div'); list.style.padding = '6px 0 0 0';
        balancesToShow.forEach(function(it, idx){
          // skip the primary line if identical to header (avoid duplicate)
          if (idx === 0 && it.label === 'Solde') return;
          var row = document.createElement('div'); row.className = 'mobile-card-row';
          var lab = document.createElement('span'); lab.className = 'mobile-card-label'; lab.textContent = it.label || 'Solde';
          var val = document.createElement('span'); val.className = 'm-value';
          var amtFmt = (parseFloat(it.amount) || 0).toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
          val.innerHTML = (it.amount < 0) ? '<strong style="color:#c62828">' + amtFmt + '</strong>' : '<strong style="color:#2e7d32">' + amtFmt + '</strong>';
          row.appendChild(lab); row.appendChild(val); list.appendChild(row);
        });
        if (list.children.length > 0) body.appendChild(list);
      }
      if (a.received && a.received.length > 0) {
        var h = document.createElement('strong'); h.textContent = 'Écritures reçues'; body.appendChild(h);
        var ul = document.createElement('ul');
        a.received.forEach(function(t){
          var li = document.createElement('li');
          var amt = parseFloat(t.amount) || 0;
          var fmt = amt.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
          var amtHtml = (amt >= 0) ? '<strong style="color:#2e7d32">' + fmt + '</strong>' : '<strong style="color:#000">' + fmt + '</strong>';
          li.innerHTML = escapeHtml(t.booking_date || '') + ' — ' + amtHtml + ' — ' + escapeHtml((t.description||'').substring(0,80));
          ul.appendChild(li);
        });
        body.appendChild(ul);
        // Per-account: show pending (skipped) transactions for this account if any (formatted like 'Écritures reçues')
        try {
          var pend = (window.pendingByAccount && window.pendingByAccount[a.id]) ? window.pendingByAccount[a.id] : [];
          if (pend && pend.length > 0) {
            var h2 = document.createElement('strong'); h2.style.marginTop = '8px'; h2.textContent = 'Écritures en attente'; body.appendChild(h2);
            var pul = document.createElement('ul');
            pend.forEach(function(t){
              var pli = document.createElement('li');
              var pamt = parseFloat((t.transaction_amount && (t.transaction_amount.amount || t.transaction_amount.amount === 0)) ? t.transaction_amount.amount : (t.amount || 0)) || 0;
              var pfmt = pamt.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
              var pamtHtml = (pamt >= 0) ? '<strong style="color:#2e7d32">' + pfmt + '</strong>' : '<strong style="color:#000">' + pfmt + '</strong>';
              var desc = '';
              if (t.remittance_information) {
                if (Array.isArray(t.remittance_information)) desc = t.remittance_information.join(' ');
                else desc = t.remittance_information;
              } else if (t.description) desc = t.description;
              else desc = JSON.stringify(t);
              pli.innerHTML = escapeHtml(t.transaction_date || t.booking_date || '') + ' — ' + pamtHtml + ' — ' + escapeHtml((desc||'').toString().substring(0,80));
              pul.appendChild(pli);
            });
            body.appendChild(pul);
          }
        } catch(e) { /* ignore */ }
      } else {
        var no = document.createElement('div'); no.style.color = '#666'; no.innerHTML = '<strong>Aucune nouvelles écritures</strong>'; body.appendChild(no);
        // Replace 'Dernières écritures' block with a button that opens transactions.php filtered by account
        var btnWrap = document.createElement('div'); btnWrap.style.marginTop = '8px';
        var btn = document.createElement('button'); btn.textContent = '>>';
        btn.title = 'Voir toutes les écritures pour ce compte';
        btn.style.padding = '6px 8px'; btn.style.borderRadius = '6px'; btn.style.border = '1px solid #ccc'; btn.style.background = '#f5f5f5'; btn.style.cursor = 'pointer';
        btn.addEventListener('click', function(){
          // navigate to transactions page showing only this account
          var accId = encodeURIComponent(a.id || a.account_id || '');
          if (accId) {
            window.location.href = 'transactions.php?account=' + accId;
          } else {
            alert('Aucun identifiant de compte disponible');
          }
        });
        btnWrap.appendChild(btn);
        body.appendChild(btnWrap);
        // Also show pending (skipped) transactions for this account when there are no 'received' entries
        try {
          var pend = (window.pendingByAccount && window.pendingByAccount[a.id]) ? window.pendingByAccount[a.id] : [];
          if (pend && pend.length > 0) {
            var h2b = document.createElement('strong'); h2b.style.marginTop = '8px'; h2b.textContent = 'Écritures en attente'; body.appendChild(h2b);
            var pulb = document.createElement('ul');
            pend.forEach(function(t){
              var pli = document.createElement('li');
              var pamt = parseFloat((t.transaction_amount && (t.transaction_amount.amount || t.transaction_amount.amount === 0)) ? t.transaction_amount.amount : (t.amount || 0)) || 0;
              var pfmt = pamt.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
              var pamtHtml = (pamt >= 0) ? '<strong style="color:#2e7d32">' + pfmt + '</strong>' : '<strong style="color:#000">' + pfmt + '</strong>';
              var desc = '';
              if (t.remittance_information) {
                if (Array.isArray(t.remittance_information)) desc = t.remittance_information.join(' ');
                else desc = t.remittance_information;
              } else if (t.description) desc = t.description;
              else desc = JSON.stringify(t);
              pli.innerHTML = escapeHtml(t.transaction_date || t.booking_date || '') + ' — ' + pamtHtml + ' — ' + escapeHtml((desc||'').toString().substring(0,80));
              pulb.appendChild(pli);
            });
            body.appendChild(pulb);
          }
        } catch(e) { /* ignore */ }
      }
      // Add a visual badge if balance is below threshold (fallback when notifications unsupported)
      var thVal = (div.dataset.threshold !== '') ? parseFloat(div.dataset.threshold) : NaN;
      if (!isNaN(thVal) && !isNaN(balance) && balance < thVal) {
        var badge = document.createElement('span');
        badge.textContent = 'ALERTE';
        badge.style.display = 'inline-block';
        badge.style.marginLeft = '8px';
        badge.style.padding = '2px 6px';
        badge.style.background = '#ffcdd2';
        badge.style.color = '#c62828';
        badge.style.border = '1px solid #ef9a9a';
        badge.style.borderRadius = '12px';
        badge.style.fontWeight = '700';
        nameEl.appendChild(badge);
      }

      div.appendChild(body);
      return div;
    }

    function notifyIfNeeded() {
      if (!('Notification' in window)) return;
      function notify(title, body){
        if (Notification.permission === 'granted') {
          new Notification(title, { body: body });
        } else if (Notification.permission !== 'denied') {
          Notification.requestPermission().then(function(p){ if (p === 'granted') new Notification(title, { body: body }); });
        }
      }
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
    }

    function renderAccounts(accounts) {
      var alerts = document.getElementById('alerts');
      if (!accounts || accounts.length === 0) { alerts.innerHTML = '<p>Aucun compte.</p>'; return; }
      accounts.forEach(function(a){ alerts.appendChild(buildCard(a)); });
    }

    // runSync: perform sync then fetch accounts for the computed import and render
    function runSync() {
      var loader = document.getElementById('loader'); var alerts = document.getElementById('alerts');
      alerts.innerHTML = '';
      loader.style.display = 'block';
      return fetch('sync.php', { method: 'GET' })
        .then(function(r){
          return r.text();
        }).then(function(text){
          loader.style.display = 'none';
          var j = null;
          // Remove any leading characters before the first JSON object to handle stray logs/notices
          var braceIdx = text.indexOf('{');
          if (braceIdx > 0) {
            text = text.slice(braceIdx);
          }
          try {
            j = JSON.parse(text);
          } catch (e) {
            alerts.innerHTML = '<div style="color:#c62828">Erreur: réponse non JSON — voir détails ci‑dessous</div><pre style="white-space:pre-wrap;margin-top:8px;max-height:300px;overflow:auto;border:1px solid #eee;padding:8px">' + escapeHtml(text) + '</pre>';
            return;
          }
          if (!j) { alerts.innerHTML = '<div style="color:#c62828">Erreur: réponse vide</div>'; return; }
          if (j.status === 'ok') {
            // Group skipped transactions by account for per-account display
            var res = j.result || {};
            window.pendingByAccount = {};
            if (res.transactions_skipped && Array.isArray(res.skipped_transactions)) {
              res.skipped_transactions.forEach(function(t){
                var aid = t._account_id || t.account_id || null;
                if (!aid) return;
                if (!window.pendingByAccount[aid]) window.pendingByAccount[aid] = [];
                window.pendingByAccount[aid].push(t);
              });
            }
            var imp = (res && typeof res.import_num !== 'undefined') ? res.import_num : null;
            if (imp === null) {
              return fetch('synchsmart.php?ajax=1').then(function(r){ return r.json(); }).then(function(data){ renderAccounts(data.accounts); notifyIfNeeded(); });
            } else {
              return fetch('synchsmart.php?ajax=1&import_num=' + encodeURIComponent(imp)).then(function(r){ return r.json(); }).then(function(data){ renderAccounts(data.accounts); notifyIfNeeded(); });
            }
          } else {
            alerts.innerHTML = '<div style="color:#c62828">Erreur: ' + (j.message || JSON.stringify(j)) + '</div>';
          }
        }).catch(function(e){ loader.style.display = 'none'; alerts.innerHTML = '<div style="color:#c62828">Erreur: '+e+'</div>'; });
    }

    // start automatically
    runSync();

    // Restart button handler (uses shared header hamburger for navigation)
    var restartBtn = document.getElementById('restartSyncBtn');
    if (restartBtn) restartBtn.addEventListener('click', function(e){ e.preventDefault(); runSync(); });

    // no transactions button here

    // no transactions button here
  })();
  </script>
</body>
</html>
