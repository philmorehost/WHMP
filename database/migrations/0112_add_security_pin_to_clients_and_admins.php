<?php

declare(strict_types=1);

namespace CodeVault\Migrations;

use CodeVault\Database;

return new class {
    public function up(Database $db): void
    {
        $db->transaction(function (Database $db) {
            $db->connection()->exec(
                'ALTER TABLE clients ADD COLUMN security_pin_hash VARCHAR(255) NULL AFTER password_hash'
            );
            $db->connection()->exec(
                'ALTER TABLE admins ADD COLUMN security_pin_hash VARCHAR(255) NULL AFTER password_hash'
            );
        });
    }

    public function down(Database $db): void
    {
        $db->transaction(function (Database $db) {
            $db->connection()->exec('ALTER TABLE clients DROP COLUMN security_pin_hash');
            $db->connection()->exec('ALTER TABLE admins DROP COLUMN security_pin_hash');
        });
    }
};
