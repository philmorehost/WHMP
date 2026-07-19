<?php

declare(strict_types=1);

namespace CodeVault\CustomFields;

use CodeVault\Database;

final class CustomFieldValueRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<int, string> custom_field_id => value */
    public function forClient(int $clientId): array
    {
        $rows = $this->db->select('SELECT custom_field_id, value FROM client_custom_field_values WHERE client_id = ?', [$clientId]);

        $values = [];

        foreach ($rows as $row) {
            $values[(int) $row['custom_field_id']] = (string) $row['value'];
        }

        return $values;
    }

    /** @param array<int, string> $values custom_field_id => value */
    public function saveForClient(int $clientId, array $values): void
    {
        foreach ($values as $fieldId => $value) {
            $existing = $this->db->selectOne(
                'SELECT id FROM client_custom_field_values WHERE client_id = ? AND custom_field_id = ?',
                [$clientId, $fieldId]
            );

            if ($existing === null) {
                $this->db->insert(
                    'INSERT INTO client_custom_field_values (client_id, custom_field_id, value) VALUES (?, ?, ?)',
                    [$clientId, $fieldId, $value]
                );

                continue;
            }

            $this->db->update(
                'UPDATE client_custom_field_values SET value = ? WHERE client_id = ? AND custom_field_id = ?',
                [$value, $clientId, $fieldId]
            );
        }
    }
}
