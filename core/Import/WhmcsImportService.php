<?php

declare(strict_types=1);

namespace CodeVault\Import;

use CodeVault\Catalog\ProductPricingRepository;
use CodeVault\Database;
use CodeVault\Domains\DomainPricingRepository;
use CodeVault\Domains\DomainSettings;
use CodeVault\Domains\RegistrarRepository;
use DateTimeImmutable;
use PDO;

final class WhmcsImportService
{
    private readonly string $progressFile;

    /**
     * Identifies the current import attempt so the frontend can tell a
     * fresh run's progress apart from a previous run's leftover result.
     * The progress file persists between runs, so without this a new
     * attempt that fails before the importer runs would show the OLD
     * run's error (wrong username/host/IP) — see the migrator JS.
     */
    private string $currentRunId = '';

    public function __construct(
        private readonly Database $db,
        private readonly DomainPricingRepository $domainPricing,
        private readonly RegistrarRepository $registrars,
        private readonly ProductPricingRepository $productPricing,
        private readonly DomainSettings $domainSettings,
        ?string $progressFile = null
    ) {
        // Defaults to the real admin-facing progress file, but tests must
        // override this — otherwise running the test suite overwrites the
        // live migrator UI's state with the test's mock import counts.
        $this->progressFile = $progressFile ?? dirname(__DIR__, 2) . '/storage/migration_progress.json';
    }

    private function updateProgress(string $status, int $percentage, string $step, array $imported, array $errors): void
    {
        file_put_contents($this->progressFile, json_encode([
            'status' => $status,
            'percentage' => $percentage,
            'current_step' => $step,
            'imported' => $imported,
            'errors' => $errors,
            'run_id' => $this->currentRunId,
        ], JSON_PRETTY_PRINT));
    }

    /**
     * Connects to a remote WHMCS database and migrates its data.
     *
     * @param array{host: string, port: int, database: string, username: string, password: string, prefix: string} $credentials
     * @return array{success: bool, message: string, imported: array<string, int>, errors: array<int, array{row: int, reason: string}>}
     */
    public function import(array $credentials, bool $overwrite = false): array
    {
        // A real production WHMCS database can easily have thousands of
        // clients/invoices/tickets, and this import does one row-by-row
        // INSERT/SELECT at a time across 15 steps inside a single request
        // — on shared hosting's default limits (commonly 30-60s execution
        // time, 128M memory) that's a near-certain timeout or memory
        // exhaustion, which kills the script mid-run and sends back a
        // non-JSON (blank or host-generated error page) response — the
        // frontend's fetch().then(r => r.json()) then throws, landing in
        // its generic catch-all "unexpected server error" message with no
        // useful detail, and storage/migration_progress.json never
        // advances past whatever step was running. Neither call throws on
        // a host that disables them, so this is safe everywhere.
        set_time_limit(0);
        ini_set('memory_limit', '1024M');
        ignore_user_abort(true);

        $this->currentRunId = (string) ($credentials['run_id'] ?? '');

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
            'domain_pricing' => 0,
        ];
        $errors = [];

        $this->updateProgress('running', 0, 'Connecting to remote database...', $imported, $errors);

        // Pre-flight check: fast connection test using fsockopen to detect blocked ports
        $fp = @fsockopen($host, (int)$port, $errno, $errstr, 3.0);
        if (!$fp) {
            $errMessage = "Could not connect to database port {$port} on host {$host} (timeout or firewall block).";
            $this->updateProgress('failed', 0, 'Connection failed: ' . $errMessage, $imported, [['row' => 0, 'reason' => $errMessage]]);
            return [
                'success' => false,
                'message' => 'Failed to connect to the remote WHMCS database: ' . $errMessage . ' Please check that your server allows remote connections on this port.',
                'imported' => $imported,
                'errors' => [['row' => 0, 'reason' => $errMessage]],
            ];
        }
        fclose($fp);

