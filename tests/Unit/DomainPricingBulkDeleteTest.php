<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Database\Migrator;
use CodeVault\Domains\DomainPricingRepository;
use CodeVault\Tests\Support\DatabaseTestCase;

/**
 * Bulk-deleting TLD pricing rows — the checkboxes on Admin → Domain Pricing.
 * The interesting parts are at the storage boundary: exactly the chosen rows
 * go, everything else survives, and hostile id lists (duplicates, negatives,
 * junk, empty) neither error nor over-delete. The route order is asserted too:
 * `/bulk-delete` is a two-segment sibling of `/{id}/delete`, so if the
 * parameterised route ever matched first, "bulk-delete" would be read as an
 * id and the delete would silently do nothing.
 */
final class DomainPricingBulkDeleteTest extends DatabaseTestCase
{
    private DomainPricingRepository $pricing;

    /** @var array<int, int> tld suffix => row id */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->pricing = new DomainPricingRepository($this->db);

        foreach (['.test1', '.test2', '.test3', '.test4'] as $i => $tld) {
            $this->ids[$i] = (int) $this->db->insert(
                'INSERT INTO domain_pricing (tld, registrar_slug, register_price, transfer_price, renew_price, created_at, updated_at) VALUES (?, ?, 10.00, 10.00, 10.00, NOW(), NOW())',
                [$tld, 'local']
            );
        }
    }

    private function remainingTlds(): array
    {
        return array_column($this->db->select('SELECT tld FROM domain_pricing ORDER BY id'), 'tld');
    }

    public function test_deletes_exactly_the_selected_rows_and_reports_the_count(): void
    {
        $deleted = $this->pricing->deleteMany([$this->ids[0], $this->ids[2]]);

        $this->assertSame(2, $deleted);
        $this->assertSame(['.test2', '.test4'], $this->remainingTlds());
    }

    public function test_junk_id_lists_are_cleaned_without_error_or_over_delete(): void
    {
        $deleted = $this->pricing->deleteMany([
            $this->ids[1],
            (string) $this->ids[1],      // duplicate, differently typed
            '-5',                        // negative junk
            'abc',                       // non-numeric junk
            0,                           // zero matches nothing by design
        ]);

        $this->assertSame(1, $deleted);
        $this->assertSame(['.test1', '.test3', '.test4'], $this->remainingTlds());
    }

    public function test_an_empty_selection_deletes_nothing(): void
    {
        $this->assertSame(0, $this->pricing->deleteMany([]));
        $this->assertCount(4, $this->remainingTlds());
    }

    public function test_unknown_ids_match_nothing_rather_than_erroring(): void
    {
        $this->assertSame(0, $this->pricing->deleteMany([499999, 500000]));
        $this->assertCount(4, $this->remainingTlds());
    }

    public function test_the_bulk_delete_route_is_registered_before_the_parameterised_delete(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/routes/domains.php');

        $this->assertLessThan(
            strpos($routes, '/admin/domain-pricing/{id}/delete'),
            strpos($routes, '/admin/domain-pricing/bulk-delete'),
            '/bulk-delete must match before /{id}/delete, or "bulk-delete" is read as an id and the action silently does nothing'
        );
    }
}
