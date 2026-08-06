<?php

declare(strict_types=1);

namespace CodeVault\Support;

use CodeVault\Database;
use DateTimeImmutable;

/**
 * Senders that mail piping must ignore (bounce loops, spam, wrong-party
 * mail that keeps turning into support tickets). Entries are stored as a
 * lowercased pattern that may contain '*' wildcards — "*@pmhserver.name.ng"
 * blocks every bounce sender on that domain at once, which a single literal
 * Mailer-Daemon address can't do once the host part starts varying.
 */
final class BlockedEmailSenderRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<int, array<string, mixed>> newest first */
    public function all(): array
    {
        return $this->db->select('SELECT * FROM blocked_email_senders ORDER BY id DESC');
    }

    /**
     * Adds a pattern if it isn't already blocked (dedupe on the unique key).
     * Returns the id of the blocked row — new or pre-existing — or 0 when
     * the pattern is empty.
     */
    public function block(string $pattern, ?int $createdBy = null, ?int $sourceTicketId = null, ?string $reason = null): int
    {
        $normalized = strtolower(trim($pattern));

        if ($normalized === '') {
            return 0;
        }

        $existing = $this->db->selectOne(
            'SELECT id FROM blocked_email_senders WHERE pattern = ?',
            [$normalized]
        );

        if ($existing !== null) {
            return (int) $existing['id'];
        }

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO blocked_email_senders (pattern, reason, created_by, source_ticket_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$normalized, $reason, $createdBy, $sourceTicketId, $now, $now]
        );
    }

    public function delete(int $id): bool
    {
        return $this->db->delete('DELETE FROM blocked_email_senders WHERE id = ?', [$id]) > 0;
    }

    public function isBlocked(string $address): bool
    {
        return $this->matchingPattern($address) !== null;
    }

    /**
     * The stored pattern that matches an address, or null when nothing does.
     * A pattern with no '*' must equal the address exactly; otherwise the
     * '*' wildcards are matched against the full address.
     */
    public function matchingPattern(string $address): ?string
    {
        $address = strtolower(trim($address));

        if ($address === '') {
            return null;
        }

        foreach ($this->all() as $entry) {
            if ($this->matches((string) $entry['pattern'], $address)) {
                return (string) $entry['pattern'];
            }
        }

        return null;
    }

    private function matches(string $pattern, string $address): bool
    {
        // "*@example.com" is the common case (block an entire sender domain,
        // bounce loops included): the '*' is the local part, and it must
        // match example.com AND its subdomains — whiterider.pmhserver.name.ng
        // is still a pmhserver.name.ng sender. Pure glob '*' matching would
        // fail that because the literal "@pmhserver.name.ng" suffix isn't at
        // the end of a subdomain address.
        if (str_starts_with($pattern, '*@')) {
            $domain = substr($pattern, 2);
            $addressDomain = str_contains($address, '@') ? substr($address, (int) strrpos($address, '@') + 1) : '';

            return $addressDomain === $domain || str_ends_with($addressDomain, '.' . $domain);
        }

        if (!str_contains($pattern, '*')) {
            return $pattern === $address;
        }

        $regex = '/^' . str_replace('\\*', '.*', preg_quote($pattern, '/')) . '$/i';

        return preg_match($regex, $address) === 1;
    }
}
