<?php

declare(strict_types=1);

// A store's OWN chat, so the platform's chatbox never appears on a reseller's
// website.
//
// WHY THIS IS NOT JUST A SKIN ON THE EXISTING TAWK-TO ADDON
//
// Tawk.To is configured once, globally, by an admin, and its widget partial is
// rendered into `layouts.client` — which is the layout behind EVERY public page,
// including `/store`, checkout and the knowledgebase. So the moment a store is
// served, the platform's own live chat appears inside somebody else's business.
// That is the reseller's customer being invited to talk to us about the
// reseller's prices.
//
// Two columns, for two different jobs:
//
//   support_whatsapp     the DEFAULT. A reseller almost always has a WhatsApp
//                        number and almost never has a chat widget, so a
//                        click-to-chat button is the support channel that
//                        actually works on day one, with nothing to sign up for.
//   tawk_property_id     the UPGRADE, optional. Only the property id is stored —
//                        not a pasted embed snippet — because that id is the part
//                        of the snippet that goes into a <script> src. Storing the
//                        whole snippet would mean injecting a reseller's
//                        arbitrary JavaScript into a page we serve under our own
//                        security headers; storing an id we validate as
//                        alphanumeric and interpolate ourselves cannot do that.
//   tawk_widget_id       Tawk issues both; 'default' is what their own embed uses
//                        when a property has one widget, so it is nullable.
//
// NULL and '' both mean "not set" (see ResellerStoreRepository::saveChat), so a
// cleared field behaves the same however the form was submitted.
//
// No backfill. A store without either setting shows NO chat rather than the
// platform's: that is the promise this feature exists to keep, and defaulting to
// the platform's would keep breaking it for every store that has not been
// configured yet.

return [
    'up' => [
        function (\CodeVault\Database $db): void {
            $hasColumn = static function (\CodeVault\Database $db, string $table, string $column): bool {
                return (string) ($db->selectOne(
                    'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                    [$table, $column]
                )['COLUMN_NAME'] ?? '') !== '';
            };

            $columns = [
                // Digits only, with the country code — `wa.me` needs E.164. Stored
                // normalised so the link is built without re-parsing at render time.
                'support_whatsapp VARCHAR(32) NULL',
                'tawk_property_id VARCHAR(64) NULL',
                'tawk_widget_id VARCHAR(64) NULL',
            ];

            foreach ($columns as $definition) {
                $name = strstr($definition, ' ', true);

                if (!$hasColumn($db, 'resellers', (string) $name)) {
                    $db->statement('ALTER TABLE resellers ADD COLUMN ' . $definition);
                }
            }
        },
    ],
];
