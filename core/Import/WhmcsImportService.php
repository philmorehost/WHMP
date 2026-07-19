<?php

declare(strict_types=1);

namespace CodeVault\Import;

use CodeVault\Database;
use DateTimeImmutable;
use PDO;

final class WhmcsImportService
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /**
     * Connects to a remote WHMCS database and migrates its data.
     *
     * @param array{host: string, port: int, database: string, username: string, password: string, prefix: string} $credentials
     * @return array{success: bool, message: string, imported: array<string, int>, errors: array<int, array{row: int, reason: string}>}
     */
    public function import(array $credentials): array
    {
        $host = $credentials['host'];
        $port = $credentials['port'];
        $dbname = $credentials['database'];
        $user = $credentials['username'];
        $pass = $credentials['password'];
        $prefix = $credentials['prefix'] ?? '';

        $imported = [
            'clients' => 0,
            'servers' => 0,
            'products' => 0,
            'services' => 0,
            'domains' => 0,
            'invoices' => 0,
            'transactions' => 0,
            'currencies' => 0,
            'tax_rules' => 0,
            'contacts' => 0,
            'configurable_options' => 0,
            'departments' => 0,
            'tickets' => 0,
            'promotions' => 0,
        ];
        $errors = [];

        try {
            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
            $remotePdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 5,
            ]);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Failed to connect to the remote WHMCS database: ' . $e->getMessage(),
                'imported' => $imported,
                'errors' => [['row' => 0, 'reason' => 'Connection failed']],
            ];
        }

        // We wrap the entire migration in our local database transaction to ensure atomicity.
        return $this->db->transaction(function () use ($remotePdo, $prefix, &$imported, &$errors) {
            $clientMap = [];  // WHMCS Client ID => Local Client ID
            $serverMap = [];  // WHMCS Server ID => Local Server ID
            $productMap = []; // WHMCS Product ID => Local Product ID
            $invoiceMap = []; // WHMCS Invoice ID => Local Invoice ID
            $currencyMap = []; // WHMCS Currency ID => Local Currency ID

            $nowStr = (new DateTimeImmutable())->format('Y-m-d H:i:s');

            // 0. Currencies
            try {
                $whmcsCurrencies = $remotePdo->query("SELECT * FROM {$prefix}tblcurrencies")->fetchAll();
                foreach ($whmcsCurrencies as $row) {
                    $code = strtoupper(trim((string) $row['code']));
                    $existing = $this->db->selectOne('SELECT id FROM currencies WHERE code = ?', [$code]);
                    if ($existing !== null) {
                        $currencyMap[(int) $row['id']] = (int) $existing['id'];
                    } else {
                        $localCurrId = (int) $this->db->insert(
                            'INSERT INTO currencies (code, symbol, exchange_rate, is_default, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
                            [
                                $code,
                                $row['suffix'] ?: ($row['code'] ?? ''),
                                (float) ($row['rate'] ?? 1.0000),
                                ($row['default'] ? 1 : 0),
                                $nowStr,
                                $nowStr,
                            ]
                        );
                        $currencyMap[(int) $row['id']] = $localCurrId;
                    }
                }
            } catch (\Throwable $e) {
                // Fail silently or log, some old installations might lack tblcurrencies
            }

            // 1. Clients
            try {
                $whmcsClients = $remotePdo->query("SELECT * FROM {$prefix}tblclients")->fetchAll();
                foreach ($whmcsClients as $row) {
                    $email = strtolower(trim((string) $row['email']));
                    // Check if client email already exists locally to avoid unique constraint crashes
                    $existing = $this->db->selectOne('SELECT id FROM clients WHERE email = ?', [$email]);
                    if ($existing !== null) {
                        $clientMap[(int) $row['id']] = (int) $existing['id'];
                        continue;
                    }

                    // Map password hash directly (WHMCS uses bcrypt or phpass, compatible with PHP password_verify)
                    $passwordHash = $row['password'] ?? password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
                    $currencyId = isset($row['currency']) && isset($currencyMap[(int) $row['currency']]) ? $currencyMap[(int) $row['currency']] : null;

                    $localClientId = (int) $this->db->insert(
                        'INSERT INTO clients (email, password_hash, first_name, last_name, company_name, address1, address2, city, state, postcode, country, phone, currency_id, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [
                            $email,
                            $passwordHash,
                            $row['firstname'] ?? '',
                            $row['lastname'] ?? '',
                            $row['companyname'] ?: null,
                            $row['address1'] ?: null,
                            $row['address2'] ?: null,
                            $row['city'] ?: null,
                            $row['state'] ?: null,
                            $row['postcode'] ?: null,
                            $row['country'] ?: null,
                            $row['phonenumber'] ?: null,
                            $currencyId,
                            ($row['status'] === 'Active' ? 'active' : ($row['status'] === 'Closed' ? 'closed' : 'inactive')),
                            $row['datecreated'] ?? $nowStr,
                            $row['datecreated'] ?? $nowStr,
                        ]
                    );
                    $clientMap[(int) $row['id']] = $localClientId;
                    $imported['clients']++;
                }
            } catch (\Throwable $e) {
                $errors[] = ['row' => 0, 'reason' => 'Clients migration failed: ' . $e->getMessage()];
            }

            // 2. Servers
            try {
                $whmcsServers = $remotePdo->query("SELECT * FROM {$prefix}tblservers")->fetchAll();
                foreach ($whmcsServers as $row) {
                    $moduleSlug = 'local';
                    $whmcsType = strtolower((string) ($row['type'] ?? ''));
                    if (str_contains($whmcsType, 'cpanel')) {
                        $moduleSlug = 'cpanel';
                    } elseif (str_contains($whmcsType, 'cyberpanel')) {
                        $moduleSlug = 'cyberpanel';
                    }

                    $localServerId = (int) $this->db->insert(
                        'INSERT INTO servers (name, hostname, module_slug, api_username, api_token, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                        [
                            $row['name'] ?? '',
                            $row['ipaddress'] ?: ($row['hostname'] ?? ''),
                            $moduleSlug,
                            $row['username'] ?: null,
                            $row['accesshash'] ?: ($row['password'] ?: null),
                            ($row['active'] ? 1 : 0),
                            $nowStr,
                            $nowStr,
                        ]
                    );
                    $serverMap[(int) $row['id']] = $localServerId;
                    $imported['servers']++;
                }
            } catch (\Throwable $e) {
                $errors[] = ['row' => 0, 'reason' => 'Servers migration failed: ' . $e->getMessage()];
            }

            // 3. Product Groups and Products
            try {
                // Ensure a default product group exists locally or create one
                $groupRow = $this->db->selectOne('SELECT id FROM product_groups LIMIT 1');
                $defaultGroupId = $groupRow !== null ? (int) $groupRow['id'] : (int) $this->db->insert(
                    'INSERT INTO product_groups (name, created_at, updated_at) VALUES (?, ?, ?)',
                    ['Migrated Products', $nowStr, $nowStr]
                );

                $whmcsProducts = $remotePdo->query("SELECT * FROM {$prefix}tblproducts")->fetchAll();
                foreach ($whmcsProducts as $row) {
                    $localProductId = (int) $this->db->insert(
                        'INSERT INTO products (product_group_id, name, description, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
                        [
                            $defaultGroupId,
                            $row['name'] ?? '',
                            $row['description'] ?: null,
                            ($row['hidden'] ? 'hidden' : 'active'),
                            $nowStr,
                            $nowStr,
                        ]
                    );
                    $productMap[(int) $row['id']] = $localProductId;
                    $imported['products']++;
                }
            } catch (\Throwable $e) {
                $errors[] = ['row' => 0, 'reason' => 'Products migration failed: ' . $e->getMessage()];
            }

            // 4. Hosting/Services
            try {
                $whmcsHosting = $remotePdo->query("SELECT * FROM {$prefix}tblhosting")->fetchAll();
                foreach ($whmcsHosting as $row) {
                    $whmcsUserId = (int) $row['userid'];
                    $whmcsProductId = (int) $row['packageid'];
                    $whmcsServerId = (int) $row['server'];

                    if (!isset($clientMap[$whmcsUserId]) || !isset($productMap[$whmcsProductId])) {
                        continue;
                    }

                    $billingCycle = 'monthly';
                    $whmcsCycle = strtolower(str_replace(' ', '', (string) ($row['billingcycle'] ?? '')));
                    if ($whmcsCycle === 'onetime' || $whmcsCycle === 'one-time') {
                        $billingCycle = 'one_time';
                    } elseif ($whmcsCycle === 'quarterly') {
                        $billingCycle = 'quarterly';
                    } elseif ($whmcsCycle === 'semiannually') {
                        $billingCycle = 'semi_annually';
                    } elseif ($whmcsCycle === 'annually') {
                        $billingCycle = 'annually';
                    } elseif ($whmcsCycle === 'biennially') {
                        $billingCycle = 'biennially';
                    } elseif ($whmcsCycle === 'triennially') {
                        $billingCycle = 'triennially';
                    }

                    $status = 'pending';
                    $whmcsStatus = strtolower((string) ($row['domainstatus'] ?? ''));
                    if ($whmcsStatus === 'active') {
                        $status = 'active';
                    } elseif ($whmcsStatus === 'suspended') {
                        $status = 'suspended';
                    } elseif ($whmcsStatus === 'cancelled') {
                        $status = 'cancelled';
                    } elseif ($whmcsStatus === 'terminated') {
                        $status = 'terminated';
                    }

                    $this->db->insert(
                        'INSERT INTO services (client_id, product_id, product_name, billing_cycle, amount, status, next_due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [
                            $clientMap[$whmcsUserId],
                            $productMap[$whmcsProductId],
                            $row['domain'] ?: 'Hosting Account',
                            $billingCycle,
                            (float) ($row['amount'] ?? 0.00),
                            $status,
                            $row['nextduedate'] ?: $nowStr,
                            $row['regdate'] ?? $nowStr,
                            $nowStr,
                        ]
                    );
                    $imported['services']++;
                }
            } catch (\Throwable $e) {
                $errors[] = ['row' => 0, 'reason' => 'Services migration failed: ' . $e->getMessage()];
            }

            // 5. Domains
            try {
                $whmcsDomains = $remotePdo->query("SELECT * FROM {$prefix}tbldomains")->fetchAll();
                foreach ($whmcsDomains as $row) {
                    $whmcsUserId = (int) $row['userid'];
                    if (!isset($clientMap[$whmcsUserId])) {
                        continue;
                    }

                    $domainName = strtolower(trim((string) $row['domain']));
                    $tld = substr($domainName, (int) strpos($domainName, '.') + 1);

                    $status = 'pending';
                    $whmcsStatus = strtolower((string) ($row['status'] ?? ''));
                    if ($whmcsStatus === 'active') {
                        $status = 'active';
                    } elseif ($whmcsStatus === 'expired') {
                        $status = 'expired';
                    } elseif ($whmcsStatus === 'cancelled') {
                        $status = 'cancelled';
                    }

                    $this->db->insert(
                        'INSERT INTO domains (client_id, domain_name, tld, registrar_slug, status, registration_date, expiry_date, next_due_date, auto_renew, amount, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [
                            $clientMap[$whmcsUserId],
                            $domainName,
                            $tld,
                            ($row['registrar'] ?: 'local'),
                            $status,
                            $row['registrationdate'] ?: null,
                            $row['expirydate'] ?: null,
                            $row['nextduedate'] ?: null,
                            ($row['donotrenew'] ? 0 : 1),
                            (float) ($row['recurringamount'] ?? 0.00),
                            $nowStr,
                            $nowStr,
                        ]
                    );
                    $imported['domains']++;
                }
            } catch (\Throwable $e) {
                $errors[] = ['row' => 0, 'reason' => 'Domains migration failed: ' . $e->getMessage()];
            }

            // 6. Invoices
            try {
                $whmcsInvoices = $remotePdo->query("SELECT * FROM {$prefix}tblinvoices")->fetchAll();
                foreach ($whmcsInvoices as $row) {
                    $whmcsUserId = (int) $row['userid'];
                    if (!isset($clientMap[$whmcsUserId])) {
                        continue;
                    }

                    $status = 'unpaid';
                    $whmcsStatus = strtolower((string) ($row['status'] ?? ''));
                    if ($whmcsStatus === 'paid') {
                        $status = 'paid';
                    } elseif ($whmcsStatus === 'cancelled') {
                        $status = 'cancelled';
                    } elseif ($whmcsStatus === 'refunded') {
                        $status = 'refunded';
                    }

                    // No invoice_number column exists on the local invoices
                    // table — a real, pre-existing bug in this INSERT
                    // (invoices are numbered "INV-{id}" from the local id
                    // everywhere else in the app, e.g. PDF filenames); a
                    // WHMCS invoicenum has no column to land in and is
                    // dropped rather than guessing at a schema change here.
                    $localInvoiceId = (int) $this->db->insert(
                        'INSERT INTO invoices (client_id, subtotal, tax_amount, total, status, created_at, due_date, paid_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [
                            $clientMap[$whmcsUserId],
                            (float) ($row['subtotal'] ?? 0.00),
                            (float) (($row['tax'] ?? 0.00) + ($row['tax2'] ?? 0.00)),
                            (float) ($row['total'] ?? 0.00),
                            $status,
                            $row['date'] ?? $nowStr,
                            $row['duedate'] ?: $nowStr,
                            $row['datepaid'] ?: null,
                            $nowStr,
                        ]
                    );
                    $invoiceMap[(int) $row['id']] = $localInvoiceId;
                    $imported['invoices']++;
                }
            } catch (\Throwable $e) {
                $errors[] = ['row' => 0, 'reason' => 'Invoices migration failed: ' . $e->getMessage()];
            }

            // 7. Transactions — WHMCS's payment ledger (tblaccounts). No
            // explicit status column exists there: a positive `amountout`
            // means a refund entry, otherwise it's a completed payment of
            // `amountin`. Skipped rows referencing an invoice that wasn't
            // migrated (e.g. it belonged to a client we skipped) rather than
            // guessing at a fallback invoice.
            try {
                $whmcsTransactions = $remotePdo->query("SELECT * FROM {$prefix}tblaccounts")->fetchAll();
                foreach ($whmcsTransactions as $row) {
                    $whmcsInvoiceId = (int) ($row['invoiceid'] ?? 0);
                    if (!isset($invoiceMap[$whmcsInvoiceId])) {
                        continue;
                    }

                    $amountOut = (float) ($row['amountout'] ?? 0);
                    $isRefund = $amountOut > 0;

                    $this->db->insert(
                        'INSERT INTO transactions (invoice_id, gateway_slug, amount, status, gateway_transaction_id, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                        [
                            $invoiceMap[$whmcsInvoiceId],
                            $row['gateway'] ?: 'manual',
                            $isRefund ? $amountOut : (float) ($row['amountin'] ?? 0),
                            $isRefund ? 'refunded' : 'completed',
                            $row['transid'] ?: null,
                            $row['date'] ?? $nowStr,
                        ]
                    );
                    $imported['transactions']++;
                }
            } catch (\Throwable $e) {
                $errors[] = ['row' => 0, 'reason' => 'Transactions migration failed: ' . $e->getMessage()];
            }

            // 8. Currencies — platform-wide settings, no per-row client/product
            // FK remapping needed. Matched by currency code (upsert, not
            // append), so re-running the migration is safe.
            try {
                $whmcsCurrencies = $remotePdo->query("SELECT * FROM {$prefix}tblcurrencies")->fetchAll();
                foreach ($whmcsCurrencies as $row) {
                    $code = strtoupper(trim((string) ($row['code'] ?? '')));
                    if ($code === '') {
                        continue;
                    }

                    $symbol = trim((string) ($row['prefix'] ?? '')) ?: (trim((string) ($row['suffix'] ?? '')) ?: '$');
                    $rate = (float) ($row['rate'] ?? 1.0);

                    $existing = $this->db->selectOne('SELECT id FROM currencies WHERE code = ?', [$code]);
                    if ($existing !== null) {
                        $this->db->update(
                            'UPDATE currencies SET symbol = ?, exchange_rate = ?, updated_at = ? WHERE id = ?',
                            [$symbol, $rate, $nowStr, $existing['id']]
                        );
                    } else {
                        $this->db->insert(
                            'INSERT INTO currencies (code, symbol, exchange_rate, is_default, created_at, updated_at) VALUES (?, ?, ?, 0, ?, ?)',
                            [$code, $symbol, $rate, $nowStr, $nowStr]
                        );
                    }
                    $imported['currencies']++;
                }
            } catch (\Throwable $e) {
                $errors[] = ['row' => 0, 'reason' => 'Currencies migration failed: ' . $e->getMessage()];
            }

            // 9. Tax rules — WHMCS's per-country/state VAT rules. Table/column
            // names here (tbltaxrules: country/state/name/taxrate) are the
            // best-documented WHMCS shape available (no live reference
            // install exists to confirm against, per this project's own
            // blueprint) — wrapped in the same try/catch isolation as every
            // other step, so a name mismatch surfaces as a clear per-step
            // error rather than aborting the whole migration.
            try {
                $whmcsTaxRules = $remotePdo->query("SELECT * FROM {$prefix}tbltaxrules")->fetchAll();
                foreach ($whmcsTaxRules as $row) {
                    $countryCode = strtoupper(trim((string) ($row['country'] ?? '')));
                    if (strlen($countryCode) !== 2) {
                        continue; // local schema requires a 2-letter ISO country code
                    }

                    $state = trim((string) ($row['state'] ?? '')) ?: null;
                    $name = trim((string) ($row['name'] ?? '')) ?: 'Tax';
                    $rate = (float) ($row['taxrate'] ?? 0);

                    $existing = $state !== null
                        ? $this->db->selectOne('SELECT id FROM tax_rules WHERE country_code = ? AND state = ?', [$countryCode, $state])
                        : $this->db->selectOne('SELECT id FROM tax_rules WHERE country_code = ? AND state IS NULL', [$countryCode]);

                    if ($existing !== null) {
                        $this->db->update(
                            'UPDATE tax_rules SET name = ?, rate = ?, updated_at = ? WHERE id = ?',
                            [$name, $rate, $nowStr, $existing['id']]
                        );
                    } else {
                        $this->db->insert(
                            'INSERT INTO tax_rules (country_code, state, name, rate, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
                            [$countryCode, $state, $name, $rate, $nowStr, $nowStr]
                        );
                    }
                    $imported['tax_rules']++;
                }
            } catch (\Throwable $e) {
                $errors[] = ['row' => 0, 'reason' => 'Tax rules migration failed: ' . $e->getMessage()];
            }

            // 10. Contacts — WHMCS's sub-account contacts (tblcontacts),
            // remapped via $clientMap. WHMCS stores `permissions` as a
            // comma-separated string; split into the JSON array format
            // client_contacts expects. Skips a contact that already exists
            // for that client+email (no unique constraint enforces this at
            // the DB level, so this check is what keeps a re-run from
            // duplicating contacts).
            try {
                $whmcsContacts = $remotePdo->query("SELECT * FROM {$prefix}tblcontacts")->fetchAll();
                foreach ($whmcsContacts as $row) {
                    $whmcsUserId = (int) ($row['userid'] ?? 0);
                    if (!isset($clientMap[$whmcsUserId])) {
                        continue;
                    }

                    $email = strtolower(trim((string) ($row['email'] ?? '')));
                    if ($email === '') {
                        continue;
                    }

                    $existing = $this->db->selectOne(
                        'SELECT id FROM client_contacts WHERE client_id = ? AND email = ?',
                        [$clientMap[$whmcsUserId], $email]
                    );
                    if ($existing !== null) {
                        continue;
                    }

                    $name = trim(trim((string) ($row['firstname'] ?? '')) . ' ' . trim((string) ($row['lastname'] ?? '')));
                    $permissionsRaw = trim((string) ($row['permissions'] ?? ''));
                    $permissions = $permissionsRaw !== ''
                        ? array_values(array_filter(array_map('trim', explode(',', $permissionsRaw))))
                        : [];

                    $this->db->insert(
                        'INSERT INTO client_contacts (client_id, name, email, permissions, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
                        [$clientMap[$whmcsUserId], $name !== '' ? $name : $email, $email, json_encode($permissions), $nowStr, $nowStr]
                    );
                    $imported['contacts']++;
                }
            } catch (\Throwable $e) {
                $errors[] = ['row' => 0, 'reason' => 'Contacts migration failed: ' . $e->getMessage()];
            }

            // 11. Configurable options — the fiddliest table group (blueprint
            // §4.2 flags this explicitly), and the table/column names below
            // (tblproductconfiggroups, tblproductconfiglinks,
            // tblproductconfigoptions, tblproductconfigoptionssub, and the
            // shared tblpricing table keyed by type='configoptions') are the
            // best-documented real WHMCS shape available — there is no live
            // reference install to confirm against (see this project's
            // blueprint §0), so treat this step as the one most likely to
            // need column-name adjustment against a real export. Scoped
            // deliberately to a straightforward name/price/group mapping,
            // not every WHMCS edge case (hidden options, quantity tiers).
            //
            // WHMCS models two levels under a group — an "option" (e.g.
            // "RAM") containing "sub-options" (e.g. "1GB"/"2GB"/"4GB") — but
            // this app's schema has only one flat level of options per
            // group, so each WHMCS sub-option becomes one local option named
            // "{option name} - {sub-option name}" to keep both levels'
            // information without losing data.
            try {
                $optionGroupMap = []; // WHMCS gid => local configurable_option_groups.id

                $whmcsGroups = $remotePdo->query("SELECT * FROM {$prefix}tblproductconfiggroups")->fetchAll();
                foreach ($whmcsGroups as $row) {
                    $name = trim((string) ($row['name'] ?? ''));
                    if ($name === '') {
                        continue;
                    }

                    $existing = $this->db->selectOne('SELECT id FROM configurable_option_groups WHERE LOWER(name) = LOWER(?)', [$name]);
                    $localGroupId = $existing !== null ? (int) $existing['id'] : (int) $this->db->insert(
                        'INSERT INTO configurable_option_groups (name, created_at, updated_at) VALUES (?, ?, ?)',
                        [$name, $nowStr, $nowStr]
                    );
                    $optionGroupMap[(int) $row['id']] = $localGroupId;
                }

                $whmcsLinks = $remotePdo->query("SELECT * FROM {$prefix}tblproductconfiglinks")->fetchAll();
                foreach ($whmcsLinks as $row) {
                    $whmcsGid = (int) ($row['gid'] ?? 0);
                    $whmcsPid = (int) ($row['pid'] ?? 0);
                    if (!isset($optionGroupMap[$whmcsGid]) || !isset($productMap[$whmcsPid])) {
                        continue;
                    }

                    $existing = $this->db->selectOne(
                        'SELECT product_id FROM product_configurable_option_groups WHERE product_id = ? AND option_group_id = ?',
                        [$productMap[$whmcsPid], $optionGroupMap[$whmcsGid]]
                    );
                    if ($existing === null) {
                        $this->db->insert(
                            'INSERT INTO product_configurable_option_groups (product_id, option_group_id) VALUES (?, ?)',
                            [$productMap[$whmcsPid], $optionGroupMap[$whmcsGid]]
                        );
                    }
                }

                $whmcsOptions = $remotePdo->query("SELECT * FROM {$prefix}tblproductconfigoptions")->fetchAll();
                foreach ($whmcsOptions as $optionRow) {
                    $whmcsGid = (int) ($optionRow['gid'] ?? 0);
                    if (!isset($optionGroupMap[$whmcsGid])) {
                        continue;
                    }

                    $optionName = trim((string) ($optionRow['optionname'] ?? ''));
                    $localGroupId = $optionGroupMap[$whmcsGid];

                    $whmcsSubOptions = $remotePdo->prepare("SELECT * FROM {$prefix}tblproductconfigoptionssub WHERE configid = ?");
                    $whmcsSubOptions->execute([(int) $optionRow['id']]);

                    foreach ($whmcsSubOptions->fetchAll() as $subRow) {
                        $subName = trim((string) ($subRow['optionname'] ?? ''));
                        $localName = $optionName !== '' ? "{$optionName} - {$subName}" : $subName;

                        if ($localName === '') {
                            continue;
                        }

                        $existingOption = $this->db->selectOne(
                            'SELECT id FROM configurable_options WHERE option_group_id = ? AND LOWER(name) = LOWER(?)',
                            [$localGroupId, $localName]
                        );
                        $localOptionId = $existingOption !== null ? (int) $existingOption['id'] : (int) $this->db->insert(
                            'INSERT INTO configurable_options (option_group_id, name, created_at, updated_at) VALUES (?, ?, ?, ?)',
                            [$localGroupId, $localName, $nowStr, $nowStr]
                        );

                        $pricingRow = $remotePdo->prepare("SELECT * FROM {$prefix}tblpricing WHERE type = 'configoptions' AND relid = ?");
                        $pricingRow->execute([(int) $subRow['id']]);
                        $pricing = $pricingRow->fetch();

                        if ($pricing !== false) {
                            $cycleColumns = [
                                'monthly' => 'monthly',
                                'quarterly' => 'quarterly',
                                'semi_annually' => 'semiannually',
                                'annually' => 'annually',
                                'biennially' => 'biennially',
                                'triennially' => 'triennially',
                            ];
                            foreach ($cycleColumns as $localCycle => $whmcsColumn) {
                                if (!isset($pricing[$whmcsColumn]) || $pricing[$whmcsColumn] === '' || $pricing[$whmcsColumn] === null) {
                                    continue;
                                }

                                $existingPrice = $this->db->selectOne(
                                    'SELECT id FROM configurable_option_pricing WHERE option_id = ? AND billing_cycle = ?',
                                    [$localOptionId, $localCycle]
                                );
                                if ($existingPrice !== null) {
                                    $this->db->update(
                                        'UPDATE configurable_option_pricing SET price = ? WHERE id = ?',
                                        [(float) $pricing[$whmcsColumn], $existingPrice['id']]
                                    );
                                } else {
                                    $this->db->insert(
                                        'INSERT INTO configurable_option_pricing (option_id, billing_cycle, price) VALUES (?, ?, ?)',
                                        [$localOptionId, $localCycle, (float) $pricing[$whmcsColumn]]
                                    );
                                }
                            }
                        }

                        $imported['configurable_options']++;
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = ['row' => 0, 'reason' => 'Configurable options migration failed: ' . $e->getMessage()];
            }

            // 12. Departments + tickets — historical support data, not a
            // live sync. Table/column names (tbldepartments: id/name/email;
            // tbltickets: id/did/userid/email/name/subject/status/priority/
            // admin/date; tblticketposts: id/ticketid/message/name/admin/
            // date) are the best-documented real WHMCS shape available, same
            // caveat as the configurable-options step above. WHMCS keeps
            // private admin-only notes in a separate mechanism this step
            // doesn't have a documented table name for, so every migrated
            // reply lands as non-private (is_private = 0) — the safe
            // direction to default wrong in, since it under-restricts
            // visibility rather than hiding a reply that should be visible.
            $departmentMap = []; // WHMCS did => local departments.id
            $ticketMap = [];     // WHMCS ticket id => local tickets.id

            try {
                $whmcsDepartments = $remotePdo->query("SELECT * FROM {$prefix}tbldepartments")->fetchAll();
                foreach ($whmcsDepartments as $row) {
                    $name = trim((string) ($row['name'] ?? ''));
                    if ($name === '') {
                        continue;
                    }

                    $existing = $this->db->selectOne('SELECT id FROM departments WHERE LOWER(name) = LOWER(?)', [$name]);
                    $localId = $existing !== null ? (int) $existing['id'] : (int) $this->db->insert(
                        'INSERT INTO departments (name, email, created_at, updated_at) VALUES (?, ?, ?, ?)',
                        [$name, $row['email'] ?: null, $nowStr, $nowStr]
                    );
                    $departmentMap[(int) $row['id']] = $localId;
                    $imported['departments']++;
                }
            } catch (\Throwable $e) {
                $errors[] = ['row' => 0, 'reason' => 'Departments migration failed: ' . $e->getMessage()];
            }

            try {
                $whmcsTickets = $remotePdo->query("SELECT * FROM {$prefix}tbltickets")->fetchAll();
                foreach ($whmcsTickets as $row) {
                    $whmcsDid = (int) ($row['did'] ?? 0);

                    if (!isset($departmentMap[$whmcsDid])) {
                        continue; // department_id is a required FK locally — no guessing at a substitute department
                    }

                    $departmentId = $departmentMap[$whmcsDid];

                    $email = trim((string) ($row['email'] ?? ''));
                    if ($email === '') {
                        continue;
                    }

                    $whmcsUserId = (int) ($row['userid'] ?? 0);
                    $clientId = $clientMap[$whmcsUserId] ?? null;

                    $status = strtolower(trim((string) ($row['status'] ?? '')));
                    if (!in_array($status, ['open', 'answered', 'customer-reply', 'closed'], true)) {
                        $status = 'open';
                    }

                    $priority = strtolower(trim((string) ($row['priority'] ?? '')));
                    if (!in_array($priority, ['low', 'medium', 'high'], true)) {
                        $priority = 'medium';
                    }

                    $localTicketId = (int) $this->db->insert(
                        'INSERT INTO tickets (client_id, email, department_id, subject, status, priority, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                        [
                            $clientId,
                            $email,
                            $departmentId,
                            trim((string) ($row['subject'] ?? '')) ?: '(no subject)',
                            $status,
                            $priority,
                            $row['date'] ?? $nowStr,
                            $nowStr,
                        ]
                    );
                    $ticketMap[(int) $row['id']] = $localTicketId;
                    $imported['tickets']++;
                }
            } catch (\Throwable $e) {
                $errors[] = ['row' => 0, 'reason' => 'Tickets migration failed: ' . $e->getMessage()];
            }

            try {
                $whmcsPosts = $remotePdo->query("SELECT * FROM {$prefix}tblticketposts")->fetchAll();
                foreach ($whmcsPosts as $row) {
                    $whmcsTicketId = (int) ($row['ticketid'] ?? 0);
                    if (!isset($ticketMap[$whmcsTicketId])) {
                        continue;
                    }

                    $adminName = trim((string) ($row['admin'] ?? ''));
                    $isAdminReply = $adminName !== '';

                    $this->db->insert(
                        'INSERT INTO ticket_replies (ticket_id, author_type, author_name, message, is_private, created_at) VALUES (?, ?, ?, ?, 0, ?)',
                        [
                            $ticketMap[$whmcsTicketId],
                            $isAdminReply ? 'admin' : 'client',
                            $isAdminReply ? $adminName : (trim((string) ($row['name'] ?? '')) ?: 'Client'),
                            (string) ($row['message'] ?? ''),
                            $row['date'] ?? $nowStr,
                        ]
                    );
                }
            } catch (\Throwable $e) {
                $errors[] = ['row' => 0, 'reason' => 'Ticket replies migration failed: ' . $e->getMessage()];
            }

            // 13. Promotions/coupon codes — table/column names (tblpromotions:
            // id/code/type/value/maxuses/uses/startdate/expirydate/status) are
            // the best-documented real WHMCS shape available, same caveat as
            // the configurable-options and tickets steps above. Matched by
            // code (upsert), so re-running the migration doesn't duplicate.
            try {
                $whmcsPromotions = $remotePdo->query("SELECT * FROM {$prefix}tblpromotions")->fetchAll();
                foreach ($whmcsPromotions as $row) {
                    $code = trim((string) ($row['code'] ?? ''));
                    if ($code === '') {
                        continue;
                    }

                    $whmcsType = strtolower(trim((string) ($row['type'] ?? '')));
                    $type = str_contains($whmcsType, 'fixed') ? 'fixed' : 'percentage';

                    $whmcsStatus = strtolower(trim((string) ($row['status'] ?? '')));
                    $status = in_array($whmcsStatus, ['disabled', 'inactive', 'expired'], true) ? 'inactive' : 'active';

                    $maxUses = isset($row['maxuses']) && (int) $row['maxuses'] > 0 ? (int) $row['maxuses'] : null;
                    $usesCount = (int) ($row['uses'] ?? 0);

                    $existing = $this->db->selectOne('SELECT id FROM promotions WHERE UPPER(code) = UPPER(?)', [$code]);

                    if ($existing !== null) {
                        $this->db->update(
                            'UPDATE promotions SET type = ?, value = ?, max_redemptions = ?, redemption_count = ?, starts_at = ?, expires_at = ?, status = ?, updated_at = ? WHERE id = ?',
                            [$type, (float) ($row['value'] ?? 0), $maxUses, $usesCount, $row['startdate'] ?: null, $row['expirydate'] ?: null, $status, $nowStr, $existing['id']]
                        );
                    } else {
                        $this->db->insert(
                            'INSERT INTO promotions (code, type, value, max_redemptions, redemption_count, min_order_amount, starts_at, expires_at, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                            [$code, $type, (float) ($row['value'] ?? 0), $maxUses, $usesCount, 0.00, $row['startdate'] ?: null, $row['expirydate'] ?: null, $status, $nowStr, $nowStr]
                        );
                    }

                    $imported['promotions']++;
                }
            } catch (\Throwable $e) {
                $errors[] = ['row' => 0, 'reason' => 'Promotions migration failed: ' . $e->getMessage()];
            }

            return [
                'success' => count($errors) === 0,
                'message' => count($errors) === 0 ? 'WHMCS database imported successfully!' : 'Completed with some errors.',
                'imported' => $imported,
                'errors' => $errors,
            ];
        });
    }
}
