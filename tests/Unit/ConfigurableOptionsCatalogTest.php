<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Catalog\ConfigurableOptionGroupRepository;
use CodeVault\Catalog\ConfigurableOptionPricingRepository;
use CodeVault\Catalog\ConfigurableOptionRepository;
use CodeVault\Catalog\ProductGroupRepository;
use CodeVault\Catalog\ProductRepository;
use CodeVault\Database\Migrator;
use CodeVault\Tests\Support\DatabaseTestCase;

final class ConfigurableOptionsCatalogTest extends DatabaseTestCase
{
    private ConfigurableOptionGroupRepository $optionGroups;
    private ConfigurableOptionRepository $options;
    private ConfigurableOptionPricingRepository $pricing;
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->optionGroups = new ConfigurableOptionGroupRepository($this->db);
        $this->options = new ConfigurableOptionRepository($this->db);
        $this->pricing = new ConfigurableOptionPricingRepository($this->db);

        $groups = new ProductGroupRepository($this->db);
        $products = new ProductRepository($this->db);
        $groupId = $groups->create('Hosting', null);
        $this->productId = $products->create(['product_group_id' => $groupId, 'name' => 'Starter']);
    }

    public function test_sync_for_product_attaches_and_replaces_groups(): void
    {
        $groupA = $this->optionGroups->create('Extra Resources');
        $groupB = $this->optionGroups->create('Backups');

        $this->optionGroups->syncForProduct($this->productId, [$groupA, $groupB]);
        $this->assertSame([$groupA, $groupB], $this->optionGroups->idsForProduct($this->productId));

        $this->optionGroups->syncForProduct($this->productId, [$groupB]);
        $this->assertSame([$groupB], $this->optionGroups->idsForProduct($this->productId));
    }

    public function test_option_pricing_varies_by_cycle(): void
    {
        $groupId = $this->optionGroups->create('Extra Resources');
        $optionId = $this->options->create($groupId, 'Extra 10GB');

        $this->pricing->setPricing($optionId, 'monthly', 2.00);
        $this->pricing->setPricing($optionId, 'annually', 20.00);

        $this->assertSame(2.00, $this->pricing->priceFor($optionId, 'monthly'));
        $this->assertSame(20.00, $this->pricing->priceFor($optionId, 'annually'));
        $this->assertSame(0.0, $this->pricing->priceFor($optionId, 'quarterly'), 'no price set for this cycle');
    }

    public function test_deleting_a_group_cascades_to_its_options(): void
    {
        $groupId = $this->optionGroups->create('Extra Resources');
        $optionId = $this->options->create($groupId, 'Extra 10GB');

        $this->optionGroups->delete($groupId);

        $this->assertNull($this->options->find($optionId));
    }
}
