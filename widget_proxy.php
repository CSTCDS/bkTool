<?php
// widget_proxy.php — server-side proxy to fetch Enable Banking widget with Authorization header
// WARNING: this proxies content from the provider and exposes it to the client. Use only over HTTPS.

// Load config/secrets
$cfg = __DIR__ . '/mon-site/config/database.php';
if (!file_exists($cfg)) {
    http_response_code(500); echo 'Config not found'; exit;
}
$config = require $cfg;
$clientId = $config['enable_client_id'] ?? '';
$clientSecret = $config['enable_client_secret'] ?? '';
$base = rtrim($config['enable_api_base'] ?? '', '/');
$widgetBase = $config['enable_widget_url'] ?? ($base . '/widget');

// Build target URL from query string
$qs = $_SERVER['QUERY_STRING'] ?? '';
$target = $widgetBase . ($qs ? ('?' . $qs) : '');

// Basic validation: require state param to mitigate casual abuse
parse_str($qs, $qp);
if (empty($qp['state'])) {
    http_response_code(400);
    echo 'Missing state parameter';
    exit;
}

$ch = curl_init($target);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);

$auth = base64_encode($clientId . ':' . $clientSecret);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Basic ' . $auth,
    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
    'User-Agent: bkTool/1.0'
]);

$resp = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$ctype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
if ($resp === false) {
    $err = curl_error($ch);
    curl_close($ch);
    http_response_code(502);
    echo 'Upstream request failed: ' . htmlspecialchars($err);
    exit;
}
curl_close($ch);

if ($ctype) header('Content-Type: ' . $ctype);
http_response_code($http ?: 200);
echo $resp;
