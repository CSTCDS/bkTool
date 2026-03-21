<?php
// mon-site/api/agg.php
// Simple aggregation endpoint for dashboard charts
try {
    $pdo = require __DIR__ . '/db.php';
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB error: ' . $e->getMessage()]);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$type = $_GET['type'] ?? '';
if ($type === 'category_month') {
    // Parameters
    $cat1 = $_GET['cat1'] ?? '';
    $cat2 = $_GET['cat2'] ?? '';
    // last N months
    $months = isset($_GET['months']) ? (int)$_GET['months'] : 12;
    $start = date('Y-m-01', strtotime(sprintf('-%d months', max(0, $months-1))));

    // Determine grouping field
    if ($cat1 === '' || $cat1 === 'all') {
        $groupField = 'cat1_id';
        $labelJoin = 'COALESCE(cat1_id,0)';
        $labelSelect = 'COALESCE(cat1_id,0) as gid';
    } elseif ($cat2 === '' || $cat2 === 'all') {
        // group by level 2 within the chosen level1
        $groupField = 'cat2_id';
        $labelJoin = 'COALESCE(cat2_id,0)';
        $labelSelect = 'COALESCE(cat2_id,0) as gid';
    } else {
        // specific cat2 selected => single series
        $groupField = null;
    }

    if ($groupField) {
        $sql = "SELECT DATE_FORMAT(booking_date,'%Y-%m') AS ym, {$labelSelect}, SUM(amount) AS amt
            FROM transactions
            WHERE booking_date >= :start";
        $params = [':start' => $start];
        if (!($cat1 === '' || $cat1 === 'all')) {
            $sql .= ' AND cat1_id = :cat1'; $params[':cat1'] = $cat1;
        }
        $sql .= " GROUP BY ym, {$labelJoin} ORDER BY ym ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // build labels (months) and series per gid
        $labels = [];
        $series = [];
        // initialize months
        $period = new DatePeriod(new DateTime($start), new DateInterval('P1M'), $months);
        foreach ($period as $dt) { $labels[] = $dt->format('Y-m'); }
        foreach ($rows as $r) {
            $gid = (string)($r['gid'] ?? '0');
            if (!isset($series[$gid])) {
                $series[$gid] = array_fill(0, count($labels), 0.0);
            }
            $idx = array_search($r['ym'], $labels);
            if ($idx !== false) $series[$gid][$idx] = (float)$r['amt'];
        }

        // Build output datasets with labels resolved via categories table when possible
        $datasets = [];
        $getLabel = $pdo->prepare('SELECT label FROM categories WHERE id = :id LIMIT 1');
        foreach ($series as $gid => $data) {
            $label = $gid === '0' ? 'Sans catégorie' : ($getLabel->execute([':id' => $gid]) ? ($getLabel->fetchColumn() ?: ('#'.$gid)) : ('#'.$gid));
            $datasets[] = ['label' => $label, 'data' => $data];
        }

        echo json_encode(['labels' => $labels, 'datasets' => $datasets]);
        exit;
    } else {
        // single series for specific cat2
        $sql = "SELECT DATE_FORMAT(booking_date,'%Y-%m') AS ym, SUM(amount) AS amt FROM transactions WHERE booking_date >= :start AND cat2_id = :cat2 GROUP BY ym ORDER BY ym ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':start' => $start, ':cat2' => $cat2]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $labels = [];
        $period = new DatePeriod(new DateTime($start), new DateInterval('P1M'), $months);
        foreach ($period as $dt) { $labels[] = $dt->format('Y-m'); }
        $data = array_fill(0, count($labels), 0.0);
        foreach ($rows as $r) {
            $idx = array_search($r['ym'], $labels);
            if ($idx !== false) $data[$idx] = (float)$r['amt'];
        }
        // label resolution
        $label = $pdo->prepare('SELECT label FROM categories WHERE id = :id LIMIT 1');
        $label->execute([':id' => $cat2]);
        $lab = $label->fetchColumn() ?: ('#'.$cat2);
        echo json_encode(['labels' => $labels, 'datasets' => [['label' => $lab, 'data' => $data]]]);
        exit;
    }
}
// Support helper endpoints to list categories for the UI
if ($type === 'list_cats') {
    $stmt = $pdo->query('SELECT id, label FROM categories WHERE parent_id IS NULL ORDER BY label');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($rows);
    exit;
}
if ($type === 'list_cats2') {
    $cat1 = $_GET['cat1'] ?? 0;
    $stmt = $pdo->prepare('SELECT id, label FROM categories WHERE parent_id = :p ORDER BY label');
    $stmt->execute([':p' => $cat1]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($rows);
    exit;
}

echo json_encode(['error' => 'unknown type']);
