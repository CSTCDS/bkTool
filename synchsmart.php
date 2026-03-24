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
  $stmt = $pdo->prepare('SELECT id, name, balance, alert_threshold FROM accounts ORDER BY name');
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
  <title>Synchro mobile — bkTool</title>
  <link rel="stylesheet" href="assets/css/style.css">
  <style>body{font-family:Arial,Helvetica,sans-serif;padding:12px}</style>
</head>
<body>
  <h1>Synchro mobile</h1>
  <div id="syncArea">
    <p><button id="startSync">Lancer synchronisation</button> <span id="syncMsg" style="margin-left:12px"></span></p>
    <div id="loader" style="display:none;margin-top:12px">🔄 <strong>Synchronisation en cours...</strong></div>
    <div id="alerts" style="margin-top:12px"></div>
  </div>

  <script>
  (function(){
    function escapeHtml(s){ return (s+'').replace(/[&<>\"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }

    function buildCard(a){
      var div = document.createElement('div');
      div.className = 'mobile-card';
      div.style.marginBottom = '12px';
      div.dataset.threshold = a.alert_threshold || '';
      var balance = parseFloat(a.balance) || 0;
      var balFmt = balance.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
      var balRow = document.createElement('div'); balRow.className = 'mobile-card-row';
      var nameEl = document.createElement('strong'); nameEl.textContent = a.name || '';
      var valSpan = document.createElement('span');
      valSpan.innerHTML = (balance < 0) ? '<strong style="color:#c62828">' + balFmt + '</strong>' : '<strong style="color:#2e7d32">' + balFmt + '</strong>';
      balRow.appendChild(nameEl); balRow.appendChild(valSpan);
      div.appendChild(balRow);

      var body = document.createElement('div'); body.style.padding = '8px 0';
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
      } else {
        var no = document.createElement('div'); no.style.color = '#666'; no.innerHTML = '<strong>Aucune nouvelles écritures</strong>'; body.appendChild(no);
        var sec = document.createElement('div'); sec.style.marginTop = '8px'; var h2 = document.createElement('strong'); h2.textContent = 'Dernières écritures'; sec.appendChild(h2);
        if (!a.txs || a.txs.length === 0) {
          var none = document.createElement('div'); none.style.color = '#666'; none.textContent = 'Aucune écriture BOOK'; sec.appendChild(none);
        } else {
          var ul2 = document.createElement('ul');
          a.txs.forEach(function(t){
            var li = document.createElement('li');
            var amt = parseFloat(t.amount) || 0;
            var fmt = amt.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
            var amtHtml = (amt >= 0) ? '<strong style="color:#2e7d32">' + fmt + '</strong>' : '<strong style="color:#000">' + fmt + '</strong>';
            li.innerHTML = escapeHtml(t.booking_date || '') + ' — ' + amtHtml + ' — ' + escapeHtml((t.description||'').substring(0,80));
            ul2.appendChild(li);
          });
          sec.appendChild(ul2);
        }
        body.appendChild(sec);
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

    document.getElementById('startSync').addEventListener('click', function(){
      var msg = document.getElementById('syncMsg'); var loader = document.getElementById('loader'); var alerts = document.getElementById('alerts');
      alerts.innerHTML = ''; msg.textContent = ''; loader.style.display = 'block';
      var token = prompt('Token de synchronisation (laisser vide si non configuré)');
      var headers = {};
      if (token && token.trim() !== '') headers['X-Sync-Token'] = token.trim();
      fetch('sync.php', { method: 'GET', headers: headers })
        .then(function(r){ return r.json().catch(function(){ return { status: 'error', message: 'Invalid JSON response' }; }); })
        .then(function(j){
          loader.style.display = 'none';
          if (!j) { msg.textContent = 'Erreur: réponse vide'; return; }
          if (j.status === 'ok') {
            var imp = (j.result && typeof j.result.import_num !== 'undefined') ? j.result.import_num : null;
            if (imp === null) {
              fetch('synchsmart.php?ajax=1').then(function(r){ return r.json(); }).then(function(data){ renderAccounts(data.accounts); notifyIfNeeded(); });
            } else {
              fetch('synchsmart.php?ajax=1&import_num=' + encodeURIComponent(imp)).then(function(r){ return r.json(); }).then(function(data){ renderAccounts(data.accounts); notifyIfNeeded(); });
            }
            msg.textContent = 'OK — synchronisation terminée';
          } else {
            msg.textContent = 'Erreur: ' + (j.message || JSON.stringify(j));
          }
        }).catch(function(e){ loader.style.display = 'none'; msg.textContent = 'Erreur: '+e; });
    });
  })();
  </script>
</body>
</html>
