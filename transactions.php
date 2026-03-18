<?php
// Root wrapper for transactions: includes the public implementation
// Enable temporary error display for diagnosis
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include __DIR__ . '/mon-site/public/transactions.php';
