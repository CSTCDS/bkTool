<?php
header('Content-Type: application/json; charset=utf-8');
try {
    $pdo = require __DIR__ . '/db.php';
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'DB']);
    exit;
}

$ruleId = isset($_POST['rule_id']) ? (int)$_POST['rule_id'] : 0;
$txIdsRaw = $_POST['tx_ids'] ?? '';
if (!$ruleId || !$txIdsRaw) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'rule_id & tx_ids required']);
    exit;
}
$txIds = array_filter(array_map('trim', explode(',', $txIdsRaw)), function($v){ return $v !== ''; });
if (empty($txIds)) {
    echo json_encode(['ok'=>false,'error'=>'no tx ids']); exit;
}

$stmt = $pdo->prepare('SELECT * FROM auto_category_rules WHERE id = :id');
$stmt->execute([':id'=>$ruleId]);
$rule = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$rule) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'rule not found']); exit; }

// Determine target category id: prefer valeur_a_affecter if present, else category_level
$targetCat = null;
if (isset($rule['valeur_a_affecter']) && $rule['valeur_a_affecter']) $targetCat = (int)$rule['valeur_a_affecter'];
elseif (isset($rule['category_level']) && $rule['category_level']) $targetCat = (int)$rule['category_level'];

if (!$targetCat) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'no target category configured for rule']); exit; }

// find criterion for target category
$cstmt = $pdo->prepare('SELECT criterion FROM categories WHERE id = :id');
$cstmt->execute([':id'=>$targetCat]);
$crit = $cstmt->fetchColumn();
if ($crit === false) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'target category not found']); exit; }
$crit = (int)$crit;
$field = 'cat' . $crit . '_id';

// prepare placeholders
$placeholders = implode(',', array_fill(0, count($txIds), '?'));

// fetch old values
$q = $pdo->prepare('SELECT id, ' . $field . ' AS oldval FROM transactions WHERE id IN (' . $placeholders . ')');
$i = 1; foreach ($txIds as $v) $q->bindValue($i++, $v);
$q->execute();
$rows = $q->fetchAll(PDO::FETCH_ASSOC);

$affected = [];
try {
    $pdo->beginTransaction();
    // Use positional placeholders for update: first param = new category, following = tx ids
    $u = $pdo->prepare('UPDATE transactions SET ' . $field . ' = ? WHERE id IN (' . $placeholders . ')');
    // bind new category as first positional parameter
    $u->bindValue(1, $targetCat, PDO::PARAM_INT);
    $i = 2; foreach ($txIds as $v) { $u->bindValue($i++, $v); }
    $u->execute();

    $log = $pdo->prepare('INSERT INTO transaction_changes_log (tx_id, old_category_id, new_category_id, rule_id, user_id) VALUES (:tx, :old, :new, :rule, :user)');
    $user = $_SERVER['REMOTE_USER'] ?? null;
    foreach ($rows as $r) {
        $log->execute([':tx'=>$r['id'], ':old'=>($r['oldval'] !== null ? $r['oldval'] : null), ':new'=>$targetCat, ':rule'=>$ruleId, ':user'=>$user]);
        $affected[] = ['tx_id' => $r['id'], 'old' => $r['oldval'], 'new' => $targetCat];
    }
    $pdo->commit();
} catch (Throwable $e) {
    try { $pdo->rollBack(); } catch (Throwable $_) {}
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'db error','message'=>$e->getMessage()]);
    exit;
}

echo json_encode(['ok'=>true,'count'=>count($affected),'affected'=>$affected]);

?>
