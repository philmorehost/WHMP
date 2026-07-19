<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Catalog\ProductGroupRepository;
use CodeVault\Catalog\ProductRepository;
use CodeVault\Database\Migrator;
use CodeVault\Tests\Support\DatabaseTestCase;

final class ProductRepositoryGroupingTest extends DatabaseTestCase
{
    private ProductRepository $products;
    private ProductGroupRepository $groups;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->products = new ProductRepository($this->db);
        $this->groups = new ProductGroupRepository($this->db);
    }

    public function test_groups_active_products_by_product_group_id_in_one_query(): void
    {
        $groupA = $this->groups->create('Group A', null);
        $groupB = $this->groups->create('Group B', null);

        $this->products->create(['product_group_id' => $groupA, 'name' => 'Product A1', 'status' => 'active']);
        $this->products->create(['product_group_id' => $groupA, 'name' => 'Product A2', 'status' => 'active']);
        $this->products->create(['product_group_id' => $groupB, 'name' => 'Product B1', 'status' => 'active']);

        $grouped = $this->products->allGroupedByGroup();

        $this->assertCount(2, $grouped[$groupA]);
        $this->assertCount(1, $grouped[$groupB]);
    }

    public function test_hidden_products_are_excluded(): void
    {
        $groupA = $this->groups->create('Group A', null);
        $this->products->create(['product_group_id' => $groupA, 'name' => 'Visible', 'status' => 'active']);
        $this->products->create(['product_group_id' => $groupA, 'name' => 'Hidden', 'status' => 'hidden']);

        $grouped = $this->products->allGroupedByGroup();

        $this->assertCount(1, $grouped[$groupA]);
        $this->assertSame('Visible', $grouped[$groupA][0]['name']);
    }

    public function test_group_with_no_products_is_simply_absent_from_the_result(): void
    {
        $emptyGroup = $this->groups->create('Empty Group', null);

        $grouped = $this->products->allGroupedByGroup();

        $this->assertArrayNotHasKey($emptyGroup, $grouped);
    }
}
