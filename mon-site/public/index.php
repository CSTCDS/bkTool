<?php
// public/index.php — dashboard minimal (used by mon-site public or included)
require __DIR__ . '/../api/db.php';
$pdo = require __DIR__ . '/../api/db.php';

$stmt = $pdo->query('SELECT SUM(balance) as total FROM accounts');
$row = $stmt->fetch();
$total = $row['total'] ?? 0;

?><!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>bkTool - Dashboard</title>
    <link rel="manifest" href="/manifest.json">
    <link rel="stylesheet" href="/assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
<main>
    <h1>bkTool — Dashboard</h1>
    <section>
        <h2>Solde total : <?php echo htmlspecialchars($total); ?> </h2>
    </section>

    <section>
        <h3>Historique (exemple)</h3>
        <canvas id="chart"></canvas>
    </section>

    <p><a href="/transactions.php">Voir transactions</a></p>
</main>

<script>
// Exemple de données pour Chart.js
const ctx = document.getElementById('chart').getContext('2d');
const chart = new Chart(ctx, {
    type: 'line',
    data: {
        labels: ['J-6','J-5','J-4','J-3','J-2','J-1','Aujourd\'hui'],
        datasets: [{
            label: 'Solde',
            backgroundColor: 'rgba(54,162,235,0.2)',
            borderColor: 'rgba(54,162,235,1)',
            data: [1000, 1200, 1100, 1300, 1250, 1400, <?php echo (float)$total; ?>]
        }]
    }
});
</script>
</body>
</html>
<?php
// public/index.php — dashboard minimal
require __DIR__ . '/../api/db.php';
$pdo = require __DIR__ . '/../api/db.php';

$stmt = $pdo->query('SELECT SUM(balance) as total FROM accounts');
$row = $stmt->fetch();
$total = $row['total'] ?? 0;

?><!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>bkTool - Dashboard</title>
    <link rel="manifest" href="/manifest.json">
    <link rel="stylesheet" href="/assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
<main>
    <h1>bkTool — Dashboard</h1>
    <section>
        <h2>Solde total : <?php echo htmlspecialchars($total); ?> </h2>
    </section>

    <section>
        <h3>Historique (exemple)</h3>
        <canvas id="chart"></canvas>
    </section>

    <p><a href="/transactions.php">Voir transactions</a></p>
</main>

<script>
// Exemple de données pour Chart.js
const ctx = document.getElementById('chart').getContext('2d');
const chart = new Chart(ctx, {
    type: 'line',
    data: {
        labels: ['J-6','J-5','J-4','J-3','J-2','J-1','Aujourd\'hui'],
        datasets: [{
            label: 'Solde',
            backgroundColor: 'rgba(54,162,235,0.2)',
            borderColor: 'rgba(54,162,235,1)',
            data: [1000, 1200, 1100, 1300, 1250, 1400, <?php echo (float)$total; ?>]
        }]
    }
});
</script>
</body>
</html>
