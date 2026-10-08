<?php

declare(strict_types=1);

// GENERATED FILE — do not edit by hand. Rebuild with: php bin/build-schema.php
//
// The complete schema a fully migrated install has: every table, column, index and
// foreign key, computed by replaying database/migrations in order. On every live site
// SchemaReconciler compares the real database with this and ADDS whatever is missing
// (it never drops or rewrites anything), so a site that skipped a step — a migration
// that failed on its MySQL version, or one edited after the site had run it — still
// ends up with every table and column the code expects.

return [
    'last_migration' => '0218_cloudflare_email_routing.php',
    'migration_count' => 217,
    'tables' => [
        'abandoned_carts' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'session_id' => 'VARCHAR(128) NOT NULL',
                'client_id' => 'INT UNSIGNED NULL',
                'email' => 'VARCHAR(190) NULL',
                'items' => 'LONGTEXT NOT NULL',
                'promo_code' => 'VARCHAR(50) NULL',
                'total' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
                'currency_id' => 'INT UNSIGNED NULL',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
                'reminder_sent_at' => 'DATETIME NULL',
                'recovered_at' => 'DATETIME NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'idx_abandoned_session' => [
                    'unique' => true,
                    'fulltext' => false,
                    'columns' => [
                        'session_id',
                    ],
                ],
                'idx_abandoned_client' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'client_id',
                    ],
                ],
                'idx_abandoned_stale' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'recovered_at',
                        'updated_at',
                    ],
                ],
            ],
            'foreign' => [],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'activity_log' => [
            'columns' => [
                'id' => 'BIGINT UNSIGNED AUTO_INCREMENT',
                'actor_type' => 'ENUM(\'admin\', \'client\', \'system\') NOT NULL',
                'actor_id' => 'INT UNSIGNED NULL',
                'action' => 'VARCHAR(100) NOT NULL',
                'subject_type' => 'VARCHAR(100) NULL',
                'subject_id' => 'INT UNSIGNED NULL',
                'description' => 'VARCHAR(500) NOT NULL',
                'ip_address' => 'VARCHAR(45) NULL',
                'created_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'idx_actor' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'actor_type',
                        'actor_id',
                    ],
                ],
                'idx_subject' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'subject_type',
                        'subject_id',
                    ],
                ],
                'idx_created' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'created_at',
                    ],
                ],
            ],
            'foreign' => [],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'addon_modules' => [
            'columns' => [
                'slug' => 'VARCHAR(64) NOT NULL',
                'enabled' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'config' => 'TEXT NULL',
                'activated_at' => 'DATETIME NULL',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'slug',
            ],
            'indexes' => [],
            'foreign' => [],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'admins' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'username' => 'VARCHAR(64) NOT NULL',
                'email' => 'VARCHAR(191) NOT NULL',
                'password_hash' => 'VARCHAR(255) NOT NULL',
                'security_pin_hash' => 'VARCHAR(255) NULL',
                'two_factor_secret' => 'VARCHAR(32) NULL',
                'two_factor_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'two_factor_recovery_codes' => 'TEXT NULL',
                'display_name' => 'VARCHAR(191) NOT NULL',
                'role_id' => 'INT UNSIGNED NULL',
                'login_notify_enabled' => 'TINYINT(1) NOT NULL DEFAULT 1',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'username' => [
                    'unique' => true,
                    'fulltext' => false,
                    'columns' => [
                        'username',
                    ],
                ],
                'email' => [
                    'unique' => true,
                    'fulltext' => false,
                    'columns' => [
                        'email',
                    ],
                ],
                'fk_admins_role' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'role_id',
                    ],
                ],
            ],
            'foreign' => [
                'fk_admins_role' => [
                    'column' => 'role_id',
                    'references' => 'roles',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'affiliate_commissions' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'affiliate_id' => 'INT UNSIGNED NOT NULL',
                'invoice_id' => 'INT UNSIGNED NOT NULL',
                'amount' => 'DECIMAL(18,6) NOT NULL',
                'status' => 'ENUM(\'pending\', \'requested\', \'paid\') NOT NULL DEFAULT \'pending\'',
                'created_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'idx_affiliate_commissions_affiliate' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'affiliate_id',
                    ],
                ],
                'fk_affiliate_commissions_invoice' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'invoice_id',
                    ],
                ],
            ],
            'foreign' => [
                'fk_affiliate_commissions_affiliate' => [
                    'column' => 'affiliate_id',
                    'references' => 'affiliates',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
                'fk_affiliate_commissions_invoice' => [
                    'column' => 'invoice_id',
                    'references' => 'invoices',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'affiliate_payout_requests' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'affiliate_id' => 'INT UNSIGNED NOT NULL',
                'amount' => 'DECIMAL(18,6) NOT NULL',
                'status' => 'ENUM(\'requested\', \'approved\', \'rejected\', \'paid\') NOT NULL DEFAULT \'requested\'',
                'requested_at' => 'DATETIME NOT NULL',
                'processed_at' => 'DATETIME NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'idx_affiliate_payout_requests_affiliate' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'affiliate_id',
                    ],
                ],
            ],
            'foreign' => [
                'fk_affiliate_payout_requests_affiliate' => [
                    'column' => 'affiliate_id',
                    'references' => 'affiliates',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'affiliate_referrals' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'affiliate_id' => 'INT UNSIGNED NOT NULL',
                'referred_client_id' => 'INT UNSIGNED NOT NULL',
                'created_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'uniq_affiliate_referrals_client' => [
                    'unique' => true,
                    'fulltext' => false,
                    'columns' => [
                        'referred_client_id',
                    ],
                ],
                'idx_affiliate_referrals_affiliate' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'affiliate_id',
                    ],
                ],
            ],
            'foreign' => [
                'fk_affiliate_referrals_affiliate' => [
                    'column' => 'affiliate_id',
                    'references' => 'affiliates',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
                'fk_affiliate_referrals_client' => [
                    'column' => 'referred_client_id',
                    'references' => 'clients',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'affiliates' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'client_id' => 'INT UNSIGNED NOT NULL',
                'code' => 'VARCHAR(32) NOT NULL',
                'status' => 'ENUM(\'active\', \'suspended\') NOT NULL DEFAULT \'active\'',
                'commission_rate' => 'DECIMAL(5,2) NOT NULL DEFAULT 10.00',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'uniq_affiliates_client' => [
                    'unique' => true,
                    'fulltext' => false,
                    'columns' => [
                        'client_id',
                    ],
                ],
                'uniq_affiliates_code' => [
                    'unique' => true,
                    'fulltext' => false,
                    'columns' => [
                        'code',
                    ],
                ],
            ],
            'foreign' => [
                'fk_affiliates_client' => [
                    'column' => 'client_id',
                    'references' => 'clients',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'ai_usage_log' => [
            'columns' => [
                'id' => 'BIGINT UNSIGNED AUTO_INCREMENT',
                'feature' => 'VARCHAR(60) NOT NULL DEFAULT \'general\'',
                'provider' => 'VARCHAR(40) NOT NULL DEFAULT \'deepseek\'',
                'prompt_tokens' => 'INT UNSIGNED NOT NULL DEFAULT 0',
                'completion_tokens' => 'INT UNSIGNED NOT NULL DEFAULT 0',
                'total_tokens' => 'INT UNSIGNED NOT NULL DEFAULT 0',
                'success' => 'TINYINT(1) NOT NULL DEFAULT 1',
                'created_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'idx_created' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'created_at',
                    ],
                ],
                'idx_feature' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'feature',
                    ],
                ],
            ],
            'foreign' => [],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'announcements' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'title' => 'VARCHAR(255) NOT NULL',
                'body' => 'TEXT NOT NULL',
                'published_at' => 'DATETIME NOT NULL',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'idx_published' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'published_at',
                    ],
                ],
            ],
            'foreign' => [],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'api_credentials' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'client_id' => 'INT UNSIGNED NULL',
                'label' => 'VARCHAR(120) NOT NULL',
                'api_key' => 'VARCHAR(64) NOT NULL',
                'secret_hash' => 'VARCHAR(255) NOT NULL',
                'scopes' => 'JSON NOT NULL',
                'reseller_domain' => 'VARCHAR(255) NULL',
                'active' => 'TINYINT(1) NOT NULL DEFAULT 1',
                'activated_at' => 'DATETIME NULL',
                'created_by' => 'INT UNSIGNED NULL',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
                'last_used_at' => 'DATETIME NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'uq_api_key' => [
                    'unique' => true,
                    'fulltext' => false,
                    'columns' => [
                        'api_key',
                    ],
                ],
                'idx_active' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'active',
                    ],
                ],
                'idx_client' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'client_id',
                    ],
                ],
            ],
            'foreign' => [
                'fk_api_credentials_client' => [
                    'column' => 'client_id',
                    'references' => 'clients',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'backup_runs' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'status' => 'ENUM(\'running\', \'success\', \'failed\') NOT NULL DEFAULT \'running\'',
                'file_path' => 'VARCHAR(500) NULL',
                'size_bytes' => 'BIGINT UNSIGNED NULL',
                'error' => 'TEXT NULL',
                'started_at' => 'DATETIME NOT NULL',
                'finished_at' => 'DATETIME NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'idx_status' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'status',
                    ],
                ],
            ],
            'foreign' => [],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'billable_items' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'client_id' => 'INT UNSIGNED NOT NULL',
                'description' => 'VARCHAR(255) NOT NULL',
                'amount' => 'DECIMAL(18,6) NOT NULL',
                'invoice_id' => 'INT UNSIGNED NULL',
                'status' => 'ENUM(\'pending\', \'invoiced\', \'cancelled\') NOT NULL DEFAULT \'pending\'',
                'cancelled_at' => 'DATETIME NULL',
                'cancelled_by' => 'ENUM(\'admin\', \'client\') NULL',
                'cancelled_reason' => 'VARCHAR(255) NULL',
                'source_type' => 'VARCHAR(50) NULL',
                'source_id' => 'INT UNSIGNED NULL',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'idx_client' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'client_id',
                    ],
                ],
                'fk_billable_items_invoice' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'invoice_id',
                    ],
                ],
            ],
            'foreign' => [
                'fk_billable_items_client' => [
                    'column' => 'client_id',
                    'references' => 'clients',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
                'fk_billable_items_invoice' => [
                    'column' => 'invoice_id',
                    'references' => 'invoices',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'blocked_email_senders' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'pattern' => 'VARCHAR(191) NOT NULL',
                'reason' => 'VARCHAR(255) NULL',
                'created_by' => 'INT UNSIGNED NULL',
                'source_ticket_id' => 'INT UNSIGNED NULL',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'uq_blocked_email_senders_pattern' => [
                    'unique' => true,
                    'fulltext' => false,
                    'columns' => [
                        'pattern',
                    ],
                ],
            ],
            'foreign' => [],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'cancellation_requests' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'service_id' => 'INT UNSIGNED NOT NULL',
                'client_id' => 'INT UNSIGNED NULL',
                'cancellation_type' => 'ENUM(\'immediate\', \'due_date\') NOT NULL DEFAULT \'immediate\'',
                'cancel_date' => 'DATE NULL',
                'type' => 'ENUM(\'immediate\', \'end_of_period\') NULL',
                'reason' => 'TEXT NULL',
                'admin_notes' => 'TEXT NULL',
                'status' => 'ENUM(\'pending\', \'approved\', \'rejected\', \'completed\') NOT NULL DEFAULT \'pending\'',
                'reviewed_by' => 'INT UNSIGNED NULL',
                'reviewed_at' => 'DATETIME NULL',
                'completed_at' => 'DATETIME NULL',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'idx_cancellation_requests_service' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'service_id',
                    ],
                ],
                'idx_cancellation_requests_client' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'client_id',
                    ],
                ],
                'idx_cancellation_requests_status' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'status',
                    ],
                ],
                'idx_cancellation_requests_cancel_date' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'cancel_date',
                    ],
                ],
            ],
            'foreign' => [
                'fk_cancellation_requests_service' => [
                    'column' => 'service_id',
                    'references' => 'services',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'canned_replies' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'title' => 'VARCHAR(191) NOT NULL',
                'body' => 'TEXT NOT NULL',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [],
            'foreign' => [],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'client_contacts' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'client_id' => 'INT UNSIGNED NOT NULL',
                'name' => 'VARCHAR(191) NOT NULL',
                'email' => 'VARCHAR(191) NOT NULL',
                'company_name' => 'VARCHAR(191) NULL',
                'address1' => 'VARCHAR(191) NULL',
                'city' => 'VARCHAR(100) NULL',
                'state' => 'VARCHAR(100) NULL',
                'postcode' => 'VARCHAR(20) NULL',
                'country' => 'CHAR(2) NULL',
                'phone' => 'VARCHAR(40) NULL',
                'permissions' => 'JSON NULL',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'idx_client' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'client_id',
                    ],
                ],
            ],
            'foreign' => [
                'fk_client_contacts_client' => [
                    'column' => 'client_id',
                    'references' => 'clients',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'client_credit_ledger' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'client_id' => 'INT UNSIGNED NOT NULL',
                'amount' => 'DECIMAL(18,6) NOT NULL',
                'reason' => 'VARCHAR(255) NOT NULL',
                'invoice_id' => 'INT UNSIGNED NULL',
                'credit_note_id' => 'INT UNSIGNED NULL',
                'admin_id' => 'INT UNSIGNED NULL',
                'created_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'idx_client' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'client_id',
                    ],
                ],
                'fk_credit_ledger_invoice' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'invoice_id',
                    ],
                ],
                'fk_ledger_credit_note' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'credit_note_id',
                    ],
                ],
            ],
            'foreign' => [
                'fk_credit_ledger_client' => [
                    'column' => 'client_id',
                    'references' => 'clients',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
                'fk_credit_ledger_invoice' => [
                    'column' => 'invoice_id',
                    'references' => 'invoices',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
                'fk_ledger_credit_note' => [
                    'column' => 'credit_note_id',
                    'references' => 'credit_notes',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'client_custom_field_values' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'client_id' => 'INT UNSIGNED NOT NULL',
                'custom_field_id' => 'INT UNSIGNED NOT NULL',
                'value' => 'TEXT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'uq_client_field' => [
                    'unique' => true,
                    'fulltext' => false,
                    'columns' => [
                        'client_id',
                        'custom_field_id',
                    ],
                ],
                'fk_ccfv_field' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'custom_field_id',
                    ],
                ],
            ],
            'foreign' => [
                'fk_ccfv_client' => [
                    'column' => 'client_id',
                    'references' => 'clients',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
                'fk_ccfv_field' => [
                    'column' => 'custom_field_id',
                    'references' => 'custom_fields',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'client_email_validations' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'client_id' => 'INT UNSIGNED NOT NULL',
                'email' => 'VARCHAR(191) NOT NULL',
                'is_valid' => 'TINYINT(1) NOT NULL',
                'reason' => 'VARCHAR(255) NULL',
                'recent_failures' => 'INT UNSIGNED NOT NULL DEFAULT 0',
                'checked_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'uniq_client' => [
                    'unique' => true,
                    'fulltext' => false,
                    'columns' => [
                        'client_id',
                    ],
                ],
            ],
            'foreign' => [
                'fk_client_email_validations_client' => [
                    'column' => 'client_id',
                    'references' => 'clients',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'client_groups' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'name' => 'VARCHAR(191) NOT NULL',
                'discount_percent' => 'DECIMAL(5,2) NOT NULL DEFAULT 0.00',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [],
            'foreign' => [],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'client_impersonation_tokens' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'token_hash' => 'CHAR(64) NOT NULL',
                'client_id' => 'INT UNSIGNED NOT NULL',
                'actor_type' => 'ENUM(\'admin\',\'reseller\') NOT NULL',
                'actor_id' => 'INT UNSIGNED NOT NULL',
                'actor_label' => 'VARCHAR(191) NOT NULL',
                'site_reseller_id' => 'INT UNSIGNED NULL',
                'return_url' => 'VARCHAR(500) NOT NULL',
                'ip_address' => 'VARCHAR(45) NULL',
                'expires_at' => 'DATETIME NOT NULL',
                'used_at' => 'DATETIME NULL',
                'created_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'uniq_client_impersonation_token' => [
                    'unique' => true,
                    'fulltext' => false,
                    'columns' => [
                        'token_hash',
                    ],
                ],
                'idx_client_impersonation_client' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'client_id',
                        'created_at',
                    ],
                ],
            ],
            'foreign' => [
                'fk_client_impersonation_client' => [
                    'column' => 'client_id',
                    'references' => 'clients',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'client_migrations' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'client_id' => 'INT UNSIGNED NULL',
                'client_email' => 'VARCHAR(191) NOT NULL',
                'from_reseller_id' => 'INT UNSIGNED NULL',
                'from_label' => 'VARCHAR(191) NULL',
                'target' => 'ENUM(\'platform\',\'store\') NOT NULL',
                'to_reseller_id' => 'INT UNSIGNED NULL',
                'target_label' => 'VARCHAR(191) NULL',
                'target_input' => 'VARCHAR(255) NULL',
                'requested_by' => 'ENUM(\'client\',\'reseller\',\'admin\') NOT NULL',
                'requester_client_id' => 'INT UNSIGNED NULL',
                'ticket_id' => 'INT UNSIGNED NULL',
                'reason' => 'TEXT NULL',
                'status' => 'ENUM(\'pending\',\'completed\',\'rejected\',\'cancelled\') NOT NULL DEFAULT \'pending\'',
                'admin_id' => 'INT UNSIGNED NULL',
                'decision_note' => 'TEXT NULL',
                'summary' => 'TEXT NULL',
                'decided_at' => 'DATETIME NULL',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'idx_client_migrations_status' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'status',
                        'created_at',
                    ],
                ],
                'idx_client_migrations_client' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'client_id',
                    ],
                ],
                'idx_client_migrations_requester' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'requester_client_id',
                    ],
                ],
                'fk_client_migrations_from' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'from_reseller_id',
                    ],
                ],
                'fk_client_migrations_to' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'to_reseller_id',
                    ],
                ],
                'fk_client_migrations_ticket' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'ticket_id',
                    ],
                ],
            ],
            'foreign' => [
                'fk_client_migrations_client' => [
                    'column' => 'client_id',
                    'references' => 'clients',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
                'fk_client_migrations_from' => [
                    'column' => 'from_reseller_id',
                    'references' => 'resellers',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
                'fk_client_migrations_to' => [
                    'column' => 'to_reseller_id',
                    'references' => 'resellers',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
                'fk_client_migrations_ticket' => [
                    'column' => 'ticket_id',
                    'references' => 'tickets',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'client_payment_methods' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'client_id' => 'INT UNSIGNED NOT NULL',
                'gateway_slug' => 'VARCHAR(64) NOT NULL',
                'token' => 'VARCHAR(255) NOT NULL',
                'card_brand' => 'VARCHAR(40) NULL',
                'card_last4' => 'VARCHAR(4) NULL',
                'card_exp_month' => 'VARCHAR(2) NULL',
                'card_exp_year' => 'VARCHAR(4) NULL',
                'is_default' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'status' => 'ENUM(\'active\', \'inactive\') NOT NULL DEFAULT \'active\'',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'idx_client' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'client_id',
                    ],
                ],
                'uniq_client_gateway_token' => [
                    'unique' => true,
                    'fulltext' => false,
                    'columns' => [
                        'client_id',
                        'gateway_slug',
                        'token',
                    ],
                ],
            ],
            'foreign' => [
                'fk_cpm_client' => [
                    'column' => 'client_id',
                    'references' => 'clients',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'client_registration_otps' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'email' => 'VARCHAR(191) NOT NULL',
                'code_hash' => 'VARCHAR(255) NOT NULL',
                'attempts' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
                'ip_address' => 'VARCHAR(45) NULL',
                'expires_at' => 'DATETIME NOT NULL',
                'created_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'idx_email' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'email',
                    ],
                ],
                'idx_otps_ip' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'ip_address',
                    ],
                ],
            ],
            'foreign' => [],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'client_security_answers' => [
            'columns' => [
                'client_id' => 'INT UNSIGNED NOT NULL',
                'module_slug' => 'VARCHAR(64) NOT NULL',
                'answer_hash' => 'VARCHAR(255) NOT NULL',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'client_id',
            ],
            'indexes' => [],
            'foreign' => [
                'fk_client_security_answers_client' => [
                    'column' => 'client_id',
                    'references' => 'clients',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'clients' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'client_group_id' => 'INT UNSIGNED NULL',
                'reseller_id' => 'INT UNSIGNED NULL',
                'registrar_client_id' => 'VARCHAR(100) NULL',
                'currency_id' => 'INT UNSIGNED NULL',
                'language_id' => 'INT UNSIGNED NULL',
                'email' => 'VARCHAR(191) NOT NULL',
                'password_hash' => 'VARCHAR(255) NOT NULL',
                'security_pin_hash' => 'VARCHAR(255) NULL',
                'two_factor_secret' => 'VARCHAR(32) NULL',
                'two_factor_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'two_factor_recovery_codes' => 'TEXT NULL',
                'first_name' => 'VARCHAR(100) NOT NULL',
                'last_name' => 'VARCHAR(100) NOT NULL',
                'company_name' => 'VARCHAR(191) NULL',
                'address1' => 'VARCHAR(191) NULL',
                'address2' => 'VARCHAR(191) NULL',
                'city' => 'VARCHAR(100) NULL',
                'state' => 'VARCHAR(100) NULL',
                'postcode' => 'VARCHAR(20) NULL',
                'country' => 'CHAR(2) NULL',
                'vat_number' => 'VARCHAR(32) NULL',
                'vat_verified_at' => 'DATETIME NULL',
                'vat_verified_valid' => 'TINYINT(1) NULL',
                'vat_verified_name' => 'VARCHAR(255) NULL',
                'tax_exempt' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'phone' => 'VARCHAR(40) NULL',
                'status' => 'ENUM(\'active\', \'inactive\', \'closed\') NOT NULL DEFAULT \'active\'',
                'notes' => 'TEXT NULL',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'email' => [
                    'unique' => true,
                    'fulltext' => false,
                    'columns' => [
                        'email',
                    ],
                ],
                'idx_status' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'status',
                    ],
                ],
                'fk_clients_group' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'client_group_id',
                    ],
                ],
                'fk_clients_currency' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'currency_id',
                    ],
                ],
                'fk_clients_language' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'language_id',
                    ],
                ],
                'fk_clients_reseller' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'reseller_id',
                    ],
                ],
            ],
            'foreign' => [
                'fk_clients_group' => [
                    'column' => 'client_group_id',
                    'references' => 'client_groups',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
                'fk_clients_currency' => [
                    'column' => 'currency_id',
                    'references' => 'currencies',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
                'fk_clients_language' => [
                    'column' => 'language_id',
                    'references' => 'languages',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
                'fk_clients_reseller' => [
                    'column' => 'reseller_id',
                    'references' => 'resellers',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'cloudflare_activity' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'zone_id' => 'INT UNSIGNED NOT NULL',
                'actor_type' => 'VARCHAR(10) NOT NULL',
                'actor_id' => 'INT UNSIGNED NULL',
                'action' => 'VARCHAR(60) NOT NULL',
                'summary' => 'VARCHAR(255) NOT NULL',
                'created_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'idx_cfa_zone' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'zone_id',
                        'id',
                    ],
                ],
            ],
            'foreign' => [],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'cloudflare_email_destinations' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'client_id' => 'INT UNSIGNED NOT NULL',
                'reseller_id' => 'INT UNSIGNED NULL',
                'cf_destination_id' => 'VARCHAR(64) NULL',
                'email' => 'VARCHAR(191) NOT NULL',
                'verified_at' => 'DATETIME NULL',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'uniq_cf_email_destination_email' => [
                    'unique' => true,
                    'fulltext' => false,
                    'columns' => [
                        'email',
                    ],
                ],
                'uniq_cf_email_destination_remote' => [
                    'unique' => true,
                    'fulltext' => false,
                    'columns' => [
                        'cf_destination_id',
                    ],
                ],
                'idx_cf_email_destination_owner' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'client_id',
                        'reseller_id',
                    ],
                ],
            ],
            'foreign' => [],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'cloudflare_email_routes' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'zone_id' => 'INT UNSIGNED NOT NULL',
                'client_id' => 'INT UNSIGNED NOT NULL',
                'reseller_id' => 'INT UNSIGNED NULL',
                'cf_rule_id' => 'VARCHAR(64) NOT NULL',
                'local_part' => 'VARCHAR(64) NOT NULL',
                'destination_id' => 'INT UNSIGNED NOT NULL',
                'enabled' => 'TINYINT(1) NOT NULL DEFAULT 1',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'uniq_cf_email_route_remote' => [
                    'unique' => true,
                    'fulltext' => false,
                    'columns' => [
                        'zone_id',
                        'cf_rule_id',
                    ],
                ],
                'uniq_cf_email_route_alias' => [
                    'unique' => true,
                    'fulltext' => false,
                    'columns' => [
                        'zone_id',
                        'local_part',
                    ],
                ],
                'idx_cf_email_route_owner' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'client_id',
                        'reseller_id',
                    ],
                ],
                'idx_cf_email_route_destination' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'destination_id',
                    ],
                ],
            ],
            'foreign' => [
                'fk_cf_email_route_destination' => [
                    'column' => 'destination_id',
                    'references' => 'cloudflare_email_destinations',
                    'referenced_column' => 'id',
                    'on_delete' => 'RESTRICT',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'cloudflare_zones' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'service_id' => 'INT UNSIGNED NULL',
                'client_id' => 'INT UNSIGNED NOT NULL',
                'reseller_id' => 'INT UNSIGNED NULL',
                'cf_zone_id' => 'VARCHAR(32) NOT NULL',
                'name' => 'VARCHAR(253) NOT NULL',
                'status' => 'VARCHAR(20) NOT NULL DEFAULT \'pending\'',
                'name_servers' => 'TEXT NULL',
                'original_name_servers' => 'TEXT NULL',
                'ns_switched_by_us' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'paused' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'paused_by_us' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'delete_after' => 'DATETIME NULL',
                'delete_reason' => 'VARCHAR(40) NULL',
                'backup_bind' => 'MEDIUMTEXT NULL',
                'last_error' => 'VARCHAR(255) NULL',
                'reminders_sent' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
                'activated_at' => 'DATETIME NULL',
                'last_checked_at' => 'DATETIME NULL',
                'last_synced_at' => 'DATETIME NULL',
                'deleted_at' => 'DATETIME NULL',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
                'dnssec_status' => 'VARCHAR(20) NULL',
                'dnssec_ds' => 'TEXT NULL',
                'dnssec_ds_by_us' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'dnssec_ds_removed' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'ns_restore_after' => 'DATETIME NULL',
                'dnssec_disable_after' => 'DATETIME NULL',
                'origin_cert_id' => 'VARCHAR(64) NULL',
                'origin_cert_expires' => 'DATETIME NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'uniq_cf_zone' => [
                    'unique' => true,
                    'fulltext' => false,
                    'columns' => [
                        'cf_zone_id',
                    ],
                ],
                'idx_cf_service' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'service_id',
                    ],
                ],
                'idx_cf_client' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'client_id',
                    ],
                ],
                'idx_cf_status' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'status',
                    ],
                ],
                'idx_cf_delete' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'delete_after',
                    ],
                ],
            ],
            'foreign' => [],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'configurable_option_groups' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'name' => 'VARCHAR(191) NOT NULL',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [],
            'foreign' => [],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'configurable_option_pricing' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'option_id' => 'INT UNSIGNED NOT NULL',
                'billing_cycle' => 'ENUM(\'one_time\', \'monthly\', \'quarterly\', \'semi_annually\', \'annually\', \'biennially\', \'triennially\') NOT NULL',
                'price' => 'DECIMAL(18,6) NOT NULL DEFAULT 0.00',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'uq_option_cycle' => [
                    'unique' => true,
                    'fulltext' => false,
                    'columns' => [
                        'option_id',
                        'billing_cycle',
                    ],
                ],
            ],
            'foreign' => [
                'fk_configurable_option_pricing_option' => [
                    'column' => 'option_id',
                    'references' => 'configurable_options',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'configurable_options' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'option_group_id' => 'INT UNSIGNED NOT NULL',
                'name' => 'VARCHAR(191) NOT NULL',
                'sort_order' => 'INT UNSIGNED NOT NULL DEFAULT 0',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'idx_group' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'option_group_id',
                    ],
                ],
            ],
            'foreign' => [
                'fk_configurable_options_group' => [
                    'column' => 'option_group_id',
                    'references' => 'configurable_option_groups',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'credit_note_items' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'credit_note_id' => 'INT UNSIGNED NOT NULL',
                'description' => 'VARCHAR(255) NOT NULL',
                'amount' => 'DECIMAL(18,6) NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'idx_credit_note' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'credit_note_id',
                    ],
                ],
            ],
            'foreign' => [
                'credit_note_items_ibfk_1' => [
                    'column' => 'credit_note_id',
                    'references' => 'credit_notes',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'credit_notes' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'client_id' => 'INT UNSIGNED NOT NULL',
                'invoice_id' => 'INT UNSIGNED NULL',
                'reason' => 'VARCHAR(255) NOT NULL',
                'total' => 'DECIMAL(18,6) NOT NULL',
                'currency_id' => 'INT UNSIGNED NULL',
                'currency_rate' => 'DECIMAL(18,8) NOT NULL DEFAULT 1.0000',
                'created_by_admin_id' => 'INT UNSIGNED NULL',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'idx_client' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'client_id',
                    ],
                ],
                'idx_invoice' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'invoice_id',
                    ],
                ],
                'credit_notes_ibfk_3' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'currency_id',
                    ],
                ],
            ],
            'foreign' => [
                'credit_notes_ibfk_1' => [
                    'column' => 'client_id',
                    'references' => 'clients',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
                'credit_notes_ibfk_2' => [
                    'column' => 'invoice_id',
                    'references' => 'invoices',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
                'credit_notes_ibfk_3' => [
                    'column' => 'currency_id',
                    'references' => 'currencies',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'cron_job_runs' => [
            'columns' => [
                'id' => 'BIGINT UNSIGNED AUTO_INCREMENT',
                'job_name' => 'VARCHAR(191) NOT NULL',
                'status' => 'ENUM(\'success\', \'error\') NOT NULL DEFAULT \'success\'',
                'error_message' => 'TEXT NULL',
                'stats' => 'TEXT NULL',
                'duration_ms' => 'INT UNSIGNED NOT NULL DEFAULT 0',
                'ran_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'idx_cron_job_runs_ran_at' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'ran_at',
                    ],
                ],
                'idx_cron_job_runs_job_ran' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'job_name',
                        'ran_at',
                    ],
                ],
            ],
            'foreign' => [],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'cron_job_state' => [
            'columns' => [
                'job_name' => 'VARCHAR(191) NOT NULL',
                'last_run_at' => 'INT UNSIGNED NOT NULL DEFAULT 0',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'job_name',
            ],
            'indexes' => [],
            'foreign' => [],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'currencies' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'code' => 'CHAR(3) NOT NULL',
                'symbol' => 'VARCHAR(5) NOT NULL',
                'exchange_rate' => 'DECIMAL(18,8) NOT NULL DEFAULT 1.0000',
                'is_default' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'is_pricing' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'code' => [
                    'unique' => true,
                    'fulltext' => false,
                    'columns' => [
                        'code',
                    ],
                ],
            ],
            'foreign' => [],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'custom_fields' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'field_for' => 'ENUM(\'client\', \'product\') NOT NULL DEFAULT \'client\'',
                'product_id' => 'INT UNSIGNED NULL',
                'name' => 'VARCHAR(191) NOT NULL',
                'type' => 'ENUM(\'text\', \'textarea\', \'dropdown\', \'checkbox\', \'password\') NOT NULL DEFAULT \'text\'',
                'options' => 'TEXT NULL',
                'required' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'admin_only' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'sort_order' => 'INT UNSIGNED NOT NULL DEFAULT 0',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'idx_field_for' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'field_for',
                    ],
                ],
                'fk_custom_fields_product' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'product_id',
                    ],
                ],
            ],
            'foreign' => [
                'fk_custom_fields_product' => [
                    'column' => 'product_id',
                    'references' => 'products',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'departments' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'name' => 'VARCHAR(191) NOT NULL',
                'email' => 'VARCHAR(191) NULL',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [],
            'foreign' => [],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'domain_child_nameservers' => [
            'columns' => [
                'id' => 'INT AUTO_INCREMENT',
                'domain_id' => 'INT NOT NULL',
                'hostname' => 'VARCHAR(255) NOT NULL',
                'ip_address' => 'VARCHAR(45) NOT NULL',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'idx_child_ns_domain_id' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'domain_id',
                    ],
                ],
            ],
            'foreign' => [],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'domain_dns_records' => [
            'columns' => [
                'id' => 'INT AUTO_INCREMENT',
                'domain_id' => 'INT NOT NULL',
                'type' => 'VARCHAR(10) NOT NULL DEFAULT \'A\'',
                'name' => 'VARCHAR(255) NOT NULL DEFAULT \'@\'',
                'content' => 'TEXT NOT NULL',
                'priority' => 'INT NULL DEFAULT 10',
                'ttl' => 'INT NOT NULL DEFAULT 3600',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'idx_domain_id' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'domain_id',
                    ],
                ],
            ],
            'foreign' => [],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'domain_pricing' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'tld' => 'VARCHAR(30) NOT NULL',
                'category' => 'VARCHAR(40) NOT NULL DEFAULT \'Popular\'',
                'sort_order' => 'INT UNSIGNED NOT NULL DEFAULT 0',
                'autosetup_registration' => 'ENUM(\'order\', \'payment\', \'on_accept\', \'off\') NOT NULL DEFAULT \'payment\'',
                'autosetup_transfer' => 'ENUM(\'order\', \'payment\', \'on_accept\', \'off\') NOT NULL DEFAULT \'payment\'',
                'registrar_slug' => 'VARCHAR(50) NOT NULL',
                'register_price' => 'DECIMAL(18,6) NOT NULL DEFAULT 0.00',
                'transfer_price' => 'DECIMAL(18,6) NOT NULL DEFAULT 0.00',
                'renew_price' => 'DECIMAL(18,6) NOT NULL DEFAULT 0.00',
                'spinner_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'grace_period_days' => 'INT UNSIGNED DEFAULT 30',
                'redemption_period_days' => 'INT UNSIGNED DEFAULT 30',
                'redemption_fee' => 'DECIMAL(18,6) NULL DEFAULT 0.00',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'tld' => [
                    'unique' => true,
                    'fulltext' => false,
                    'columns' => [
                        'tld',
                    ],
                ],
            ],
            'foreign' => [],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'domains' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'client_id' => 'INT UNSIGNED NOT NULL',
                'order_id' => 'INT UNSIGNED NULL',
                'domain_name' => 'VARCHAR(255) NOT NULL',
                'tld' => 'VARCHAR(30) NOT NULL',
                'registrar_slug' => 'VARCHAR(50) NOT NULL',
                'registrar_domain_id' => 'VARCHAR(100) NULL',
                'registrar_contact_id' => 'VARCHAR(100) NULL',
                'contact_id' => 'INT UNSIGNED NULL',
                'contact_data' => 'LONGTEXT NULL',
                'status' => 'ENUM(\'pending\', \'active\', \'expired\', \'cancelled\', \'transferred_away\', \'grace\', \'redemption\') NOT NULL DEFAULT \'pending\'',
                'registration_date' => 'DATE NULL',
                'expiry_date' => 'DATE NULL',
                'next_due_date' => 'DATE NULL',
                'auto_renew' => 'TINYINT(1) NOT NULL DEFAULT 1',
                'amount' => 'DECIMAL(18,6) NOT NULL DEFAULT 0.00',
                'id_protection_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'registrar_lock_enabled' => 'TINYINT(1) NOT NULL DEFAULT 1',
                'nameservers' => 'JSON NULL',
                'provisioning_error' => 'TEXT NULL',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'domain_name' => [
                    'unique' => true,
                    'fulltext' => false,
                    'columns' => [
                        'domain_name',
                    ],
                ],
                'idx_client' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'client_id',
                    ],
                ],
                'idx_due' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'status',
                        'next_due_date',
                    ],
                ],
                'fk_domains_order' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'order_id',
                    ],
                ],
                'idx_domains_contact_id' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'contact_id',
                    ],
                ],
            ],
            'foreign' => [
                'fk_domains_client' => [
                    'column' => 'client_id',
                    'references' => 'clients',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
                'fk_domains_order' => [
                    'column' => 'order_id',
                    'references' => 'orders',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
                'fk_domains_contact' => [
                    'column' => 'contact_id',
                    'references' => 'client_contacts',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'download_categories' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'name' => 'VARCHAR(191) NOT NULL',
                'sort_order' => 'INT UNSIGNED NOT NULL DEFAULT 0',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [],
            'foreign' => [],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'downloads' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'category_id' => 'INT UNSIGNED NOT NULL',
                'name' => 'VARCHAR(191) NOT NULL',
                'description' => 'TEXT NULL',
                'file_path' => 'VARCHAR(255) NOT NULL',
                'file_size' => 'INT UNSIGNED NULL',
                'download_count' => 'INT UNSIGNED NOT NULL DEFAULT 0',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'idx_category' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'category_id',
                    ],
                ],
            ],
            'foreign' => [
                'fk_downloads_category' => [
                    'column' => 'category_id',
                    'references' => 'download_categories',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'email_log' => [
            'columns' => [
                'id' => 'BIGINT UNSIGNED AUTO_INCREMENT',
                'to_email' => 'VARCHAR(191) NOT NULL',
                'subject' => 'VARCHAR(255) NOT NULL',
                'template_key' => 'VARCHAR(100) NULL',
                'client_id' => 'INT UNSIGNED NULL',
                'status' => 'ENUM(\'queued\', \'sent\', \'failed\', \'suppressed\') NOT NULL DEFAULT \'queued\'',
                'error' => 'TEXT NULL',
                'created_at' => 'DATETIME NOT NULL',
                'sent_at' => 'DATETIME NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'idx_client' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'client_id',
                    ],
                ],
                'idx_status' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'status',
                    ],
                ],
            ],
            'foreign' => [],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'email_templates' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'key' => 'VARCHAR(100) NOT NULL',
                'name' => 'VARCHAR(191) NOT NULL',
                'subject' => 'VARCHAR(255) NOT NULL',
                'body_html' => 'TEXT NOT NULL',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'key' => [
                    'unique' => true,
                    'fulltext' => false,
                    'columns' => [
                        'key',
                    ],
                ],
            ],
            'foreign' => [],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'gdpr_requests' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'client_id' => 'INT UNSIGNED NOT NULL',
                'type' => 'ENUM(\'export\', \'erasure\') NOT NULL',
                'status' => 'ENUM(\'pending\', \'completed\', \'rejected\') NOT NULL DEFAULT \'pending\'',
                'export_data' => 'LONGTEXT NULL',
                'admin_notes' => 'VARCHAR(500) NULL',
                'processed_by_admin_id' => 'INT UNSIGNED NULL',
                'processed_at' => 'DATETIME NULL',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'idx_client' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'client_id',
                    ],
                ],
                'idx_status' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'status',
                    ],
                ],
            ],
            'foreign' => [
                'gdpr_requests_ibfk_1' => [
                    'column' => 'client_id',
                    'references' => 'clients',
                    'referenced_column' => 'id',
                    'on_delete' => NULL,
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'import_runs' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'admin_id' => 'INT UNSIGNED NOT NULL',
                'entity_type' => 'VARCHAR(32) NOT NULL DEFAULT \'clients\'',
                'filename' => 'VARCHAR(255) NOT NULL',
                'total_rows' => 'INT UNSIGNED NOT NULL',
                'imported_count' => 'INT UNSIGNED NOT NULL',
                'skipped_count' => 'INT UNSIGNED NOT NULL',
                'errors' => 'TEXT NULL',
                'created_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'idx_created' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'created_at',
                    ],
                ],
            ],
            'foreign' => [],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'invoice_items' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'invoice_id' => 'INT UNSIGNED NOT NULL',
                'description' => 'VARCHAR(255) NOT NULL',
                'amount' => 'DECIMAL(18,6) NOT NULL',
                'order_id' => 'INT UNSIGNED NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'idx_invoice' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'invoice_id',
                    ],
                ],
                'idx_invoice_items_order' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'order_id',
                    ],
                ],
            ],
            'foreign' => [
                'fk_invoice_items_invoice' => [
                    'column' => 'invoice_id',
                    'references' => 'invoices',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
                'fk_invoice_items_order' => [
                    'column' => 'order_id',
                    'references' => 'orders',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'invoices' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'client_id' => 'INT UNSIGNED NOT NULL',
                'order_id' => 'INT UNSIGNED NULL',
                'service_id' => 'INT UNSIGNED NULL',
                'domain_id' => 'INT UNSIGNED NULL',
                'recurring_invoice_id' => 'INT UNSIGNED NULL',
                'status' => 'ENUM(\'unpaid\', \'paid\', \'cancelled\', \'refunded\') NOT NULL DEFAULT \'unpaid\'',
                'is_cancelled' => 'BOOLEAN DEFAULT 0',
                'cancelled_at' => 'DATETIME NULL',
                'cancellation_reason' => 'TEXT NULL',
                'subtotal' => 'DECIMAL(18,6) NOT NULL DEFAULT 0.00',
                'tax_amount' => 'DECIMAL(18,6) NOT NULL DEFAULT 0.00',
                'discount_amount' => 'DECIMAL(18,6) NOT NULL DEFAULT 0.00',
                'promotion_code' => 'VARCHAR(50) NULL',
                'total' => 'DECIMAL(18,6) NOT NULL DEFAULT 0.00',
                'currency_id' => 'INT UNSIGNED NULL',
                'currency_rate' => 'DECIMAL(18,8) NOT NULL DEFAULT 1.0000',
                'due_date' => 'DATE NOT NULL',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
                'paid_at' => 'DATETIME NULL',
                'parent_invoice_id' => 'INT UNSIGNED NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'idx_client' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'client_id',
                    ],
                ],
                'idx_status' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'status',
                    ],
                ],
                'fk_invoices_order' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'order_id',
                    ],
                ],
                'fk_invoices_service' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'service_id',
                    ],
                ],
                'idx_service_due' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'service_id',
                        'due_date',
                    ],
                ],
                'fk_invoices_domain' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'domain_id',
                    ],
                ],
                'idx_domain_due' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'domain_id',
                        'due_date',
                    ],
                ],
                'fk_invoices_currency' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'currency_id',
                    ],
                ],
                'idx_invoices_recurring' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'recurring_invoice_id',
                    ],
                ],
                'idx_invoices_parent' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'parent_invoice_id',
                    ],
                ],
            ],
            'foreign' => [
                'fk_invoices_client' => [
                    'column' => 'client_id',
                    'references' => 'clients',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
                'fk_invoices_order' => [
                    'column' => 'order_id',
                    'references' => 'orders',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
                'fk_invoices_service' => [
                    'column' => 'service_id',
                    'references' => 'services',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
                'fk_invoices_domain' => [
                    'column' => 'domain_id',
                    'references' => 'domains',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
                'fk_invoices_currency' => [
                    'column' => 'currency_id',
                    'references' => 'currencies',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
                'fk_invoices_recurring' => [
                    'column' => 'recurring_invoice_id',
                    'references' => 'recurring_invoices',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
                'fk_invoices_parent' => [
                    'column' => 'parent_invoice_id',
                    'references' => 'invoices',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'kb_article_images' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'article_id' => 'INT UNSIGNED NOT NULL',
                'source' => 'ENUM(\'upload\', \'ai_generated\') NOT NULL DEFAULT \'upload\'',
                'original_name' => 'VARCHAR(255) NULL',
                'stored_name' => 'VARCHAR(255) NULL',
                'svg_content' => 'MEDIUMTEXT NULL',
                'mime_type' => 'VARCHAR(120) NULL',
                'size_bytes' => 'INT UNSIGNED NULL',
                'caption' => 'VARCHAR(255) NULL',
                'sort_order' => 'INT UNSIGNED NOT NULL DEFAULT 0',
                'created_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'idx_article' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'article_id',
                    ],
                ],
            ],
            'foreign' => [
                'fk_kbimg_article' => [
                    'column' => 'article_id',
                    'references' => 'kb_articles',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'kb_articles' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'category_id' => 'INT UNSIGNED NOT NULL',
                'title' => 'VARCHAR(255) NOT NULL',
                'body' => 'LONGTEXT NOT NULL',
                'views' => 'INT UNSIGNED NOT NULL DEFAULT 0',
                'helpful_count' => 'INT UNSIGNED NOT NULL DEFAULT 0',
                'unhelpful_count' => 'INT UNSIGNED NOT NULL DEFAULT 0',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'idx_category' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'category_id',
                    ],
                ],
            ],
            'foreign' => [
                'fk_kb_articles_category' => [
                    'column' => 'category_id',
                    'references' => 'kb_categories',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'kb_categories' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'name' => 'VARCHAR(191) NOT NULL',
                'description' => 'TEXT NULL',
                'sort_order' => 'INT UNSIGNED NOT NULL DEFAULT 0',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [],
            'foreign' => [],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'languages' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'code' => 'VARCHAR(10) NOT NULL',
                'name' => 'VARCHAR(100) NOT NULL',
                'is_rtl' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'is_default' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'is_active' => 'TINYINT(1) NOT NULL DEFAULT 1',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'code' => [
                    'unique' => true,
                    'fulltext' => false,
                    'columns' => [
                        'code',
                    ],
                ],
            ],
            'foreign' => [],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'mail_campaign_recipients' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'campaign_id' => 'INT UNSIGNED NOT NULL',
                'client_id' => 'INT UNSIGNED NULL',
                'email' => 'VARCHAR(191) NULL',
                'open_token' => 'CHAR(64) NOT NULL',
                'email_log_id' => 'INT UNSIGNED NULL',
                'sent_at' => 'DATETIME NULL',
                'send_error' => 'TEXT NULL',
                'opened_at' => 'DATETIME NULL',
                'created_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'open_token' => [
                    'unique' => true,
                    'fulltext' => false,
                    'columns' => [
                        'open_token',
                    ],
                ],
                'idx_campaign' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'campaign_id',
                    ],
                ],
                'fk_mail_campaign_recipients_client' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'client_id',
                    ],
                ],
            ],
            'foreign' => [
                'fk_mail_campaign_recipients_campaign' => [
                    'column' => 'campaign_id',
                    'references' => 'mail_campaigns',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
                'fk_mail_campaign_recipients_client' => [
                    'column' => 'client_id',
                    'references' => 'clients',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'mail_campaigns' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'subject' => 'VARCHAR(255) NOT NULL',
                'body' => 'TEXT NOT NULL',
                'client_group_id' => 'INT UNSIGNED NULL',
                'client_id' => 'INT UNSIGNED NULL',
                'external_emails' => 'TEXT NULL',
                'only_inactive' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'status' => 'ENUM(\'draft\', \'sending\', \'paused\', \'sent\') NOT NULL DEFAULT \'draft\'',
                'scheduled_at' => 'DATETIME NULL',
                'sent_at' => 'DATETIME NULL',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'fk_mail_campaigns_group' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'client_group_id',
                    ],
                ],
            ],
            'foreign' => [
                'fk_mail_campaigns_group' => [
                    'column' => 'client_group_id',
                    'references' => 'client_groups',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'network_issues' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'title' => 'VARCHAR(255) NOT NULL',
                'message' => 'TEXT NOT NULL',
                'status' => 'ENUM(\'investigating\', \'identified\', \'monitoring\', \'resolved\') NOT NULL DEFAULT \'investigating\'',
                'started_at' => 'DATETIME NOT NULL',
                'resolved_at' => 'DATETIME NULL',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [],
            'foreign' => [],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'notification_endpoints' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'type' => 'VARCHAR(32) NOT NULL',
                'name' => 'VARCHAR(191) NOT NULL',
                'url' => 'VARCHAR(500) NOT NULL',
                'secret' => 'VARCHAR(255) NULL',
                'events' => 'TEXT NOT NULL',
                'is_active' => 'TINYINT(1) NOT NULL DEFAULT 1',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [],
            'foreign' => [],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'notification_recipients' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'notification_id' => 'INT UNSIGNED NOT NULL',
                'client_id' => 'INT UNSIGNED NOT NULL',
                'read_at' => 'DATETIME NULL',
                'reply_ticket_id' => 'INT UNSIGNED NULL',
                'created_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'idx_client_unread' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'client_id',
                        'read_at',
                    ],
                ],
                'idx_notification' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'notification_id',
                    ],
                ],
            ],
            'foreign' => [
                'fk_notification_recipients_notification' => [
                    'column' => 'notification_id',
                    'references' => 'notifications',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
                'fk_notification_recipients_client' => [
                    'column' => 'client_id',
                    'references' => 'clients',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'notifications' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'subject' => 'VARCHAR(255) NOT NULL',
                'body' => 'TEXT NOT NULL',
                'source' => 'ENUM(\'admin\', \'system_email\') NOT NULL DEFAULT \'admin\'',
                'email_log_id' => 'BIGINT UNSIGNED NULL',
                'created_by_admin_id' => 'INT UNSIGNED NULL',
                'created_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'fk_notifications_email_log' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'email_log_id',
                    ],
                ],
                'fk_notifications_admin' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'created_by_admin_id',
                    ],
                ],
            ],
            'foreign' => [
                'fk_notifications_email_log' => [
                    'column' => 'email_log_id',
                    'references' => 'email_log',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
                'fk_notifications_admin' => [
                    'column' => 'created_by_admin_id',
                    'references' => 'admins',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'order_items' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'order_id' => 'INT UNSIGNED NOT NULL',
                'product_id' => 'INT UNSIGNED NOT NULL',
                'product_name' => 'VARCHAR(191) NOT NULL',
                'billing_cycle' => 'ENUM(\'one_time\', \'monthly\', \'quarterly\', \'semi_annually\', \'annually\', \'biennially\', \'triennially\') NOT NULL',
                'quantity' => 'INT UNSIGNED NOT NULL DEFAULT 1',
                'unit_price' => 'DECIMAL(18,6) NOT NULL',
                'cost_price' => 'DECIMAL(10,2) NULL',
                'setup_fee' => 'DECIMAL(18,6) NOT NULL DEFAULT 0.00',
                'configurable_options' => 'JSON NULL',
                'created_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'idx_order' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'order_id',
                    ],
                ],
                'fk_order_items_product' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'product_id',
                    ],
                ],
            ],
            'foreign' => [
                'fk_order_items_order' => [
                    'column' => 'order_id',
                    'references' => 'orders',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
                'fk_order_items_product' => [
                    'column' => 'product_id',
                    'references' => 'products',
                    'referenced_column' => 'id',
                    'on_delete' => NULL,
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'orders' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'client_id' => 'INT UNSIGNED NOT NULL',
                'reseller_id' => 'INT UNSIGNED NULL',
                'status' => 'ENUM(\'pending\', \'active\', \'cancelled\', \'fraud\') NOT NULL DEFAULT \'pending\'',
                'is_cancelled' => 'BOOLEAN DEFAULT 0',
                'no_invoice' => 'BOOLEAN DEFAULT 0',
                'cancelled_at' => 'DATETIME NULL',
                'cancellation_reason' => 'TEXT NULL',
                'total' => 'DECIMAL(18,6) NOT NULL DEFAULT 0.00',
                'cost_total' => 'DECIMAL(10,2) NULL',
                'upline_reseller_id' => 'INT UNSIGNED NULL',
                'upline_cost_total' => 'DECIMAL(10,2) NULL',
                'reseller_cost_invoice_id' => 'INT UNSIGNED NULL',
                'discount_amount' => 'DECIMAL(18,6) NOT NULL DEFAULT 0.00',
                'promotion_code' => 'VARCHAR(50) NULL',
                'currency_id' => 'INT UNSIGNED NULL',
                'currency_rate' => 'DECIMAL(18,8) NOT NULL DEFAULT 1.0000',
                'fraud_score' => 'DECIMAL(5,2) NULL',
                'fraud_reasons' => 'TEXT NULL',
                'fraud_reviewed_by' => 'INT UNSIGNED NULL',
                'fraud_reviewed_at' => 'DATETIME NULL',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'idx_client' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'client_id',
                    ],
                ],
                'idx_status' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'status',
                    ],
                ],
                'fk_orders_currency' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'currency_id',
                    ],
                ],
                'idx_reseller' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'reseller_id',
                    ],
                ],
                'idx_reseller_cost' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'reseller_id',
                        'reseller_cost_invoice_id',
                    ],
                ],
                'fk_orders_reseller_cost_invoice' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'reseller_cost_invoice_id',
                    ],
                ],
                'fk_orders_upline_reseller' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'upline_reseller_id',
                    ],
                ],
            ],
            'foreign' => [
                'fk_orders_client' => [
                    'column' => 'client_id',
                    'references' => 'clients',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
                'fk_orders_currency' => [
                    'column' => 'currency_id',
                    'references' => 'currencies',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
                'fk_orders_reseller' => [
                    'column' => 'reseller_id',
                    'references' => 'resellers',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
                'fk_orders_reseller_cost_invoice' => [
                    'column' => 'reseller_cost_invoice_id',
                    'references' => 'invoices',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
                'fk_orders_upline_reseller' => [
                    'column' => 'upline_reseller_id',
                    'references' => 'resellers',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'password_reset_tokens' => [
            'columns' => [
                'id' => 'BIGINT UNSIGNED AUTO_INCREMENT',
                'account_type' => 'ENUM(\'admin\', \'client\') NOT NULL',
                'account_id' => 'INT UNSIGNED NOT NULL',
                'token_hash' => 'CHAR(64) NOT NULL',
                'expires_at' => 'DATETIME NOT NULL',
                'created_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'idx_account' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'account_type',
                        'account_id',
                    ],
                ],
                'idx_token_hash' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'token_hash',
                    ],
                ],
            ],
            'foreign' => [],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'payment_gateways' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'slug' => 'VARCHAR(50) NOT NULL',
                'name' => 'VARCHAR(191) NOT NULL',
                'config' => 'JSON NULL',
                'is_enabled' => 'TINYINT(1) NOT NULL DEFAULT 1',
                'sort_order' => 'INT UNSIGNED NOT NULL DEFAULT 0',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'slug' => [
                    'unique' => true,
                    'fulltext' => false,
                    'columns' => [
                        'slug',
                    ],
                ],
            ],
            'foreign' => [],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'product_addons' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'parent_product_id' => 'INT UNSIGNED NOT NULL',
                'addon_product_id' => 'INT UNSIGNED NOT NULL',
                'billing_cycle' => 'ENUM(\'one_time\', \'monthly\', \'quarterly\', \'semi_annually\', \'annually\', \'biennially\', \'triennially\') NULL',
                'sort_order' => 'INT UNSIGNED NOT NULL DEFAULT 0',
                'status' => 'ENUM(\'active\', \'hidden\') NOT NULL DEFAULT \'active\'',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'uq_product_addon' => [
                    'unique' => true,
                    'fulltext' => false,
                    'columns' => [
                        'parent_product_id',
                        'addon_product_id',
                        'billing_cycle',
                    ],
                ],
                'fk_addons_addon_product' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'addon_product_id',
                    ],
                ],
            ],
            'foreign' => [
                'fk_addons_parent_product' => [
                    'column' => 'parent_product_id',
                    'references' => 'products',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
                'fk_addons_addon_product' => [
                    'column' => 'addon_product_id',
                    'references' => 'products',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'product_configurable_option_groups' => [
            'columns' => [
                'product_id' => 'INT UNSIGNED NOT NULL',
                'option_group_id' => 'INT UNSIGNED NOT NULL',
            ],
            'primary' => [
                'product_id',
                'option_group_id',
            ],
            'indexes' => [
                'fk_pcog_group' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'option_group_id',
                    ],
                ],
            ],
            'foreign' => [
                'fk_pcog_product' => [
                    'column' => 'product_id',
                    'references' => 'products',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
                'fk_pcog_group' => [
                    'column' => 'option_group_id',
                    'references' => 'configurable_option_groups',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'product_groups' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'name' => 'VARCHAR(191) NOT NULL',
                'description' => 'TEXT NULL',
                'sort_order' => 'INT UNSIGNED NOT NULL DEFAULT 0',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [],
            'foreign' => [],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'product_pricing' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'product_id' => 'INT UNSIGNED NOT NULL',
                'billing_cycle' => 'ENUM(\'one_time\', \'monthly\', \'quarterly\', \'semi_annually\', \'annually\', \'biennially\', \'triennially\') NOT NULL',
                'setup_fee' => 'DECIMAL(18,6) NOT NULL DEFAULT 0.00',
                'price' => 'DECIMAL(18,6) NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'uq_product_cycle' => [
                    'unique' => true,
                    'fulltext' => false,
                    'columns' => [
                        'product_id',
                        'billing_cycle',
                    ],
                ],
            ],
            'foreign' => [
                'fk_product_pricing_product' => [
                    'column' => 'product_id',
                    'references' => 'products',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'products' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'product_group_id' => 'INT UNSIGNED NOT NULL',
                'type' => 'ENUM(\'shared\', \'reseller\', \'vps\', \'dedicated\', \'other\') NOT NULL DEFAULT \'other\'',
                'pay_type' => 'VARCHAR(20) NOT NULL DEFAULT \'paid\'',
                'server_group_id' => 'INT UNSIGNED NULL',
                'whm_package_name' => 'VARCHAR(191) NULL',
                'autosetup' => 'ENUM(\'order\', \'payment\', \'on_accept\', \'off\') NOT NULL DEFAULT \'payment\'',
                'name' => 'VARCHAR(191) NOT NULL',
                'description' => 'TEXT NULL',
                'status' => 'ENUM(\'active\', \'hidden\') NOT NULL DEFAULT \'active\'',
                'is_upsell' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'require_domain' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'free_duration_type' => 'VARCHAR(50) NOT NULL DEFAULT \'lifetime\'',
                'free_duration_days' => 'INT NULL',
                'upsell_pitch' => 'VARCHAR(255) NULL',
                'stock_quantity' => 'INT NULL',
                'sort_order' => 'INT UNSIGNED NOT NULL DEFAULT 0',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'idx_group' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'product_group_id',
                    ],
                ],
                'fk_products_server_group' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'server_group_id',
                    ],
                ],
            ],
            'foreign' => [
                'fk_products_group' => [
                    'column' => 'product_group_id',
                    'references' => 'product_groups',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
                'fk_products_server_group' => [
                    'column' => 'server_group_id',
                    'references' => 'server_groups',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'promo_banners' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'reseller_id' => 'INT UNSIGNED NULL',
                'name' => 'VARCHAR(100) NOT NULL',
                'template' => 'VARCHAR(30) NOT NULL DEFAULT \'sunset\'',
                'eyebrow_text' => 'VARCHAR(120) NULL',
                'headline' => 'VARCHAR(150) NOT NULL',
                'subtext' => 'VARCHAR(300) NULL',
                'coupon_code' => 'VARCHAR(50) NOT NULL',
                'cta_text' => 'VARCHAR(40) NOT NULL DEFAULT \'Apply Now\'',
                'target_pages' => 'VARCHAR(255) NOT NULL DEFAULT \'["all"]\'',
                'status' => 'ENUM(\'active\', \'paused\') NOT NULL DEFAULT \'active\'',
                'starts_at' => 'DATE NULL',
                'expires_at' => 'DATE NULL',
                'impressions' => 'INT UNSIGNED NOT NULL DEFAULT 0',
                'clicks' => 'INT UNSIGNED NOT NULL DEFAULT 0',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'idx_promo_banners_reseller' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'reseller_id',
                        'status',
                    ],
                ],
            ],
            'foreign' => [
                'fk_promo_banners_reseller' => [
                    'column' => 'reseller_id',
                    'references' => 'resellers',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'promotions' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'reseller_id' => 'INT UNSIGNED NULL',
                'code' => 'VARCHAR(50) NOT NULL',
                'type' => 'ENUM(\'percentage\', \'fixed\') NOT NULL DEFAULT \'percentage\'',
                'value' => 'DECIMAL(18,6) NOT NULL',
                'max_redemptions' => 'INT UNSIGNED NULL',
                'redemption_count' => 'INT UNSIGNED NOT NULL DEFAULT 0',
                'min_order_amount' => 'DECIMAL(18,6) NOT NULL DEFAULT 0.00',
                'starts_at' => 'DATE NULL',
                'expires_at' => 'DATE NULL',
                'status' => 'ENUM(\'active\', \'inactive\') NOT NULL DEFAULT \'active\'',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'uniq_promotions_reseller_code' => [
                    'unique' => true,
                    'fulltext' => false,
                    'columns' => [
                        'reseller_id',
                        'code',
                    ],
                ],
                'idx_promotions_code' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'code',
                    ],
                ],
            ],
            'foreign' => [
                'fk_promotions_reseller' => [
                    'column' => 'reseller_id',
                    'references' => 'resellers',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'quote_items' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'quote_id' => 'INT UNSIGNED NOT NULL',
                'description' => 'VARCHAR(255) NOT NULL',
                'amount' => 'DECIMAL(18,6) NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'idx_quote' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'quote_id',
                    ],
                ],
            ],
            'foreign' => [
                'quote_items_ibfk_1' => [
                    'column' => 'quote_id',
                    'references' => 'quotes',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'quotes' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'client_id' => 'INT UNSIGNED NOT NULL',
                'subject' => 'VARCHAR(255) NOT NULL',
                'status' => 'ENUM(\'draft\', \'sent\', \'accepted\', \'declined\', \'expired\') NOT NULL DEFAULT \'draft\'',
                'valid_until' => 'DATE NULL',
                'total' => 'DECIMAL(18,6) NOT NULL DEFAULT 0.00',
                'currency_id' => 'INT UNSIGNED NULL',
                'currency_rate' => 'DECIMAL(18,8) NOT NULL DEFAULT 1.0000',
                'invoice_id' => 'INT UNSIGNED NULL',
                'created_by_admin_id' => 'INT UNSIGNED NULL',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'idx_client' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'client_id',
                    ],
                ],
                'idx_status' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'status',
                    ],
                ],
                'quotes_ibfk_2' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'currency_id',
                    ],
                ],
                'quotes_ibfk_3' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'invoice_id',
                    ],
                ],
            ],
            'foreign' => [
                'quotes_ibfk_1' => [
                    'column' => 'client_id',
                    'references' => 'clients',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
                'quotes_ibfk_2' => [
                    'column' => 'currency_id',
                    'references' => 'currencies',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
                'quotes_ibfk_3' => [
                    'column' => 'invoice_id',
                    'references' => 'invoices',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'recurring_invoices' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'client_id' => 'INT UNSIGNED NOT NULL',
                'currency_id' => 'INT UNSIGNED NULL',
                'currency_rate' => 'DECIMAL(18,8) NOT NULL DEFAULT 1.00000000',
                'billing_cycle' => 'ENUM(\'monthly\', \'quarterly\', \'semi_annually\', \'annually\', \'biennially\', \'triennially\') NOT NULL',
                'items' => 'JSON NOT NULL',
                'amount' => 'DECIMAL(18,6) NOT NULL DEFAULT 0.000000',
                'due_in_days' => 'INT UNSIGNED NOT NULL DEFAULT 0',
                'next_due_date' => 'DATE NOT NULL',
                'last_generated_at' => 'DATETIME NULL',
                'last_invoice_id' => 'INT UNSIGNED NULL',
                'status' => 'ENUM(\'active\', \'paused\', \'cancelled\') NOT NULL DEFAULT \'active\'',
                'created_by_admin_id' => 'INT UNSIGNED NULL',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'idx_recurring_client' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'client_id',
                    ],
                ],
                'idx_recurring_status_due' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'status',
                        'next_due_date',
                    ],
                ],
                'idx_recurring_last_invoice' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'last_invoice_id',
                    ],
                ],
            ],
            'foreign' => [
                'fk_recurring_invoices_client' => [
                    'column' => 'client_id',
                    'references' => 'clients',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'registrars' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'slug' => 'VARCHAR(50) NOT NULL',
                'name' => 'VARCHAR(191) NOT NULL',
                'config' => 'JSON NULL',
                'is_enabled' => 'TINYINT(1) NOT NULL DEFAULT 1',
                'sort_order' => 'INT UNSIGNED NOT NULL DEFAULT 0',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'slug' => [
                    'unique' => true,
                    'fulltext' => false,
                    'columns' => [
                        'slug',
                    ],
                ],
            ],
            'foreign' => [],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'report_modules' => [
            'columns' => [
                'slug' => 'VARCHAR(64) NOT NULL',
                'enabled' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'config' => 'TEXT NULL',
                'activated_at' => 'DATETIME NULL',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'slug',
            ],
            'indexes' => [],
            'foreign' => [],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'reseller_domain_prices' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'reseller_id' => 'INT UNSIGNED NOT NULL',
                'tld' => 'VARCHAR(30) NOT NULL',
                'register_price' => 'DECIMAL(10,2) NULL',
                'transfer_price' => 'DECIMAL(10,2) NULL',
                'renew_price' => 'DECIMAL(10,2) NULL',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'uq_reseller_tld' => [
                    'unique' => true,
                    'fulltext' => false,
                    'columns' => [
                        'reseller_id',
                        'tld',
                    ],
                ],
            ],
            'foreign' => [
                'fk_reseller_domain_prices_store' => [
                    'column' => 'reseller_id',
                    'references' => 'resellers',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'reseller_ledger' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'reseller_id' => 'INT UNSIGNED NOT NULL',
                'client_id' => 'INT UNSIGNED NULL',
                'kind' => 'ENUM(\'store_receipt\',\'cost_invoice\',\'payout\',\'adjustment\',\'receipt_reversal\',\'cost_reversal\',\'upline_margin\',\'upline_margin_reversal\') NOT NULL',
                'amount' => 'DECIMAL(18,6) NOT NULL COMMENT \'BASE currency, signed: + owed to the reseller, - owed by them\'',
                'withdrawable_at' => 'DATETIME NULL COMMENT \'NULL = immediate; receipts carry payment date + holding period\'',
                'order_id' => 'INT UNSIGNED NULL',
                'invoice_id' => 'INT UNSIGNED NULL',
                'payout_id' => 'INT UNSIGNED NULL',
                'description' => 'VARCHAR(255) NULL',
                'admin_id' => 'INT UNSIGNED NULL',
                'created_at' => 'DATETIME NOT NULL',
                'forward_flag' => 'TINYINT AS (IF(kind IN (\'store_receipt\',\'cost_invoice\'), 1, NULL)) STORED',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'idx_reseller_ledger_account' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'reseller_id',
                        'created_at',
                    ],
                ],
                'idx_reseller_ledger_order' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'order_id',
                    ],
                ],
                'idx_reseller_ledger_withdrawable' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'reseller_id',
                        'withdrawable_at',
                    ],
                ],
                'fk_reseller_ledger_invoice' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'invoice_id',
                    ],
                ],
                'uq_reseller_ledger_forward' => [
                    'unique' => true,
                    'fulltext' => false,
                    'columns' => [
                        'kind',
                        'invoice_id',
                        'forward_flag',
                    ],
                ],
            ],
            'foreign' => [
                'fk_reseller_ledger_store' => [
                    'column' => 'reseller_id',
                    'references' => 'resellers',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
                'fk_reseller_ledger_order' => [
                    'column' => 'order_id',
                    'references' => 'orders',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
                'fk_reseller_ledger_invoice' => [
                    'column' => 'invoice_id',
                    'references' => 'invoices',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'reseller_payout_destinations' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'reseller_id' => 'INT UNSIGNED NOT NULL',
                'client_id' => 'INT UNSIGNED NULL',
                'method' => 'VARCHAR(32) NOT NULL DEFAULT \'bank_transfer\'',
                'account_name' => 'VARCHAR(191) NOT NULL',
                'account_number' => 'VARCHAR(64) NOT NULL',
                'bank_name' => 'VARCHAR(191) NULL',
                'bank_code' => 'VARCHAR(32) NULL',
                'currency_id' => 'INT UNSIGNED NULL COMMENT \'NULL = the base currency\'',
                'verified_at' => 'DATETIME NULL',
                'verified_by' => 'INT UNSIGNED NULL',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'uq_reseller_payout_dest_store' => [
                    'unique' => true,
                    'fulltext' => false,
                    'columns' => [
                        'reseller_id',
                    ],
                ],
                'idx_reseller_payout_dest_client' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'client_id',
                    ],
                ],
                'fk_reseller_payout_dest_admin' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'verified_by',
                    ],
                ],
            ],
            'foreign' => [
                'fk_reseller_payout_dest_store' => [
                    'column' => 'reseller_id',
                    'references' => 'resellers',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
                'fk_reseller_payout_dest_client' => [
                    'column' => 'client_id',
                    'references' => 'clients',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
                'fk_reseller_payout_dest_admin' => [
                    'column' => 'verified_by',
                    'references' => 'admins',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'reseller_payouts' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'reseller_id' => 'INT UNSIGNED NOT NULL',
                'client_id' => 'INT UNSIGNED NULL',
                'amount' => 'DECIMAL(18,6) NOT NULL COMMENT \'reseller currency, exactly as sent\'',
                'currency_id' => 'INT UNSIGNED NULL COMMENT \'NULL = the base currency\'',
                'currency_rate' => 'DECIMAL(18,6) NOT NULL COMMENT \'rate LOCKED at request; never recomputed\'',
                'amount_base' => 'DECIMAL(18,6) NOT NULL COMMENT \'base currency figure the account was debited by\'',
                'status' => 'ENUM(\'pending\',\'paid\',\'rejected\',\'cancelled\') NOT NULL DEFAULT \'pending\'',
                'method' => 'VARCHAR(32) NOT NULL DEFAULT \'bank_transfer\'',
                'reference' => 'VARCHAR(191) NULL COMMENT \'the bank/transfer reference the admin recorded\'',
                'note' => 'TEXT NULL COMMENT \'why it was rejected, or anything the admin needs to record\'',
                'requested_at' => 'DATETIME NOT NULL',
                'decided_at' => 'DATETIME NULL',
                'decided_by' => 'INT UNSIGNED NULL',
                'open_flag' => 'TINYINT AS (IF(status = \'pending\', 1, NULL)) STORED',
                'destination_snapshot' => 'TEXT NULL COMMENT "frozen destination text at request time; never restated"',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'uq_reseller_payouts_open' => [
                    'unique' => true,
                    'fulltext' => false,
                    'columns' => [
                        'reseller_id',
                        'open_flag',
                    ],
                ],
                'idx_reseller_payouts_queue' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'status',
                        'requested_at',
                    ],
                ],
                'idx_reseller_payouts_account' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'reseller_id',
                        'requested_at',
                    ],
                ],
                'fk_reseller_payouts_client' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'client_id',
                    ],
                ],
                'fk_reseller_payouts_admin' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'decided_by',
                    ],
                ],
            ],
            'foreign' => [
                'fk_reseller_payouts_store' => [
                    'column' => 'reseller_id',
                    'references' => 'resellers',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
                'fk_reseller_payouts_client' => [
                    'column' => 'client_id',
                    'references' => 'clients',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
                'fk_reseller_payouts_admin' => [
                    'column' => 'decided_by',
                    'references' => 'admins',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'reseller_prices' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'reseller_id' => 'INT UNSIGNED NOT NULL',
                'product_id' => 'INT UNSIGNED NOT NULL',
                'billing_cycle' => 'ENUM(\'one_time\', \'monthly\', \'quarterly\', \'semi_annually\', \'annually\', \'biennially\', \'triennially\') NOT NULL',
                'price' => 'DECIMAL(10,2) NOT NULL',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'uq_reseller_product_cycle' => [
                    'unique' => true,
                    'fulltext' => false,
                    'columns' => [
                        'reseller_id',
                        'product_id',
                        'billing_cycle',
                    ],
                ],
                'fk_reseller_prices_product' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'product_id',
                    ],
                ],
            ],
            'foreign' => [
                'fk_reseller_prices_store' => [
                    'column' => 'reseller_id',
                    'references' => 'resellers',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
                'fk_reseller_prices_product' => [
                    'column' => 'product_id',
                    'references' => 'products',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'reseller_statements' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'reseller_id' => 'INT UNSIGNED NOT NULL',
                'client_id' => 'INT UNSIGNED NULL',
                'number' => 'VARCHAR(32) NOT NULL COMMENT \'human-facing, e.g. STMT-2026-0001; never reused\'',
                'seq' => 'INT UNSIGNED NOT NULL COMMENT \'per store, per year\'',
                'period_year' => 'SMALLINT UNSIGNED NOT NULL COMMENT \'the year the number is scoped to (the period end)\'',
                'period_from' => 'DATETIME NOT NULL',
                'period_to' => 'DATETIME NOT NULL',
                'opening_base' => 'DECIMAL(18,6) NOT NULL COMMENT \'BASE currency, as issued\'',
                'credits_base' => 'DECIMAL(18,6) NOT NULL',
                'debits_base' => 'DECIMAL(18,6) NOT NULL',
                'closing_base' => 'DECIMAL(18,6) NOT NULL',
                'withdrawable_base' => 'DECIMAL(18,6) NOT NULL COMMENT \'as at the period end\'',
                'entry_count' => 'INT UNSIGNED NOT NULL DEFAULT 0',
                'currency_id' => 'INT UNSIGNED NULL COMMENT \'the reseller currency used for the conversion\'',
                'currency_code' => 'VARCHAR(8) NULL',
                'currency_rate' => 'DECIMAL(18,6) NOT NULL DEFAULT 1.000000 COMMENT \'rate LOCKED at issue; never recomputed\'',
                'closing_converted' => 'DECIMAL(18,6) NOT NULL DEFAULT 0',
                'withdrawable_converted' => 'DECIMAL(18,6) NOT NULL DEFAULT 0',
                'line_items' => 'MEDIUMTEXT NOT NULL COMMENT \'frozen JSON snapshot of the entries (NOT `lines`: a reserved word in MariaDB)\'',
                'our_identity' => 'MEDIUMTEXT NOT NULL COMMENT \'frozen JSON snapshot of our tax identity at issue\'',
                'issued_at' => 'DATETIME NOT NULL',
                'issued_by' => 'INT UNSIGNED NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'uq_reseller_statements_seq' => [
                    'unique' => true,
                    'fulltext' => false,
                    'columns' => [
                        'reseller_id',
                        'period_year',
                        'seq',
                    ],
                ],
                'uq_reseller_statements_number' => [
                    'unique' => true,
                    'fulltext' => false,
                    'columns' => [
                        'reseller_id',
                        'number',
                    ],
                ],
                'uq_reseller_statements_period' => [
                    'unique' => true,
                    'fulltext' => false,
                    'columns' => [
                        'reseller_id',
                        'period_from',
                        'period_to',
                    ],
                ],
                'idx_reseller_statements_store' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'reseller_id',
                        'issued_at',
                    ],
                ],
                'fk_reseller_statements_client' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'client_id',
                    ],
                ],
                'fk_reseller_statements_admin' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'issued_by',
                    ],
                ],
            ],
            'foreign' => [
                'fk_reseller_statements_store' => [
                    'column' => 'reseller_id',
                    'references' => 'resellers',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
                'fk_reseller_statements_client' => [
                    'column' => 'client_id',
                    'references' => 'clients',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
                'fk_reseller_statements_admin' => [
                    'column' => 'issued_by',
                    'references' => 'admins',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'resellers' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'client_id' => 'INT UNSIGNED NOT NULL',
                'slug' => 'VARCHAR(63) NOT NULL',
                'status' => 'ENUM(\'active\', \'suspended\') NOT NULL DEFAULT \'active\'',
                'brand_name' => 'VARCHAR(191) NULL',
                'logo_url' => 'VARCHAR(255) NULL',
                'favicon_url' => 'VARCHAR(255) NULL',
                'primary_color' => 'VARCHAR(7) NULL',
                'markup_percent' => 'DECIMAL(5,2) NOT NULL DEFAULT 0.00',
                'custom_domain' => 'VARCHAR(255) NULL',
                'domain_verification_token' => 'VARCHAR(64) NULL',
                'domain_verification_method' => 'VARCHAR(16) NULL',
                'domain_verified_at' => 'DATETIME NULL',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
                'domain_status' => 'ENUM(\'none\',\'pending\',\'approved\',\'rejected\') NOT NULL DEFAULT \'none\'',
                'domain_requested_at' => 'DATETIME NULL',
                'domain_reviewed_at' => 'DATETIME NULL',
                'domain_reviewed_by' => 'INT UNSIGNED NULL',
                'domain_review_note' => 'TEXT NULL',
                'domain_provisioned_at' => 'DATETIME NULL',
                'domain_provision_error' => 'TEXT NULL',
                'domain_provisioned_host' => 'VARCHAR(255) NULL',
                'support_whatsapp' => 'VARCHAR(32) NULL',
                'tawk_property_id' => 'VARCHAR(64) NULL',
                'tawk_widget_id' => 'VARCHAR(64) NULL',
                'support_email' => 'VARCHAR(190) NULL',
                'support_email_status' => 'VARCHAR(20) NULL',
                'support_email_checked_at' => 'DATETIME NULL',
                'support_email_detail' => 'TEXT NULL',
                'mailbox_host' => 'VARCHAR(255) NULL',
                'mailbox_provisioned_at' => 'DATETIME NULL',
                'mailbox_provision_error' => 'TEXT NULL',
                'chat_configured_at' => 'DATETIME NULL',
                'home_headline' => 'VARCHAR(120) NULL',
                'home_tagline' => 'VARCHAR(300) NULL',
                'google_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'google_client_id' => 'VARCHAR(191) NULL',
                'google_client_secret' => 'TEXT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'uq_reseller_slug' => [
                    'unique' => true,
                    'fulltext' => false,
                    'columns' => [
                        'slug',
                    ],
                ],
                'uq_reseller_domain' => [
                    'unique' => true,
                    'fulltext' => false,
                    'columns' => [
                        'custom_domain',
                    ],
                ],
                'idx_reseller_client' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'client_id',
                    ],
                ],
                'fk_resellers_domain_reviewed_by' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'domain_reviewed_by',
                    ],
                ],
            ],
            'foreign' => [
                'fk_resellers_client' => [
                    'column' => 'client_id',
                    'references' => 'clients',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
                'fk_resellers_domain_reviewed_by' => [
                    'column' => 'domain_reviewed_by',
                    'references' => 'admins',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'role_permissions' => [
            'columns' => [
                'role_id' => 'INT UNSIGNED NOT NULL',
                'permission_key' => 'VARCHAR(100) NOT NULL',
            ],
            'primary' => [
                'role_id',
                'permission_key',
            ],
            'indexes' => [],
            'foreign' => [
                'fk_role_permissions_role' => [
                    'column' => 'role_id',
                    'references' => 'roles',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'roles' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'name' => 'VARCHAR(100) NOT NULL',
                'is_super_admin' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'name' => [
                    'unique' => true,
                    'fulltext' => false,
                    'columns' => [
                        'name',
                    ],
                ],
            ],
            'foreign' => [],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'security_account_locks' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'admin_id' => 'INT UNSIGNED NOT NULL',
                'locked_at' => 'DATETIME NOT NULL',
                'expires_at' => 'DATETIME NULL',
                'reason' => 'VARCHAR(255) NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'idx_admin' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'admin_id',
                    ],
                ],
            ],
            'foreign' => [
                'fk_security_account_locks_admin' => [
                    'column' => 'admin_id',
                    'references' => 'admins',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'security_country_rules' => [
            'columns' => [
                'country_code' => 'CHAR(2) NOT NULL',
                'policy' => 'ENUM(\'whitelisted\', \'not_specified\', \'blacklisted\') NOT NULL DEFAULT \'not_specified\'',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'country_code',
            ],
            'indexes' => [],
            'foreign' => [],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'security_ip_rules' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'ip_address' => 'VARCHAR(45) NOT NULL',
                'policy' => 'ENUM(\'blacklisted\', \'whitelisted\') NULL',
                'tier' => 'ENUM(\'day\', \'week\', \'month\', \'year\') NULL',
                'source' => 'ENUM(\'auto\', \'manual\') NOT NULL DEFAULT \'auto\'',
                'reason' => 'VARCHAR(255) NULL',
                'admin_id' => 'INT UNSIGNED NULL',
                'clean_session_count' => 'INT UNSIGNED NOT NULL DEFAULT 0',
                'block_count' => 'INT UNSIGNED NOT NULL DEFAULT 0',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
                'expires_at' => 'DATETIME NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'ip_address' => [
                    'unique' => true,
                    'fulltext' => false,
                    'columns' => [
                        'ip_address',
                    ],
                ],
                'idx_policy' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'policy',
                    ],
                ],
            ],
            'foreign' => [],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'security_login_attempts' => [
            'columns' => [
                'id' => 'BIGINT UNSIGNED AUTO_INCREMENT',
                'username' => 'VARCHAR(64) NULL',
                'ip_address' => 'VARCHAR(45) NOT NULL',
                'country_code' => 'CHAR(2) NULL',
                'successful' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'user_agent' => 'VARCHAR(255) NULL',
                'created_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'idx_ip_created' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'ip_address',
                        'created_at',
                    ],
                ],
                'idx_username_created' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'username',
                        'created_at',
                    ],
                ],
            ],
            'foreign' => [],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'security_question_modules' => [
            'columns' => [
                'slug' => 'VARCHAR(64) NOT NULL',
                'enabled' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'config' => 'TEXT NULL',
                'activated_at' => 'DATETIME NULL',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'slug',
            ],
            'indexes' => [],
            'foreign' => [],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'server_groups' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'name' => 'VARCHAR(191) NOT NULL',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [],
            'foreign' => [],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'servers' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'server_group_id' => 'INT UNSIGNED NULL',
                'name' => 'VARCHAR(191) NOT NULL',
                'hostname' => 'VARCHAR(191) NOT NULL',
                'module_slug' => 'VARCHAR(50) NOT NULL',
                'api_username' => 'VARCHAR(191) NULL',
                'api_token' => 'VARCHAR(255) NULL',
                'account_secret' => 'TEXT NULL',
                'api_port' => 'INT UNSIGNED NULL',
                'use_ssl' => 'TINYINT(1) NOT NULL DEFAULT 1',
                'active' => 'TINYINT(1) NOT NULL DEFAULT 1',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'idx_group' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'server_group_id',
                    ],
                ],
            ],
            'foreign' => [
                'fk_servers_group' => [
                    'column' => 'server_group_id',
                    'references' => 'server_groups',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'service_custom_field_values' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'service_id' => 'INT UNSIGNED NOT NULL',
                'custom_field_id' => 'INT UNSIGNED NOT NULL',
                'value' => 'TEXT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'uq_service_field' => [
                    'unique' => true,
                    'fulltext' => false,
                    'columns' => [
                        'service_id',
                        'custom_field_id',
                    ],
                ],
                'fk_scfv_field' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'custom_field_id',
                    ],
                ],
            ],
            'foreign' => [
                'fk_scfv_service' => [
                    'column' => 'service_id',
                    'references' => 'services',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
                'fk_scfv_field' => [
                    'column' => 'custom_field_id',
                    'references' => 'custom_fields',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'services' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'client_id' => 'INT UNSIGNED NOT NULL',
                'order_id' => 'INT UNSIGNED NULL',
                'parent_id' => 'INT UNSIGNED NULL',
                'product_id' => 'INT UNSIGNED NOT NULL',
                'server_id' => 'INT UNSIGNED NULL',
                'username' => 'VARCHAR(100) NULL',
                'remote_id' => 'VARCHAR(64) NULL',
                'dedicated_ip' => 'VARCHAR(255) NULL',
                'assigned_ips' => 'TEXT NULL',
                'product_name' => 'VARCHAR(191) NOT NULL',
                'billing_cycle' => 'ENUM(\'one_time\', \'monthly\', \'quarterly\', \'semi_annually\', \'annually\', \'biennially\', \'triennially\') NOT NULL',
                'amount' => 'DECIMAL(18,6) NOT NULL',
                'domain' => 'VARCHAR(255) NULL',
                'hostname' => 'VARCHAR(255) NULL',
                'password' => 'VARCHAR(255) NULL',
                'details_sent_at' => 'DATETIME NULL',
                'status' => 'ENUM(\'pending\', \'active\', \'suspended\', \'cancelled\', \'terminated\') NOT NULL DEFAULT \'pending\'',
                'suspension_reason' => 'VARCHAR(255) NULL',
                'suspended_by_reseller_id' => 'INT UNSIGNED NULL',
                'provisioning_error' => 'TEXT NULL',
                'next_due_date' => 'DATE NOT NULL',
                'renewal_reminded_at' => 'DATETIME NULL',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'idx_client' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'client_id',
                    ],
                ],
                'idx_due' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'status',
                        'next_due_date',
                    ],
                ],
                'fk_services_order' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'order_id',
                    ],
                ],
                'fk_services_product' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'product_id',
                    ],
                ],
                'fk_services_server' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'server_id',
                    ],
                ],
                'idx_services_parent' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'parent_id',
                    ],
                ],
                'idx_services_reseller_hold' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'suspended_by_reseller_id',
                    ],
                ],
                'idx_services_username' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'username',
                    ],
                ],
            ],
            'foreign' => [
                'fk_services_client' => [
                    'column' => 'client_id',
                    'references' => 'clients',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
                'fk_services_order' => [
                    'column' => 'order_id',
                    'references' => 'orders',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
                'fk_services_product' => [
                    'column' => 'product_id',
                    'references' => 'products',
                    'referenced_column' => 'id',
                    'on_delete' => NULL,
                    'on_update' => NULL,
                ],
                'fk_services_server' => [
                    'column' => 'server_id',
                    'references' => 'servers',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
                'fk_services_parent' => [
                    'column' => 'parent_id',
                    'references' => 'services',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'settings' => [
            'columns' => [
                'key' => 'VARCHAR(191) NOT NULL',
                'value' => 'TEXT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'key',
            ],
            'indexes' => [],
            'foreign' => [],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'system_activation' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'domain' => 'VARCHAR(191) NOT NULL',
                'status' => 'ENUM(\'pending\', \'active\', \'grace\', \'suspended\') NOT NULL DEFAULT \'pending\'',
                'last_checked_at' => 'DATETIME NULL',
                'last_valid_at' => 'DATETIME NULL',
                'cached_response' => 'TEXT NULL',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [],
            'foreign' => [],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'tax_rules' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'country_code' => 'CHAR(2) NOT NULL',
                'state' => 'VARCHAR(100) NULL',
                'name' => 'VARCHAR(100) NOT NULL DEFAULT \'Tax\'',
                'rate' => 'DECIMAL(5,2) NOT NULL',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'uq_country_state' => [
                    'unique' => true,
                    'fulltext' => false,
                    'columns' => [
                        'country_code',
                        'state',
                    ],
                ],
            ],
            'foreign' => [],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'ticket_attachments' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'ticket_id' => 'INT UNSIGNED NOT NULL',
                'reply_id' => 'INT UNSIGNED NULL',
                'uploaded_by' => 'ENUM(\'client\', \'admin\') NOT NULL',
                'original_name' => 'VARCHAR(255) NOT NULL',
                'stored_name' => 'VARCHAR(255) NOT NULL',
                'mime_type' => 'VARCHAR(120) NOT NULL',
                'size_bytes' => 'INT UNSIGNED NOT NULL DEFAULT 0',
                'created_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'idx_ticket' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'ticket_id',
                    ],
                ],
                'idx_reply' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'reply_id',
                    ],
                ],
            ],
            'foreign' => [
                'fk_ta_ticket' => [
                    'column' => 'ticket_id',
                    'references' => 'tickets',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'ticket_replies' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'ticket_id' => 'INT UNSIGNED NOT NULL',
                'author_type' => 'ENUM(\'client\',\'admin\',\'reseller\') NOT NULL',
                'author_id' => 'INT UNSIGNED NULL',
                'author_name' => 'VARCHAR(191) NOT NULL',
                'message' => 'TEXT NOT NULL',
                'is_private' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'created_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'idx_ticket' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'ticket_id',
                    ],
                ],
                'idx_replies_ticket_created' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'ticket_id',
                        'created_at',
                    ],
                ],
            ],
            'foreign' => [
                'fk_ticket_replies_ticket' => [
                    'column' => 'ticket_id',
                    'references' => 'tickets',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'tickets' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'client_id' => 'INT UNSIGNED NULL',
                'email' => 'VARCHAR(191) NOT NULL',
                'department_id' => 'INT UNSIGNED NOT NULL',
                'service_id' => 'INT UNSIGNED NULL',
                'domain_id' => 'INT UNSIGNED NULL',
                'subject' => 'VARCHAR(255) NOT NULL',
                'status' => 'ENUM(\'open\', \'answered\', \'customer-reply\', \'closed\') NOT NULL DEFAULT \'open\'',
                'priority' => 'ENUM(\'low\', \'medium\', \'high\') NOT NULL DEFAULT \'medium\'',
                'assigned_admin_id' => 'INT UNSIGNED NULL',
                'merged_into_id' => 'INT UNSIGNED NULL',
                'satisfaction_rating' => 'TINYINT UNSIGNED NULL',
                'last_reply_at' => 'DATETIME NULL',
                'last_reply_by' => 'ENUM(\'client\',\'admin\',\'reseller\') NULL',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
                'reseller_id' => 'INT UNSIGNED NULL',
                'escalated_at' => 'DATETIME NULL',
                'escalated_note' => 'TEXT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'idx_client' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'client_id',
                    ],
                ],
                'idx_status' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'status',
                    ],
                ],
                'idx_department' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'department_id',
                    ],
                ],
                'fk_tickets_assigned_admin' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'assigned_admin_id',
                    ],
                ],
                'fk_tickets_merged_into' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'merged_into_id',
                    ],
                ],
                'idx_merged_into' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'merged_into_id',
                    ],
                ],
                'idx_tickets_email' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'email',
                    ],
                ],
                'idx_tickets_updated_at' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'updated_at',
                    ],
                ],
                'idx_tickets_status_updated' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'status',
                        'updated_at',
                    ],
                ],
                'idx_tickets_last_reply' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'last_reply_at',
                    ],
                ],
                'idx_service' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'service_id',
                    ],
                ],
                'idx_domain' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'domain_id',
                    ],
                ],
                'idx_ticket_reseller' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'reseller_id',
                        'status',
                    ],
                ],
                'idx_ticket_escalated' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'escalated_at',
                    ],
                ],
            ],
            'foreign' => [
                'fk_tickets_client' => [
                    'column' => 'client_id',
                    'references' => 'clients',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
                'fk_tickets_department' => [
                    'column' => 'department_id',
                    'references' => 'departments',
                    'referenced_column' => 'id',
                    'on_delete' => NULL,
                    'on_update' => NULL,
                ],
                'fk_tickets_assigned_admin' => [
                    'column' => 'assigned_admin_id',
                    'references' => 'admins',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
                'fk_tickets_merged_into' => [
                    'column' => 'merged_into_id',
                    'references' => 'tickets',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
                'fk_tickets_service' => [
                    'column' => 'service_id',
                    'references' => 'services',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
                'fk_tickets_domain' => [
                    'column' => 'domain_id',
                    'references' => 'domains',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
                'fk_tickets_reseller' => [
                    'column' => 'reseller_id',
                    'references' => 'resellers',
                    'referenced_column' => 'id',
                    'on_delete' => 'SET NULL',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'transactions' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'invoice_id' => 'INT UNSIGNED NOT NULL',
                'gateway_slug' => 'VARCHAR(50) NOT NULL',
                'amount' => 'DECIMAL(18,6) NOT NULL',
                'status' => 'ENUM(\'completed\', \'refunded\', \'failed\') NOT NULL DEFAULT \'completed\'',
                'gateway_transaction_id' => 'VARCHAR(191) NULL',
                'created_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'idx_invoice' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'invoice_id',
                    ],
                ],
                'uniq_gateway_txn' => [
                    'unique' => true,
                    'fulltext' => false,
                    'columns' => [
                        'gateway_slug',
                        'gateway_transaction_id',
                    ],
                ],
            ],
            'foreign' => [
                'fk_transactions_invoice' => [
                    'column' => 'invoice_id',
                    'references' => 'invoices',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'translation_overrides' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'language_id' => 'INT UNSIGNED NOT NULL',
                'key' => 'VARCHAR(191) NOT NULL',
                'value' => 'TEXT NOT NULL',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'uniq_language_key' => [
                    'unique' => true,
                    'fulltext' => false,
                    'columns' => [
                        'language_id',
                        'key',
                    ],
                ],
            ],
            'foreign' => [
                'fk_translation_overrides_language' => [
                    'column' => 'language_id',
                    'references' => 'languages',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'username_change_events' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'request_id' => 'INT UNSIGNED NOT NULL',
                'event' => 'VARCHAR(32) NOT NULL',
                'actor_type' => 'VARCHAR(10) NOT NULL',
                'actor_id' => 'INT UNSIGNED NULL',
                'ip' => 'VARCHAR(45) NULL',
                'detail' => 'TEXT NULL',
                'created_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'idx_uce_request' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'request_id',
                        'id',
                    ],
                ],
                'idx_uce_created' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'created_at',
                    ],
                ],
            ],
            'foreign' => [
                'fk_uce_request' => [
                    'column' => 'request_id',
                    'references' => 'username_change_requests',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'username_change_policies' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'scope' => 'VARCHAR(8) NOT NULL',
                'scope_id' => 'INT UNSIGNED NOT NULL',
                'enabled' => 'TINYINT(1) NULL',
                'max_changes' => 'SMALLINT UNSIGNED NULL',
                'cooldown_days' => 'SMALLINT UNSIGNED NULL',
                'approval' => 'VARCHAR(10) NULL',
                'allow_db_rename' => 'TINYINT(1) NULL',
                'client_mode' => 'VARCHAR(8) NULL',
                'extra_changes' => 'SMALLINT UNSIGNED NULL',
                'fee' => 'DECIMAL(12,2) NULL',
                'note' => 'VARCHAR(255) NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'uniq_ucp_scope' => [
                    'unique' => true,
                    'fulltext' => false,
                    'columns' => [
                        'scope',
                        'scope_id',
                    ],
                ],
            ],
            'foreign' => [],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'username_change_requests' => [
            'columns' => [
                'id' => 'INT UNSIGNED AUTO_INCREMENT',
                'service_id' => 'INT UNSIGNED NOT NULL',
                'client_id' => 'INT UNSIGNED NOT NULL',
                'reseller_id' => 'INT UNSIGNED NULL',
                'server_id' => 'INT UNSIGNED NULL',
                'old_username' => 'VARCHAR(16) NOT NULL',
                'new_username' => 'VARCHAR(16) NOT NULL',
                'reason' => 'VARCHAR(500) NULL',
                'rename_db_objects' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'status' => 'VARCHAR(24) NOT NULL',
                'confirm_method' => 'VARCHAR(8) NULL',
                'confirm_token_hash' => 'CHAR(64) NULL',
                'confirm_expires_at' => 'DATETIME NULL',
                'confirm_sent_count' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
                'confirm_last_sent_at' => 'DATETIME NULL',
                'pin_attempts' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
                'confirmed_at' => 'DATETIME NULL',
                'decided_by_type' => 'VARCHAR(10) NULL',
                'decided_by_id' => 'INT UNSIGNED NULL',
                'decided_at' => 'DATETIME NULL',
                'decline_reason' => 'VARCHAR(500) NULL',
                'fee_amount' => 'DECIMAL(12,2) NULL',
                'fee_currency_id' => 'INT UNSIGNED NULL',
                'fee_retail' => 'DECIMAL(12,2) NULL',
                'fee_cost' => 'DECIMAL(12,2) NULL',
                'fee_upline_cost' => 'DECIMAL(12,2) NULL',
                'invoice_id' => 'INT UNSIGNED NULL',
                'paid_at' => 'DATETIME NULL',
                'fee_credited_at' => 'DATETIME NULL',
                'fee_reversed_at' => 'DATETIME NULL',
                'attempts' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
                'next_attempt_at' => 'DATETIME NULL',
                'lock_token' => 'CHAR(32) NULL',
                'locked_at' => 'DATETIME NULL',
                'last_error' => 'TEXT NULL',
                'server_response' => 'TEXT NULL',
                'sync_state' => 'VARCHAR(12) NULL',
                'requested_by_type' => 'VARCHAR(10) NOT NULL',
                'requested_by_id' => 'INT UNSIGNED NULL',
                'ip' => 'VARCHAR(45) NULL',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
                'completed_at' => 'DATETIME NULL',
            ],
            'primary' => [
                'id',
            ],
            'indexes' => [
                'uniq_ucr_token' => [
                    'unique' => true,
                    'fulltext' => false,
                    'columns' => [
                        'confirm_token_hash',
                    ],
                ],
                'idx_ucr_status' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'status',
                        'next_attempt_at',
                    ],
                ],
                'idx_ucr_service' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'service_id',
                        'status',
                    ],
                ],
                'idx_ucr_client' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'client_id',
                    ],
                ],
                'idx_ucr_reseller' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'reseller_id',
                        'status',
                    ],
                ],
                'idx_ucr_new' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'new_username',
                        'status',
                    ],
                ],
                'idx_ucr_invoice' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'invoice_id',
                    ],
                ],
            ],
            'foreign' => [
                'fk_ucr_service' => [
                    'column' => 'service_id',
                    'references' => 'services',
                    'referenced_column' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => NULL,
                ],
            ],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'username_change_server_accounts' => [
            'columns' => [
                'server_id' => 'INT UNSIGNED NOT NULL',
                'username' => 'VARCHAR(32) NOT NULL',
                'prefix8' => 'VARCHAR(8) NOT NULL',
                'domain' => 'VARCHAR(191) NULL',
                'synced_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'server_id',
                'username',
            ],
            'indexes' => [
                'idx_ucsa_username' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'username',
                    ],
                ],
                'idx_ucsa_prefix' => [
                    'unique' => false,
                    'fulltext' => false,
                    'columns' => [
                        'server_id',
                        'prefix8',
                    ],
                ],
            ],
            'foreign' => [],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'username_change_servers' => [
            'columns' => [
                'server_id' => 'INT UNSIGNED NOT NULL',
                'db_engine' => 'VARCHAR(10) NULL',
                'db_engine_override' => 'VARCHAR(10) NULL',
                'account_count' => 'INT UNSIGNED NOT NULL DEFAULT 0',
                'accounts_synced_at' => 'DATETIME NULL',
                'last_error' => 'VARCHAR(255) NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'server_id',
            ],
            'indexes' => [],
            'foreign' => [],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'username_change_throttle' => [
            'columns' => [
                'throttle_key' => 'VARCHAR(120) NOT NULL',
                'hits' => 'INT UNSIGNED NOT NULL DEFAULT 0',
                'window_start' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'throttle_key',
            ],
            'indexes' => [],
            'foreign' => [],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
        'widget_modules' => [
            'columns' => [
                'slug' => 'VARCHAR(64) NOT NULL',
                'enabled' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'config' => 'TEXT NULL',
                'activated_at' => 'DATETIME NULL',
                'created_at' => 'DATETIME NOT NULL',
                'updated_at' => 'DATETIME NOT NULL',
            ],
            'primary' => [
                'slug',
            ],
            'indexes' => [],
            'foreign' => [],
            'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
    ],
];
