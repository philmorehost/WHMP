<?php
require __DIR__ . '/vendor/autoload.php';
$kernel = new CodeVault\Kernel(__DIR__);
$db = \CodeVault\Support\App::container()->make(\CodeVault\Database::class);

echo "--- CURRENCIES ---\n";
$currencies = $db->select("SELECT * FROM currencies");
foreach ($currencies as $c) {
    print_r($c);
}

echo "--- RECENT INVOICES ---\n";
$invoices = $db->select("SELECT id, client_id, status, subtotal, tax_amount, total, currency_id, currency_rate FROM invoices ORDER BY id DESC LIMIT 5");
foreach ($invoices as $i) {
    print_r($i);
}
