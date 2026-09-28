<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Api\DatabaseApiCredentialRepository;

/**
 * The API reference, as data.
 *
 * The endpoints table is the single source of truth the docs page renders AND
 * the thing the test suite cross-checks against routes/api.php. Writing the
 * reference as prose in a view would let it drift the moment a route changes —
 * the documented path would keep looking authoritative while 404ing. Here, a
 * path that isn't registered fails a test instead.
 *
 * Scope names come from DatabaseApiCredentialRepository::scopeCatalog() for the
 * same reason.
 */
final class ApiDocumentation
{
    public const BASE_URL = '/api';

    /** The header every authenticated call carries. */
    public const AUTH_HEADER = 'Authorization: Bearer {key}.{secret}';

    /**
     * The whole public API surface. `example` is a runnable curl command, and
     * `returns` is the shape inside the envelope's `data`.
     *
     * @return array<int, array{method: string, path: string, scope: string, summary: string, example: string, returns: string}>
     */
    public static function endpoints(): array
    {
        return [
            [
                'method' => 'GET',
                'path' => '/api/ping',
                'scope' => 'none (public)',
                'summary' => 'Liveness probe. Confirms the API is reachable without any credentials.',
                'example' => "curl -s " . self::BASE_URL . "/ping",
                'returns' => '{"pong": true, "time": "2026-01-01T00:00:00+00:00"}',
            ],
            [
                'method' => 'GET',
                'path' => '/api/reseller/pricing',
                'scope' => 'reseller.read',
                'summary' => 'Your reseller catalogue: every sellable service (per billing cycle) and every TLD, each at list price, your discount percentage, and the price you actually pay. This is the endpoint a reseller key is for.',
                'example' => "curl -s -H \"Authorization: Bearer \$KEY.\$SECRET\" " . self::BASE_URL . "/reseller/pricing",
                'returns' => '{"discounts": {"service": 20, "domain": 10}, "services": [{"product_id": 1, "name": "Starter Hosting", "cycles": [{"cycle": "monthly", "label": "Monthly", "price": {"list": 9.99, "discount_percent": 20, "reseller": 7.99}, "setup_fee": {...}}]}], "domains": [{"tld": ".com", "register": {"list": 15, "discount_percent": 10, "reseller": 13.5}, "transfer": {...}, "renew": {...}}]}',
            ],
            [
                'method' => 'GET',
                'path' => '/api/clients',
                'scope' => 'clients.read',
                'summary' => 'List client accounts (paginated: ?page=, ?per_page=, ?search=). Staff-scoped — not available to a reseller key.',
                'example' => "curl -s -H \"Authorization: Bearer \$KEY.\$SECRET\" \"" . self::BASE_URL . "/clients?per_page=20\"",
                'returns' => '{"data": [ ... ], "total": 128, "page": 1, "perPage": 20}',
            ],
            [
                'method' => 'GET',
                'path' => '/api/clients/{id}',
                'scope' => 'clients.read',
                'summary' => 'One client account by id.',
                'example' => "curl -s -H \"Authorization: Bearer \$KEY.\$SECRET\" " . self::BASE_URL . "/clients/42",
                'returns' => 'A single client record.',
            ],
            [
                'method' => 'GET',
                'path' => '/api/invoices',
                'scope' => 'invoices.read',
                'summary' => 'List invoices (paginated, optionally ?status=).',
                'example' => "curl -s -H \"Authorization: Bearer \$KEY.\$SECRET\" \"" . self::BASE_URL . "/invoices?status=unpaid\"",
                'returns' => '{"data": [ ... ], "total": 12, "page": 1, "perPage": 20}',
            ],
            [
                'method' => 'GET',
                'path' => '/api/invoices/{id}',
                'scope' => 'invoices.read',
                'summary' => 'One invoice, with its line items.',
                'example' => "curl -s -H \"Authorization: Bearer \$KEY.\$SECRET\" " . self::BASE_URL . "/invoices/3619",
                'returns' => '{"invoice": {...}, "items": [ ... ]}',
            ],
            [
                'method' => 'POST',
                'path' => '/api/invoices',
                'scope' => 'invoices.write',
                'summary' => 'Raise an invoice for a client. Body: client_id, due_date, items[] (description + amount), optional tax/discount.',
                'example' => "curl -s -X POST -H \"Authorization: Bearer \$KEY.\$SECRET\" -H 'Content-Type: application/json' \\\n  -d '{\"client_id\":42,\"due_date\":\"2026-02-01\",\"items\":[{\"description\":\"Consulting\",\"amount\":150.00}]}' \\\n  " . self::BASE_URL . "/invoices",
                'returns' => '{"invoice_id": 3620}',
            ],
            [
                'method' => 'GET',
                'path' => '/api/services',
                'scope' => 'services.read',
                'summary' => 'List services (paginated, optionally ?client_id= or ?status=).',
                'example' => "curl -s -H \"Authorization: Bearer \$KEY.\$SECRET\" \"" . self::BASE_URL . "/services?status=active\"",
                'returns' => '{"data": [ ... ], "total": 40, "page": 1, "perPage": 20}',
            ],
            [
                'method' => 'GET',
                'path' => '/api/domains',
                'scope' => 'domains.read',
                'summary' => 'List domains (paginated, optionally ?client_id= or ?status=).',
                'example' => "curl -s -H \"Authorization: Bearer \$KEY.\$SECRET\" " . self::BASE_URL . "/domains",
                'returns' => '{"data": [ ... ], "total": 18, "page": 1, "perPage": 20}',
            ],
            [
                'method' => 'GET',
                'path' => '/api/tickets',
                'scope' => 'tickets.read',
                'summary' => 'List support tickets (paginated, optionally ?status=).',
                'example' => "curl -s -H \"Authorization: Bearer \$KEY.\$SECRET\" " . self::BASE_URL . "/tickets",
                'returns' => '{"data": [ ... ], "total": 5, "page": 1, "perPage": 20}',
            ],
            [
                'method' => 'POST',
                'path' => '/api/tickets/{id}/reply',
                'scope' => 'tickets.write',
                'summary' => 'Add a reply to a ticket. Body: message (required), optional is_private.',
                'example' => "curl -s -X POST -H \"Authorization: Bearer \$KEY.\$SECRET\" -H 'Content-Type: application/json' \\\n  -d '{\"message\":\"We restarted the service for you.\"}' \\\n  " . self::BASE_URL . "/tickets/812/reply",
                'returns' => '{"reply_id": 9021}',
            ],
        ];
    }

