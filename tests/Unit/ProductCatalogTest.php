<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Catalog\ProductGroupRepository;
use CodeVault\Catalog\ProductPricingRepository;
use CodeVault\Catalog\ProductRepository;
use CodeVault\Database\Migrator;
use CodeVault\Tests\Support\DatabaseTestCase;

final class ProductCatalogTest extends DatabaseTestCase
{
    private ProductGroupRepository $groups;
    private ProductRepository $products;
    private ProductPricingRepository $pricing;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->groups = new ProductGroupRepository($this->db);
        $this->products = new ProductRepository($this->db);
        $this->pricing = new ProductPricingRepository($this->db);
    }

    public function test_group_delete_is_refused_while_products_exist(): void
    {
        $groupId = $this->groups->create('Shared Hosting', null);
        $this->products->create(['product_group_id' => $groupId, 'name' => 'Starter']);

        $this->assertFalse($this->groups->delete($groupId));

        $this->db->delete('DELETE FROM products WHERE product_group_id = ?', [$groupId]);
        $this->assertTrue($this->groups->delete($groupId));
    }

    public function test_pricing_set_and_retrieve_per_cycle(): void
    {
        $groupId = $this->groups->create('Shared Hosting', null);
        $productId = $this->products->create(['product_group_id' => $groupId, 'name' => 'Starter']);

        $this->pricing->setPricing($productId, 'monthly', 5.00, 9.99);
        $this->pricing->setPricing($productId, 'annually', 5.00, 99.00);

        $all = $this->pricing->forProduct($productId);

        $this->assertCount(2, $all);
        $this->assertSame(9.99, (float) $all['monthly']['price']);
        $this->assertSame(99.00, (float) $all['annually']['price']);
    }

    public function test_setting_pricing_twice_updates_rather_than_duplicates(): void
    {
        $groupId = $this->groups->create('Shared Hosting', null);
        $productId = $this->products->create(['product_group_id' => $groupId, 'name' => 'Starter']);

        $this->pricing->setPricing($productId, 'monthly', 0, 10.00);
        $this->pricing->setPricing($productId, 'monthly', 0, 12.00);

        $this->assertSame(12.00, (float) $this->pricing->find($productId, 'monthly')['price']);
    }

    public function test_stock_decrements_and_blocks_at_zero(): void
    {
        $groupId = $this->groups->create('Licenses', null);
        $productId = $this->products->create(['product_group_id' => $groupId, 'name' => 'Single License', 'stock_quantity' => 1]);

        $this->assertTrue($this->products->hasUnlimitedOrAvailableStock($productId));
        $this->assertTrue($this->products->decrementStock($productId));
        $this->assertFalse($this->products->hasUnlimitedOrAvailableStock($productId));
        $this->assertFalse($this->products->decrementStock($productId), 'cannot decrement below zero');
    }

    public function test_null_stock_quantity_is_always_available(): void
    {
        $groupId = $this->groups->create('Hosting', null);
        $productId = $this->products->create(['product_group_id' => $groupId, 'name' => 'Unlimited Plan', 'stock_quantity' => null]);

        $this->assertTrue($this->products->hasUnlimitedOrAvailableStock($productId));
        $this->assertFalse($this->products->decrementStock($productId), 'unlimited stock has nothing to decrement');
        $this->assertTrue($this->products->hasUnlimitedOrAvailableStock($productId), 'still available after a no-op decrement');
    }
}
