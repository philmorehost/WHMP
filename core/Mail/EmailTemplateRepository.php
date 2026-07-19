<?php

declare(strict_types=1);

namespace CodeVault\Mail;

use CodeVault\Database;
use DateTimeImmutable;

final class EmailTemplateRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->db->select('SELECT * FROM email_templates ORDER BY name');
    }

    /** @return array<string, mixed>|null */
    public function findByKey(string $key): ?array
    {
        return $this->db->selectOne('SELECT * FROM email_templates WHERE `key` = ?', [$key]);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM email_templates WHERE id = ?', [$id]);
    }

    public function update(int $id, string $name, string $subject, string $bodyHtml): void
    {
        $this->db->update(
            'UPDATE email_templates SET name = ?, subject = ?, body_html = ?, updated_at = ? WHERE id = ?',
            [$name, $subject, $bodyHtml, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }
}