    /**
     * Every scope the API understands, with a plain-English description. The
     * canonical list comes from the credential repository so a scope added
     * there appears here without anyone remembering to update the docs.
     *
     * @return array<int, array{scope: string, description: string, reseller: bool}>
     */
    public static function scopes(): array
    {
        $descriptions = [
            'clients.read' => 'Read client accounts.',
            'clients.write' => 'Create and update client accounts.',
            'invoices.read' => 'Read invoices and their line items.',
            'invoices.write' => 'Raise, edit and cancel invoices.',
            'services.read' => 'Read services.',
            'services.write' => 'Create, suspend and terminate services.',
            'domains.read' => 'Read domains.',
            'domains.write' => 'Register, renew and update domains.',
            'tickets.read' => 'Read support tickets.',
            'tickets.write' => 'Reply to and update support tickets.',
            'reseller.read' => 'Read YOUR reseller catalogue and discounted prices.',
        ];

        $catalog = DatabaseApiCredentialRepository::scopeCatalog();
        $scopes = [];

        foreach ($catalog as $scope) {
            $scopes[] = [
                'scope' => $scope,
                'description' => $descriptions[$scope] ?? '',
                // Which of these a reseller key actually holds. Everything else
                // reads the whole install, so a client-owned key never gets it.
                'reseller' => in_array($scope, ResellerCredentialService::SCOPES, true),
            ];
        }

        return $scopes;
    }

    /** The error codes a client should expect, and what they mean. */
    public static function errorCodes(): array
    {
        return [
            ['code' => 400, 'message' => 'Bad request', 'meaning' => 'A required field was missing or malformed.'],
            ['code' => 401, 'message' => 'Unauthorized', 'meaning' => 'The key/secret pair is wrong, the key is disabled, or the scope is missing.'],
            ['code' => 403, 'message' => 'Forbidden', 'meaning' => 'Authenticated, but the credential does not hold the scope that endpoint needs.'],
            ['code' => 404, 'message' => 'Not found', 'meaning' => 'The id does not exist (or is not visible to this credential).'],
            ['code' => 429, 'message' => 'Too many requests', 'meaning' => 'Rate limited. Back off and retry.'],
        ];
    }
}