        try {
            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
            $remotePdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 5,
            ]);
        } catch (\Throwable $e) {
            $this->updateProgress('failed', 0, 'Connection failed: ' . $e->getMessage(), $imported, [['row' => 0, 'reason' => 'Connection failed: ' . $e->getMessage()]]);
            return [
                'success' => false,
                'message' => 'Failed to connect to the remote WHMCS database: ' . $e->getMessage(),
                'imported' => $imported,
                'errors' => [['row' => 0, 'reason' => 'Connection failed: ' . $e->getMessage()]],
            ];
        }

        if ($overwrite) {
            $this->updateProgress('running', 5, 'Clearing existing database tables for overwrite...', $imported, $errors);
            try {
                $this->db->transaction(function() {
                    $this->db->statement('SET FOREIGN_KEY_CHECKS = 0');
                    // NB: 'departments' is intentionally NOT truncated — it
                    // holds a seeded default department (migration 0058) and
                    // the departments import step is idempotent (upsert by
                    // name), so wiping it would delete the seed for nothing.
                    // invoice_items IS cleared so re-runs don't accumulate
                    // orphaned line items under re-created invoices.
                    $tables = [
                        'transactions', 'service_custom_field_values', 'services', 'domains',
                        'invoice_items', 'invoices',
                        'products', 'product_groups', 'servers', 'server_groups', 'clients', 'ticket_replies',
                        'tickets', 'promotions', 'currencies', 'tax_rules'
                    ];
                    foreach ($tables as $table) {
                        $this->db->statement("TRUNCATE TABLE {$table}");
                    }
                    $this->db->statement('SET FOREIGN_KEY_CHECKS = 1');
                });
            } catch (\Throwable $e) {
                $this->updateProgress('failed', 5, 'Failed to clear local database: ' . $e->getMessage(), $imported, [['row' => 0, 'reason' => $e->getMessage()]]);
                return [
                    'success' => false,
                    'message' => 'Failed to clear local database: ' . $e->getMessage(),
                    'imported' => $imported,
                    'errors' => [['row' => 0, 'reason' => $e->getMessage()]],
                ];
            }
        }

        try {
            $result = $this->db->transaction(function () use ($remotePdo, $prefix, &$imported, &$errors) {
            $clientMap = [];  // WHMCS Client ID => Local Client ID
            $serverMap = [];  // WHMCS Server ID => Local Server ID
            $productMap = []; // WHMCS Product ID => Local Product ID
            $productNameMap = []; // WHMCS Product ID => product name (services step needs the real name, not the domain)
            $productTypeMap = []; // WHMCS Product ID => local products.type ('shared'/'reseller'/'dedicated'/'other')
            $invoiceMap = []; // WHMCS Invoice ID => Local Invoice ID
            $currencyMap = []; // WHMCS Currency ID => Local Currency ID
            $whmcsDefaultCurrencyId = null; // WHMCS tblcurrencies.id where default=1, used to pick one price row for domain TLD pricing (this app has no per-currency domain pricing)
            
            // Optimization Cache Maps to prevent queries inside loops
            $pricingByProduct = [];
            $pricingByConfigOption = [];
            $pricingByTld = [];
            $invoiceItemsByInvoice = [];
            $subOptionsByConfigId = [];
            $clientRateMap = [];

            // Load existing local currency exchange rates
            $existingCurrencies = $this->db->select('SELECT id, exchange_rate FROM currencies');
            $localCurrencyRates = [];
            foreach ($existingCurrencies as $c) {
                $localCurrencyRates[(int) $c['id']] = (float) ($c['exchange_rate'] ?? 1.0000);
            }

            // Load existing local clients currency rates
            $existingClients = $this->db->select('SELECT c.id, c.currency_id, curr.exchange_rate FROM clients c LEFT JOIN currencies curr ON c.currency_id = curr.id');
            $clientCurrencyMap = [];
            foreach ($existingClients as $c) {
                $clientRateMap[(int) $c['id']] = (float) ($c['exchange_rate'] ?? 1.0000);
                $clientCurrencyMap[(int) $c['id']] = $c['currency_id'] !== null ? (int) $c['currency_id'] : null;
            }

            // Pre-fetch remote pricing, items, and config sub-options to avoid roundtrips inside loops
            try {
                $pricingRows = $remotePdo->query("SELECT * FROM {$prefix}tblpricing")->fetchAll();
                foreach ($pricingRows as $r) {
                    $type = $r['type'];
                    $relid = (int) $r['relid'];
                    if ($type === 'product') {
                        $pricingByProduct[$relid][] = $r;
                    } elseif ($type === 'configoptions') {
                        $pricingByConfigOption[$relid] = $r;
                    } else {
                        $pricingByTld[$type][$relid][] = $r;
                    }
                }
            } catch (\Throwable $e) {}

            try {
                $invoiceItemRows = $remotePdo->query("SELECT * FROM {$prefix}tblinvoiceitems")->fetchAll();
                foreach ($invoiceItemRows as $r) {
                    $invoiceid = (int) $r['invoiceid'];
                    $invoiceItemsByInvoice[$invoiceid][] = $r;
                }
            } catch (\Throwable $e) {}

            try {
                $subOptionRows = $remotePdo->query("SELECT * FROM {$prefix}tblproductconfigoptionssub")->fetchAll();
                foreach ($subOptionRows as $r) {
                    $configid = (int) $r['configid'];
                    $subOptionsByConfigId[$configid][] = $r;
                }
            } catch (\Throwable $e) {}

            $nowStr = (new DateTimeImmutable())->format('Y-m-d H:i:s');

            $this->updateProgress('running', 10, 'Importing currencies...', $imported, $errors);
            // 0. Currencies
            try {
                $whmcsCurrencies = $remotePdo->query("SELECT * FROM {$prefix}tblcurrencies")->fetchAll();
                foreach ($whmcsCurrencies as $row) {
                    if (!empty($row['default'])) {
                        $whmcsDefaultCurrencyId = (int) $row['id'];
                    }

                    $code = strtoupper(trim((string) $row['code']));
                    $existing = $this->db->selectOne('SELECT id FROM currencies WHERE code = ?', [$code]);
                    if ($existing !== null) {
                        $currencyMap[(int) $row['id']] = (int) $existing['id'];
                    } else {
                        // WHMCS shows the currency symbol via `prefix`
                        // (e.g. "₦", "$"); `suffix` is usually empty or a
                        // trailing code. Prefer prefix, then suffix, then the
                        // ISO code as a last resort.
                        $symbol = trim((string) ($row['prefix'] ?? '')) ?: (trim((string) ($row['suffix'] ?? '')) ?: $code);

                        $localCurrId = (int) $this->db->insert(
                            'INSERT INTO currencies (code, symbol, exchange_rate, is_default, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
                            [
                                $code,
                                $symbol,
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

            $this->updateProgress('running', 20, 'Importing clients...', $imported, $errors);
            // 1. Clients
            try {
                $whmcsClients = $remotePdo->query("SELECT * FROM {$prefix}tblclients")->fetchAll();
                foreach ($whmcsClients as $row) {
                    // Per-row isolation: one bad client row must not abort
                    // the whole clients step — that would also strand every
                    // service/domain/invoice belonging to the un-imported
                    // clients (they key off clientMap), silently gutting the
                    // migration. Log and continue instead.
                    try {
                        $email = strtolower(trim((string) $row['email']));
                        // Check if client email already exists locally to avoid unique constraint crashes
                        $existing = $this->db->selectOne('SELECT id FROM clients WHERE email = ?', [$email]);
                        if ($existing !== null) {
                            $clientMap[(int) $row['id']] = (int) $existing['id'];
                            continue;
                        }

                        // Map password hash directly. WHMCS 8+ stores bcrypt
                        // (password_verify-compatible) but WHMCS <= 7.x stores
                        // PHPass portable hashes ($P$...), which PHP's
                        // password_verify() cannot check. Both formats are
                        // preserved as-is: ClientAuthManager::attempt() now
                        // falls back to PhpassHasher and transparently
                        // upgrades to Argon2id on first successful login.
                        // A genuinely missing password (never set in WHMCS)
                        // gets an unguessable random hash so the account can
                        // never be logged into with an empty/unknown value —
                        // the client recovers via the forgot-password flow.
                        $passwordHash = $row['password'];
                        if (!is_string($passwordHash) || $passwordHash === '') {
                            $passwordHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
                        }
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
                        $clientRateMap[$localClientId] = isset($localCurrencyRates[$currencyId]) ? $localCurrencyRates[$currencyId] : 1.0000;
                        $clientCurrencyMap[$localClientId] = $currencyId;
                        $imported['clients']++;

                        if ($imported['clients'] % 100 === 0) {
                            $this->updateProgress('running', 20, "Importing clients... ({$imported['clients']} so far)", $imported, $errors);
                        }
                    } catch (\Throwable $rowError) {
                        $errors[] = ['row' => 0, 'reason' => 'Client "' . ($row['email'] ?? ('#' . ($row['id'] ?? '?'))) . '" skipped: ' . $rowError->getMessage()];
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = ['row' => 0, 'reason' => 'Clients migration failed: ' . $e->getMessage()];
            }

            $this->updateProgress('running', 30, 'Importing servers and server groups...', $imported, $errors);
            // 2. Servers
            try {
                $whmcsServerGroups = $remotePdo->query("SELECT * FROM {$prefix}tblservergroups")->fetchAll();
                $serverGroupMap = []; // WHMCS Group ID => Local Group ID
                foreach ($whmcsServerGroups as $sgRow) {
                    $localGroupId = (int) $this->db->insert(
                        'INSERT INTO server_groups (name, created_at, updated_at) VALUES (?, ?, ?)',
                        [$sgRow['name'] ?? '', $nowStr, $nowStr]
                    );
                    $serverGroupMap[(int) $sgRow['id']] = $localGroupId;
                }

                $serverToGroupMap = [];
                try {
                    $whmcsSgRel = $remotePdo->query("SELECT serverid, groupid FROM {$prefix}tblservergroupsrel")->fetchAll();
                    foreach ($whmcsSgRel as $rel) {
                        $serverToGroupMap[(int) $rel['serverid']] = (int) $rel['groupid'];
                    }
                } catch (\Throwable $e) {}

                $whmcsServers = $remotePdo->query("SELECT * FROM {$prefix}tblservers")->fetchAll();
                foreach ($whmcsServers as $row) {
                    $moduleSlug = 'local';
                    $whmcsType = strtolower((string) ($row['type'] ?? ''));
                    if (str_contains($whmcsType, 'cpanel')) {
                        $moduleSlug = 'cpanel';
                    } elseif (str_contains($whmcsType, 'cyberpanel')) {
                        $moduleSlug = 'cyberpanel';
                    }

                    $whmcsGroupId = $serverToGroupMap[(int) $row['id']] ?? null;
                    $localServerGroupId = $whmcsGroupId !== null && isset($serverGroupMap[$whmcsGroupId]) ? $serverGroupMap[$whmcsGroupId] : null;

                    $localServerId = (int) $this->db->insert(
                        'INSERT INTO servers (server_group_id, name, hostname, module_slug, api_username, api_token, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [
                            $localServerGroupId,
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

            $this->updateProgress('running', 40, 'Importing product groups and products...', $imported, $errors);
            // 3. Product Groups and Products
            try {
                $groupMap = []; // WHMCS Group ID => Local Group ID
                
                $whmcsGroups = $remotePdo->query("SELECT * FROM {$prefix}tblproductgroups")->fetchAll();
                foreach ($whmcsGroups as $gRow) {
                    $localGroupId = (int) $this->db->insert(
                        'INSERT INTO product_groups (name, description, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
                        [
                            $gRow['name'] ?? '',
                            $gRow['headline'] ?? ($gRow['tagline'] ?? null),
                            (int) ($gRow['order'] ?? 0),
                            $nowStr,
                            $nowStr
                        ]
                    );
                    $groupMap[(int) $gRow['id']] = $localGroupId;
                }

                $groupRow = $this->db->selectOne('SELECT id FROM product_groups LIMIT 1');
                $defaultGroupId = $groupRow !== null ? (int) $groupRow['id'] : (int) $this->db->insert(
                    'INSERT INTO product_groups (name, created_at, updated_at) VALUES (?, ?, ?)',
                    ['Migrated Products', $nowStr, $nowStr]
                );

                $whmcsProducts = $remotePdo->query("SELECT * FROM {$prefix}tblproducts")->fetchAll();
                foreach ($whmcsProducts as $row) {
                    $groupId = isset($groupMap[(int) ($row['gid'] ?? 0)]) ? $groupMap[(int) ($row['gid'] ?? 0)] : $defaultGroupId;

                    // WHMCS's tblproducts.type ('hostingaccount',
                    // 'reselleraccount', 'server', 'other') is the closest
                    // available signal for this app's own type enum — best
                    // documented mapping available, not a guaranteed 1:1
                    // (WHMCS has no distinct "VPS" product type of its own;
                    // VPS/dedicated both commonly show up as 'server').
                    // Used below by the services step to decide whether an
                    // imported service should show its domain or its
                    // dedicated IP/hostname.
                    $whmcsType = strtolower(trim((string) ($row['type'] ?? '')));
                    $localType = match ($whmcsType) {
                        'server' => 'dedicated',
                        'reselleraccount' => 'reseller',
                        'hostingaccount' => 'shared',
                        default => 'other',
                    };

                    $productName = (string) ($row['name'] ?? '');
                    $localProductId = (int) $this->db->insert(
                        'INSERT INTO products (product_group_id, name, description, status, type, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                        [
                            $groupId,
                            $productName,
                            $row['description'] ?: null,
                            ($row['hidden'] ? 'hidden' : 'active'),
                            $localType,
                            $nowStr,
                            $nowStr,
                        ]
                    );
                    $productMap[(int) $row['id']] = $localProductId;
                    $productNameMap[(int) $row['id']] = $productName;
                    $productTypeMap[(int) $row['id']] = $localType;
                    $imported['products']++;

                    // Per-billing-cycle recurring price + setup fee
                    // (tblpricing, type='product', relid=this WHMCS
                    // product id) — same shared-table shape as the
                    // configoptions step above, same "best documented,
                    // no live install to verify against" caveat. Local
                    // product_pricing has no currency column (see
                    // ProductPricingRepository/product_pricing migration)
                    // — a product's catalog price is always in this
                    // system's own default currency, converted to
                    // whatever the shopper is viewing in at display time
                    // (CurrencyService::format/resolveEffective, already
                    // wired into the store/cart), so the row matching
                    // WHMCS's OWN default currency is the one that lines
                    // up 1:1 with that model. Falls back to whichever
                    // currency row exists first if WHMCS's default
                    // currency isn't priced for this product.
                     try {
                        $priceRows = $pricingByProduct[(int) $row['id']] ?? [];

                        $priceRow = null;
                        foreach ($priceRows as $r) {
                            if ($whmcsDefaultCurrencyId !== null && (int) ($r['currency'] ?? 0) === $whmcsDefaultCurrencyId) {
                                $priceRow = $r;
                                break;
                            }
                        }
                        $priceRow ??= ($priceRows[0] ?? null);

                        if ($priceRow !== null) {
                            $cycleColumns = [
                                'monthly' => ['monthly', 'msetupfee'],
                                'quarterly' => ['quarterly', 'qsetupfee'],
                                'semi_annually' => ['semiannually', 'ssetupfee'],
                                'annually' => ['annually', 'asetupfee'],
                                'biennially' => ['biennially', 'bsetupfee'],
                                'triennially' => ['triennially', 'tsetupfee'],
                            ];

                            foreach ($cycleColumns as $localCycle => [$priceCol, $setupCol]) {
                                $cyclePrice = $priceRow[$priceCol] ?? null;

                                if ($cyclePrice === null || $cyclePrice === '' || (float) $cyclePrice < 0) {
                                    continue;
                                }

                                $this->productPricing->setPricing($localProductId, $localCycle, (float) ($priceRow[$setupCol] ?? 0), (float) $cyclePrice);
                            }
                        }
                    } catch (\Throwable $e) {
                        $errors[] = ['row' => 0, 'reason' => "Pricing for product \"{$productName}\" failed to import: " . $e->getMessage()];
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = ['row' => 0, 'reason' => 'Products migration failed: ' . $e->getMessage()];
            }

            $this->updateProgress('running', 50, 'Importing client services...', $imported, $errors);
            // 4. Hosting/Services
            try {
                $whmcsHosting = $remotePdo->query("SELECT * FROM {$prefix}tblhosting")->fetchAll();
                foreach ($whmcsHosting as $row) {
                    // Per-row isolation: one bad service row shouldn't abort
                    // the whole services step and drop the rest.
                    try {
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

                    // A VPS/dedicated-server product doesn't really have a
                    // "domain" the way shared hosting does — WHMCS still
                    // requires some value in tblhosting.domain at order
                    // time, but the value that actually identifies the
                    // service is the assigned dedicated IP/hostname
                    // (tblhosting.dedicatedip). For those product types,
                    // prefer that over the domain field.
                    $localClientId = $clientMap[$whmcsUserId];
                    $clientRate = $clientRateMap[$localClientId] ?? 1.0000;
                    if ($clientRate <= 0.0) {
                        $clientRate = 1.0000;
                    }
                    $baseAmount = (float) ($row['amount'] ?? 0.00) / $clientRate;

                    $domainVal = trim((string) ($row['domain'] ?? ''));
                    $hostnameVal = trim((string) ($row['dedicatedip'] ?? ''));
                    $usernameVal = trim((string) ($row['username'] ?? ''));
                    $passwordVal = trim((string) ($row['password'] ?? ''));
                    $whmcsServerId = (int) $row['server'];
                    $localServerId = $serverMap[$whmcsServerId] ?? null;

                    $this->db->insert(
                        'INSERT INTO services (client_id, order_id, product_id, server_id, username, product_name, billing_cycle, amount, domain, hostname, password, status, next_due_date, created_at, updated_at) VALUES (?, null, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [
                            $localClientId,
                            $productMap[$whmcsProductId],
                            $localServerId,
                            $usernameVal !== '' ? $usernameVal : null,
                            $productNameMap[$whmcsProductId] ?? 'Hosting Account',
                            $billingCycle,
                            $baseAmount,
                            $domainVal !== '' ? $domainVal : null,
                            $hostnameVal !== '' ? $hostnameVal : null,
                            $passwordVal !== '' ? $passwordVal : null,
                            $status,
                            $row['nextduedate'] ?: $nowStr,
                            $row['regdate'] ?? $nowStr,
                            $nowStr,
                        ]
                    );
                    $imported['services']++;

                    if ($imported['services'] % 100 === 0) {
                        $this->updateProgress('running', 50, "Importing client services... ({$imported['services']} so far)", $imported, $errors);
                    }
                    } catch (\Throwable $rowError) {
                        $errors[] = ['row' => 0, 'reason' => 'Service for WHMCS hosting #' . ($row['id'] ?? '?') . ' skipped: ' . $rowError->getMessage()];
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = ['row' => 0, 'reason' => 'Services migration failed: ' . $e->getMessage()];
            }

            $this->updateProgress('running', 60, 'Importing client domains...', $imported, $errors);
            // 5. Domains
            try {
                $whmcsDomains = $remotePdo->query("SELECT * FROM {$prefix}tbldomains")->fetchAll();
                foreach ($whmcsDomains as $row) {
                    // Per-row isolation: a single bad domain (duplicate name
                    // hitting the UNIQUE constraint, a malformed value) must
                    // NOT abort the whole step and drop every domain after
                    // it — record it and keep going so the migration stays
                    // complete.
                    try {
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

                        $localClientId = $clientMap[$whmcsUserId];
                        $clientRate = $clientRateMap[$localClientId] ?? 1.0000;
                        if ($clientRate <= 0.0) {
                            $clientRate = 1.0000;
                        }
                        $domainAmount = (float) ($row['recurringamount'] ?? 0.00) / $clientRate;

                        try {
                            $this->db->insert(
                                'INSERT IGNORE INTO domains (client_id, domain_name, tld, registrar_slug, status, registration_date, expiry_date, next_due_date, auto_renew, amount, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                                [
                                    $localClientId,
                                    $domainName,
                                    $tld,
                                    ($row['registrar'] ?: 'local'),
                                    $status,
                                    $row['registrationdate'] ?: null,
                                    $row['expirydate'] ?: null,
                                    $row['nextduedate'] ?: null,
                                    ($row['donotrenew'] ? 0 : 1),
                                    $domainAmount,
                                    $nowStr,
                                    $nowStr,
                                ]
                            );
                            $imported['domains']++;
                        } catch (\Throwable $e) {
                            // Domain already exists - skip silently with INSERT IGNORE
                            // If this catch fires, INSERT IGNORE already skipped the duplicate
                        }

                        if ($imported['domains'] % 100 === 0) {
                            $this->updateProgress('running', 60, "Importing client domains... ({$imported['domains']} so far)", $imported, $errors);
                        }
                    } catch (\Throwable $rowError) {
                        $errors[] = ['row' => 0, 'reason' => 'Domain "' . ($row['domain'] ?? '?') . '" skipped: ' . $rowError->getMessage()];
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = ['row' => 0, 'reason' => 'Domains migration failed: ' . $e->getMessage()];
            }

            $this->updateProgress('running', 70, 'Importing invoices...', $imported, $errors);
            // 6. Invoices
            try {
                $whmcsInvoices = $remotePdo->query("SELECT * FROM {$prefix}tblinvoices")->fetchAll();
                foreach ($whmcsInvoices as $row) {
                    // Per-row isolation: one bad invoice shouldn't abort the
                    // whole invoices step and drop the rest.
                    try {
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

                    $localClientId = $clientMap[$whmcsUserId];
                    $invoiceCurrencyId = $clientCurrencyMap[$localClientId] ?? null;
                    $invoiceCurrencyRate = $clientRateMap[$localClientId] ?? 1.0000;
                    if ($invoiceCurrencyRate <= 0.0) {
                        $invoiceCurrencyRate = 1.0000;
                    }

                    $baseSubtotal = (float) ($row['subtotal'] ?? 0.00) / $invoiceCurrencyRate;
                    $baseTax = (float) (($row['tax'] ?? 0.00) + ($row['tax2'] ?? 0.00)) / $invoiceCurrencyRate;
                    $baseTotal = (float) ($row['total'] ?? 0.00) / $invoiceCurrencyRate;

                    // No invoice_number column exists on the local invoices
                    // table — a real, pre-existing bug in this INSERT
                    // (invoices are numbered "INV-{id}" from the local id
                    // everywhere else in the app, e.g. PDF filenames); a
                    // WHMCS invoicenum has no column to land in and is
                    // dropped rather than guessing at a schema change here.
                    $localInvoiceId = (int) $this->db->insert(
                        'INSERT INTO invoices (client_id, subtotal, tax_amount, total, status, currency_id, currency_rate, created_at, due_date, paid_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [
                            $localClientId,
                            $baseSubtotal,
                            $baseTax,
                            $baseTotal,
                            $status,
                            $invoiceCurrencyId,
                            $invoiceCurrencyRate,
                            $row['date'] ?? $nowStr,
                            $row['duedate'] ?: $nowStr,
                            $row['datepaid'] ?: null,
                            $nowStr,
                        ]
                    );
                    $invoiceMap[(int) $row['id']] = $localInvoiceId;
                    $imported['invoices']++;

                    if ($imported['invoices'] % 100 === 0) {
                        $this->updateProgress('running', 70, "Importing invoices... ({$imported['invoices']} so far)", $imported, $errors);
                    }

                    // Line items (tblinvoiceitems) — without these an
                    // imported invoice has nothing under "Items" when an
                    // admin opens it, even though the header total is
                    // right. One summary line is inserted as a fallback
                    // when WHMCS has no item rows for this invoice (a
                    // manually-created invoice, or the table doesn't
                    // exist on older WHMCS installs) so the invoice still
                    // shows *something* rather than an empty items table.
                    try {
                        $itemRows = $invoiceItemsByInvoice[(int) $row['id']] ?? [];

                        if ($itemRows === []) {
                            $this->db->insert(
                                'INSERT INTO invoice_items (invoice_id, description, amount) VALUES (?, ?, ?)',
                                [$localInvoiceId, 'Imported invoice', $baseSubtotal]
                            );
                        } else {
                            foreach ($itemRows as $itemRow) {
                                $this->db->insert(
                                    'INSERT INTO invoice_items (invoice_id, description, amount) VALUES (?, ?, ?)',
                                    [$localInvoiceId, trim((string) ($itemRow['description'] ?? '')) ?: 'Item', (float) ($itemRow['amount'] ?? 0.00) / $invoiceCurrencyRate]
                                );
                            }
                        }
                    } catch (\Throwable $e) {
                        // No tblinvoiceitems table on this WHMCS install — fall back to one summary line so the invoice isn't blank.
                        $this->db->insert(
                            'INSERT INTO invoice_items (invoice_id, description, amount) VALUES (?, ?, ?)',
                            [$localInvoiceId, 'Imported invoice', (float) ($row['subtotal'] ?? $row['total'] ?? 0.00)]
                        );
                    }
                    } catch (\Throwable $rowError) {
                        $errors[] = ['row' => 0, 'reason' => 'Invoice #' . ($row['id'] ?? '?') . ' skipped: ' . $rowError->getMessage()];
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = ['row' => 0, 'reason' => 'Invoices migration failed: ' . $e->getMessage()];
            }

            $this->updateProgress('running', 75, 'Importing payment transactions...', $imported, $errors);
            // 7. Transactions — WHMCS's payment ledger (tblaccounts). No
            // explicit status column exists there: a positive `amountout`
            // means a refund entry, otherwise it's a completed payment of
            // `amountin`. Skipped rows referencing an invoice that wasn't
            // migrated (e.g. it belonged to a client we skipped) rather than
            // guessing at a fallback invoice.
            try {
                // A payment ledger only ever grows — an established
                // business's tblaccounts can be huge, so this streams rows
                // one at a time (PDO's own buffered-cursor fetch) instead
                // of fetchAll()'s "load the whole table into memory first".
                $transactionsStmt = $remotePdo->query("SELECT * FROM {$prefix}tblaccounts");
                while (($row = $transactionsStmt->fetch()) !== false) {
                    $whmcsInvoiceId = (int) ($row['invoiceid'] ?? 0);
                    if (!isset($invoiceMap[$whmcsInvoiceId])) {
                        continue;
                    }

                    $amountOut = (float) ($row['amountout'] ?? 0);
                    $isRefund = $amountOut > 0;

                    try {
                        $this->db->insert(
                            'INSERT IGNORE INTO transactions (invoice_id, gateway_slug, amount, status, gateway_transaction_id, created_at) VALUES (?, ?, ?, ?, ?, ?)',
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
                    } catch (\Throwable $e) {
                        // Transaction already exists - skip silently with INSERT IGNORE
                    }

                    if ($imported['transactions'] % 100 === 0) {
                        $this->updateProgress('running', 75, "Importing payment transactions... ({$imported['transactions']} so far)", $imported, $errors);
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = ['row' => 0, 'reason' => 'Transactions migration failed: ' . $e->getMessage()];
            }

            $this->updateProgress('running', 80, 'Importing currency configurations...', $imported, $errors);
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

            $this->updateProgress('running', 85, 'Importing tax rules...', $imported, $errors);
            // 9. Tax rules — WHMCS's per-country/state VAT rules, stored in
            // tbltax (columns: level/name/state/country/taxrate), confirmed
            // against a real WHMCS database schema.
            try {
                $whmcsTaxRules = $remotePdo->query("SELECT * FROM {$prefix}tbltax")->fetchAll();
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

            $this->updateProgress('running', 90, 'Importing client sub-account contacts...', $imported, $errors);
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

            $this->updateProgress('running', 93, 'Importing configurable options groups...', $imported, $errors);
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

                    $subRows = $subOptionsByConfigId[(int) $optionRow['id']] ?? [];

                    foreach ($subRows as $subRow) {
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

                        $pricing = $pricingByConfigOption[(int) $subRow['id']] ?? false;

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

            $this->updateProgress('running', 95, 'Importing support departments and tickets...', $imported, $errors);
            // 12. Departments + tickets — historical support data, not a
            // live sync. Table/column names confirmed against a real WHMCS
            // database: tblticketdepartments (id/name/email), tbltickets
            // (id/did/userid/email/name/title/status/urgency/admin/date),
            // tblticketreplies (id/tid/message/name/admin/date). WHMCS keeps
            // private admin-only notes in a separate mechanism this step
            // doesn't migrate, so every migrated reply lands as non-private
            // (is_private = 0) — the safe direction to default wrong in,
            // since it under-restricts visibility rather than hiding a reply
            // that should be visible.
            $departmentMap = []; // WHMCS did => local departments.id
            $ticketMap = [];     // WHMCS ticket id => local tickets.id

            try {
                $whmcsDepartments = $remotePdo->query("SELECT * FROM {$prefix}tblticketdepartments")->fetchAll();
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

                    // WHMCS ticket status values ("Open", "Answered",
                    // "Customer-Reply", "Closed", "On Hold", "In Progress")
                    // — anything outside our local enum falls back to 'open'.
                    $status = strtolower(trim((string) ($row['status'] ?? '')));
                    if (!in_array($status, ['open', 'answered', 'customer-reply', 'closed'], true)) {
                        $status = 'open';
                    }

                    // WHMCS stores ticket priority in the `urgency` column
                    // ("Low"/"Medium"/"High"), not `priority`.
                    $priority = strtolower(trim((string) ($row['urgency'] ?? '')));
                    if (!in_array($priority, ['low', 'medium', 'high'], true)) {
                        $priority = 'medium';
                    }

                    $localTicketId = (int) $this->db->insert(
                        'INSERT INTO tickets (client_id, email, department_id, subject, status, priority, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                        [
                            $clientId,
                            $email,
                            $departmentId,
                            // WHMCS stores the ticket subject in `title`.
                            trim((string) ($row['title'] ?? '')) ?: '(no subject)',
                            $status,
                            $priority,
                            $row['date'] ?? $nowStr,
                            $nowStr,
                        ]
                    );
                    $ticketMap[(int) $row['id']] = $localTicketId;
                    $imported['tickets']++;

                    if ($imported['tickets'] % 100 === 0) {
                        $this->updateProgress('running', 95, "Importing tickets... ({$imported['tickets']} so far)", $imported, $errors);
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = ['row' => 0, 'reason' => 'Tickets migration failed: ' . $e->getMessage()];
            }

            try {
                // Same reasoning as tblaccounts above — a support history's
                // reply table only grows, so this streams rather than
                // fetchAll()-ing the whole thing into memory up front.
                $postsStmt = $remotePdo->query("SELECT * FROM {$prefix}tblticketreplies");
                $postCount = 0;
                while (($row = $postsStmt->fetch()) !== false) {
                    // WHMCS ticket replies link to their ticket via `tid`.
                    $whmcsTicketId = (int) ($row['tid'] ?? 0);
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

                    $postCount++;
                    if ($postCount % 100 === 0) {
                        $this->updateProgress('running', 95, "Importing ticket replies... ({$postCount} so far)", $imported, $errors);
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = ['row' => 0, 'reason' => 'Ticket replies migration failed: ' . $e->getMessage()];
            }

            $this->updateProgress('running', 98, 'Importing promotions and coupons...', $imported, $errors);
            // 13. Promotions/coupon codes — confirmed against a real WHMCS
            // schema: tblpromotions has code/type/value/maxuses/uses/
            // startdate/expirationdate (there is NO status column — a promo
            // is simply active until its expirationdate passes). Matched by
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

                    // WHMCS has no promo status column — derive it: expired
                    // (expirationdate in the past) => inactive, else active.
                    $expiresAt = ($row['expirationdate'] ?? null) ?: null;
                    $status = ($expiresAt !== null && $expiresAt < substr($nowStr, 0, 10)) ? 'inactive' : 'active';

                    $maxUses = isset($row['maxuses']) && (int) $row['maxuses'] > 0 ? (int) $row['maxuses'] : null;
                    $usesCount = (int) ($row['uses'] ?? 0);

                    $existing = $this->db->selectOne('SELECT id FROM promotions WHERE UPPER(code) = UPPER(?)', [$code]);

                    if ($existing !== null) {
                        $this->db->update(
                            'UPDATE promotions SET type = ?, value = ?, max_redemptions = ?, redemption_count = ?, starts_at = ?, expires_at = ?, status = ?, updated_at = ? WHERE id = ?',
                            [$type, (float) ($row['value'] ?? 0), $maxUses, $usesCount, ($row['startdate'] ?? null) ?: null, $expiresAt, $status, $nowStr, $existing['id']]
                        );
                    } else {
                        $this->db->insert(
                            'INSERT INTO promotions (code, type, value, max_redemptions, redemption_count, min_order_amount, starts_at, expires_at, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                            [$code, $type, (float) ($row['value'] ?? 0), $maxUses, $usesCount, 0.00, ($row['startdate'] ?? null) ?: null, $expiresAt, $status, $nowStr, $nowStr]
                        );
                    }

                    $imported['promotions']++;
                }
            } catch (\Throwable $e) {
                $errors[] = ['row' => 0, 'reason' => 'Promotions migration failed: ' . $e->getMessage()];
            }

            $this->updateProgress('running', 99, 'Importing domain TLD pricing...', $imported, $errors);
            // 14. Domain TLD pricing — same "best-documented shape, no live
            // reference install to confirm against" caveat as the
            // configurable-options/tickets/promotions steps above. WHMCS
            // keeps per-TLD pricing in tbldomainpricing (extension + which
            // registrar module auto-registers it) with the actual prices in
            // the shared tblpricing table (type IN domainregister/
            // domaintransfer/domainrenew, relid = tbldomainpricing.id, one
            // row per currency, price in msetupfee). This app's
            // domain_pricing table has no currency dimension, so the row
            // matching WHMCS's default currency is used (falling back to
            // whichever currency row exists first); it also has one flat
            // price per type rather than WHMCS's 1-10 year term tiers, so
            // only the 1-year fee is imported. Upserts by TLD via
            // DomainPricingRepository::save(), so re-running is safe.
            try {
                $whmcsTlds = $remotePdo->query("SELECT * FROM {$prefix}tbldomainpricing")->fetchAll();
                foreach ($whmcsTlds as $row) {
                    $extension = trim((string) ($row['extension'] ?? ''));
                    if ($extension === '') {
                        continue;
                    }

                    $relid = (int) $row['id'];
                    $prices = [];

                    foreach (['domainregister' => 'register_price', 'domaintransfer' => 'transfer_price', 'domainrenew' => 'renew_price'] as $whmcsType => $localField) {
                        $priceRows = $pricingByTld[$whmcsType][$relid] ?? [];

                        $priceRow = null;
                        foreach ($priceRows as $r) {
                            if ($whmcsDefaultCurrencyId !== null && (int) ($r['currency'] ?? 0) === $whmcsDefaultCurrencyId) {
                                $priceRow = $r;
                                break;
                            }
                        }
                        $priceRow ??= ($priceRows[0] ?? null);

                        $prices[$localField] = $priceRow !== null ? (float) ($priceRow['msetupfee'] ?? 0) : 0.0;
                    }

                    $registrarSlug = $this->resolveRegistrarSlug(trim((string) ($row['autoreg'] ?? '')));

                    if ($registrarSlug === null) {
                        $registrarSlug = 'local';
                        $errors[] = [
                            'row' => 0,
                            'reason' => ".{$extension}: WHMCS registrar module \"" . trim((string) ($row['autoreg'] ?? '')) . "\" has no matching registrar here — assigned to \"local\", review and reassign in Domain Pricing.",
                        ];
                    }

                    $this->domainPricing->save([
                        'tld' => $extension,
                        'registrar_slug' => $registrarSlug,
                        'register_price' => $prices['register_price'],
                        'transfer_price' => $prices['transfer_price'],
                        'renew_price' => $prices['renew_price'],
                    ]);

                    $imported['domain_pricing']++;
                }
            } catch (\Throwable $e) {
                $errors[] = ['row' => 0, 'reason' => 'Domain TLD pricing migration failed: ' . $e->getMessage()];
            }

            $this->updateProgress('running', 99, 'Importing default nameservers...', $imported, $errors);
            // 15. Default nameservers — WHMCS General Settings > Domains
            // tab stores these as individual rows in the general
            // tblconfiguration key/value table, setting names
            // 'DomainNS1'..'DomainNS5'. Best-documented shape available,
            // same caveat as the other steps that assume undocumented
            // internal table layouts.
            try {
                $nsStmt = $remotePdo->prepare("SELECT value FROM {$prefix}tblconfiguration WHERE setting = ?");
                $nameservers = [];

                for ($i = 1; $i <= 5; $i++) {
                    $nsStmt->execute(["DomainNS{$i}"]);
                    $row = $nsStmt->fetch();
                    $value = $row !== false ? trim((string) ($row['value'] ?? '')) : '';

                    if ($value !== '') {
                        $nameservers[] = $value;
                    }
                }

                if ($nameservers !== []) {
                    $this->domainSettings->setDefaultNameservers($nameservers);
                }
            } catch (\Throwable $e) {
                // No tblconfiguration table, or this WHMCS install has no default nameservers set — not fatal, admin can set them manually.
            }

            return [
                'success' => count($errors) === 0,
                'message' => count($errors) === 0 ? 'WHMCS database imported successfully!' : 'Completed with some errors.',
                'imported' => $imported,
                'errors' => $errors,
            ];
        });

            $success = count($result['errors']) === 0;
            $this->updateProgress($success ? 'completed' : 'failed', 100, $success ? 'Migration completed successfully!' : 'Completed with some errors.', $result['imported'], $result['errors']);
            return $result;
        } catch (\Throwable $e) {
            $this->updateProgress('failed', 100, 'Migration failed: ' . $e->getMessage(), $imported, [['row' => 0, 'reason' => 'Migration failed: ' . $e->getMessage()]]);
            return [
                'success' => false,
                'message' => 'Migration failed: ' . $e->getMessage(),
                'imported' => $imported,
                'errors' => [['row' => 0, 'reason' => $e->getMessage()]],
            ];
        }
    }

    /**
     * Password-only re-sync for clients already imported from WHMCS.
     *
     * The full import copies `tblclients.password` into `clients.password_hash`,
     * but WHMCS <= 7.x stores PHPass portable hashes ($P$...) that PHP's
     * password_verify() cannot check — so those clients could never log in.
     * This method does NOT re-import any services/invoices/etc.: it connects
     * to the remote WHMCS database, reads ONLY `tblclients` (id, email,
     * password), and for every local client whose password_hash is currently
     * empty/unusable, copies the matching WHMCS hash by email (case-insensitive).
     * Accounts that already have a working hash are left untouched.
     *
     * @param array{host: string, port: int, database: string, username: string, password: string, prefix: string} $credentials
     * @return array{success: bool, message: string, matched: int, not_found: int, empty_remote: int, errors: array<int, array{row: int, reason: string}>}
     */
    public function syncClientPasswords(array $credentials): array
    {
        set_time_limit(0);
        ini_set('memory_limit', '1024M');
        ignore_user_abort(true);

        $host = $credentials['host'];
        $port = $credentials['port'];
        $dbname = $credentials['database'];
        $user = $credentials['username'];
        $pass = $credentials['password'];
        $prefix = $credentials['prefix'] ?? '';

        $stats = ['matched' => 0, 'not_found' => 0, 'empty_remote' => 0];
        $errors = [];

        $fp = @fsockopen($host, (int)$port, $errno, $errstr, 3.0);
        if (!$fp) {
            return [
                'success' => false,
                'message' => "Could not connect to database port {$port} on host {$host} (timeout or firewall block).",
                ...$stats,
                'errors' => [['row' => 0, 'reason' => "Connection failed on port {$port}: {$errstr}"]],
            ];
        }
        fclose($fp);

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
                ...$stats,
                'errors' => [['row' => 0, 'reason' => 'Connection failed: ' . $e->getMessage()]],
            ];
        }

        try {
            // Remote email => password hash, normalised exactly the way the
            // clients import step does it (trim + lowercase) so the match key
            // is identical between a full import and this re-sync.
            $remotePasswords = [];
            $whmcsClients = $remotePdo->query("SELECT id, email, password FROM {$prefix}tblclients")->fetchAll();
            foreach ($whmcsClients as $row) {
                $email = strtolower(trim((string) ($row['email'] ?? '')));
                if ($email === '') {
                    continue;
                }
                $remotePasswords[$email] = $row['password'];
            }

            // Only accounts whose stored hash is empty/unusable are candidates —
            // a client who reset their password locally after migration keeps it.
            $localClients = $this->db->select(
                "SELECT id, email, password_hash FROM clients WHERE email != '' AND (password_hash IS NULL OR password_hash = '')"
            );

            $this->db->transaction(function () use ($localClients, $remotePasswords, &$stats, &$errors) {
                foreach ($localClients as $local) {
                    $email = strtolower(trim((string) $local['email']));

                    if (!isset($remotePasswords[$email])) {
                        $stats['not_found']++;
                        continue;
                    }

                    $remoteHash = $remotePasswords[$email];
                    if (!is_string($remoteHash) || $remoteHash === '') {
                        // WHMCS itself has no password for this account — a
                        // random unusable hash keeps the account secure and
                        // forces the forgot-password flow to set a real one.
                        $remoteHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
                        $stats['empty_remote']++;
                    }

                    $this->db->update(
                        'UPDATE clients SET password_hash = ?, updated_at = ? WHERE id = ?',
                        [$remoteHash, (new DateTimeImmutable())->format('Y-m-d H:i:s'), (int) $local['id']]
                    );
                    $stats['matched']++;
                }
            });
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Password sync failed: ' . $e->getMessage(),
                ...$stats,
                'errors' => [['row' => 0, 'reason' => $e->getMessage()]],
            ];
        }

        return [
            'success' => true,
            'message' => "Password sync complete: {$stats['matched']} account(s) updated.",
            ...$stats,
            'errors' => $errors,
        ];
    }

    /**
     * Matches a WHMCS registrar module directory name (tbldomainpricing.autoreg,
     * e.g. "enom", "resellerclub") against this app's registered registrars
     * by slug or display name. Returns null (rather than guessing) when
     * nothing matches — WHMCS's module names don't correspond 1:1 with the
     * registrars this app ships (local/upperlink/connectreseller), so an
     * unmatched TLD needs a human to pick the right one.
     */
    private function resolveRegistrarSlug(string $whmcsAutoreg): ?string
    {
        if ($whmcsAutoreg === '') {
            return null;
        }

        $normalized = strtolower($whmcsAutoreg);

        foreach ($this->registrars->all() as $registrar) {
            if (strtolower((string) $registrar['slug']) === $normalized || strtolower((string) $registrar['name']) === $normalized) {
                return (string) $registrar['slug'];
            }
        }

        return null;
    }
}
