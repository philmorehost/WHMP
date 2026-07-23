<?php
require __DIR__ . '/../core/Kernel.php';
$app = new \CodeVault\Support\App(dirname(__DIR__));
$db = $app->container()->make(\CodeVault\Database::class);

echo "Currencies:\n";
print_r($db->select("SELECT * FROM currencies"));

echo "\nClients:\n";
print_r($db->select("SELECT id, email, currency_id FROM clients LIMIT 5"));

echo "\nProducts:\n";
print_r($db->select("SELECT id, name FROM products LIMIT 5"));

echo "\nProduct Pricing:\n";
print_r($db->select("SELECT * FROM product_pricing LIMIT 5"));

echo "\nInvoices:\n";
print_r($db->select("SELECT id, client_id, subtotal, total, currency_id, currency_rate FROM invoices LIMIT 5"));
