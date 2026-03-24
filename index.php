<?php
// Front-controller index.php (PWA navigation refactor on main)
// If no ?page= is provided, display header + terms.txt only.

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$page = isset($_GET['page']) && is_string($_GET['page']) ? trim($_GET['page']) : '';

if ($page === '') {
  // show minimal home: header + terms
  include __DIR__ . '/header.php';
  echo "<main style=\"padding:12px\">";
  $termsFile = __DIR__ . '/terms.txt';
  if (is_readable($termsFile)) {
    $terms = file_get_contents($termsFile);
    echo '<section><h2>Conditions / Mentions</h2><pre style="white-space:pre-wrap">' . htmlspecialchars($terms) . '</pre></section>';
  } else {
    echo '<section><p>Fichier terms.txt introuvable.</p></section>';
  }
  echo "</main>";
  exit;
}

// Simple whitelist router for now — extend as needed
$whitelist = [
  'graph' => 'graph.php',
  'synchro' => 'synchsmart.php',
  'transactions' => 'transactions.php',
  'categories' => 'categories.php',
  'banque' => 'choix.php',
  'mobile' => 'mobile.php'
];

if (isset($whitelist[$page])) {
  include __DIR__ . '/' . $whitelist[$page];
  exit;
}

// unknown page
include __DIR__ . '/header.php';
http_response_code(404);
echo '<main style="padding:12px"><h2>Page introuvable</h2><p>Page "' . htmlspecialchars($page) . '" non reconnue.</p></main>';
exit;
