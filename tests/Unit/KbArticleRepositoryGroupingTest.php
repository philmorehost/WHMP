<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Database\Migrator;
use CodeVault\Knowledgebase\KbArticleRepository;
use CodeVault\Knowledgebase\KbCategoryRepository;
use CodeVault\Tests\Support\DatabaseTestCase;

final class KbArticleRepositoryGroupingTest extends DatabaseTestCase
{
    private KbArticleRepository $articles;
    private KbCategoryRepository $categories;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->articles = new KbArticleRepository($this->db);
        $this->categories = new KbCategoryRepository($this->db);
    }

    public function test_groups_articles_by_category_id_in_one_query(): void
    {
        $catA = $this->categories->create('Category A');
        $catB = $this->categories->create('Category B');

        $this->articles->create($catA, 'Article A1', 'Body');
        $this->articles->create($catA, 'Article A2', 'Body');
        $this->articles->create($catB, 'Article B1', 'Body');

        $grouped = $this->articles->allGroupedByCategory();

        $this->assertCount(2, $grouped[$catA]);
        $this->assertCount(1, $grouped[$catB]);
    }

    public function test_category_with_no_articles_is_simply_absent_from_the_result(): void
    {
        $emptyCategory = $this->categories->create('Empty Category');

        $grouped = $this->articles->allGroupedByCategory();

        $this->assertArrayNotHasKey($emptyCategory, $grouped);
    }
}
