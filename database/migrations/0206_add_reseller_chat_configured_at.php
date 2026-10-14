<?php

declare(strict_types=1);

// When a store last SAVED its chat settings — the difference between "has not chosen
// yet" and "chose to show no chat".
//
// WHY THIS IS NEEDED
//
// A new store's WhatsApp button only appeared once the reseller had opened the store
// page and pressed "Save chat settings". The form PRE-FILLED their account phone as a
// hint, so most resellers looked at a number that was already there, assumed it was
// live, and never saved — and their storefront showed no chat at all.
//
// The fix is for the widget to use the owner's account phone AUTOMATICALLY until the
// reseller makes a choice. But "support_whatsapp IS NULL" means both "never chose"
// and "deliberately cleared it to show nothing", and only the first may fall back.
// This timestamp separates the two: NULL = never saved (fall back to the account
// phone); set = the saved values are the reseller's choice, including "none".
//
// NO BACKFILL for stores that already have a number: their saved number wins over the
// fallback anyway. Stores with nothing saved are exactly the ones that should now get
// the automatic button.

return [
    'up' => [
        function (\CodeVault\Database $db): void {
            $exists = (string) ($db->selectOne(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'resellers' AND COLUMN_NAME = 'chat_configured_at'"
            )['COLUMN_NAME'] ?? '') !== '';

            if (!$exists) {
                $db->statement('ALTER TABLE resellers ADD COLUMN chat_configured_at DATETIME NULL');
            }

            // A store that already saved a WhatsApp number or a Tawk.To id has made
            // its choice; record that so behaviour is identical to a fresh save.
            $db->update(
                'UPDATE resellers SET chat_configured_at = COALESCE(updated_at, NOW())
                 WHERE chat_configured_at IS NULL
                   AND (support_whatsapp IS NOT NULL OR tawk_property_id IS NOT NULL)'
            );
        },
    ],
];
