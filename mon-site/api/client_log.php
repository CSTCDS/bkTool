<?php
// Minimal client-side log receiver for debugging DOM matching issues.
// Appends JSON lines to mon-site/logs/client_logs.log

try {
  $dir = __DIR__ . '/../logs';
  if (!is_dir($dir)) @mkdir($dir, 0755, true);
  $raw = file_get_contents('php://input');
  $ts = date('c');
  $entry = [ 'ts' => $ts, 'ip' => $_SERVER['REMOTE_ADDR'] ?? '', 'ua' => $_SERVER['HTTP_USER_AGENT'] ?? '', 'payload' => null ];
  if ($raw) {
    $decoded = json_decode($raw, true);
    $entry['payload'] = $decoded ?: $raw;
  } else {
    $entry['payload'] = $_POST ?: [];
  }
  $line = json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n";
  file_put_contents($dir . '/client_logs.log', $line, FILE_APPEND | LOCK_EX);
  // respond lightly
  header('Content-Type: application/json');
  echo json_encode(['ok' => true]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => (string)$e]);
}
