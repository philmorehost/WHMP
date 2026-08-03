<?php

declare(strict_types=1);

use CodeVault\Database;

// mail_campaigns.client_id — the "send this campaign to one specific client"
// target, alongside the existing client_group_id.
//
// The column was referenced throughout core/Marketing (the campaign list joins
// clients on it, create() inserts it, MailCampaignService reads it) but no
// migration ever created it, so /admin/marketing died with
// "Unknown column 'c.client_id' in 'on clause'" on every install.
//
// MailCampaignRepository::create() tried to self-heal with an ALTER wrapped in
// an empty catch, but that could never run: the campaign list page calls all()
// and crashes before anything can reach create(). The page you must open to
// create a campaign was the page that failed. DDL also doesn't belong on an
// insert path — that's what this migrator is for.
//
// Existence is checked via INFORMATION_SCHEMA rather than
// "ADD COLUMN IF NOT EXISTS", which MariaDB supports but MySQL does not (see
// the note in 0120) — this way works on both, and stays safe to re-apply under
// the automatic on-boot migrator.

$addColumnIfMissing = static function (Database $db, string $table, string $column, string $definition): void {
    $exists = $db->selectOne(
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [$table, $column]
    );

    if ($exists !== null) {
        return;
    }

    $db->statement("ALTER TABLE {$table} ADD COLUMN {$definition}");
};

return [
    'up' => [
        static fn (Database $db) => $addColumnIfMissing(
            $db,
            'mail_campaigns',
            'client_id',
            'client_id INT UNSIGNED NULL AFTER client_group_id'
        ),
    ],
];
