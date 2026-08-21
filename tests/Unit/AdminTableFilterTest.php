<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\OrderRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Database\Migrator;
use CodeVault\Table\TableFilters;
use CodeVault\Tests\Support\DatabaseTestCase;
use CodeVault\View;

/**
 * Admin table column-filter feature: the shared TableFilters wire-format
 * helpers, the filter-aware OrderRepository::paginate() (Order ID / Client /
 * Total / Status columns), pagination persistence, and the reusable
 * filter-row + pagination view partials that every admin list page uses.
 */
final class AdminTableFilterTest extends DatabaseTestCase
{
    private OrderRepository $orders;
    private ClientRepository $clients;

    protected function setUp(): void
    {
        parent::setUp();

        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->orders = new OrderRepository($this->db);
        $this->clients = new ClientRepository($this->db);
    }

    private function insertClient(string $email, string $first, string $last): int
    {
        return $this->clients->create([
            'email' => $email,
            'password' => 'password123',
            'first_name' => $first,
            'last_name' => $last,
        ]);
    }

    private function insertOrder(int $clientId, float $total, string $status, string $createdAt = '2026-01-01 00:00:00'): int
    {
        return (int) $this->db->insert(
            'INSERT INTO orders (client_id, status, total, discount_amount, currency_id, currency_rate, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$clientId, $status, $total, 0.0, null, 1.0, $createdAt, $createdAt]
        );
    }

    public function test_filters_orders_by_status(): void
    {
        $alice = $this->insertClient('alice@example.test', 'Alice', 'Adams');
        $bob = $this->insertClient('bob@example.test', 'Bob', 'Brown');
        $this->insertOrder($alice, 100.0, 'active');
        $this->insertOrder($alice, 50.0, 'pending');
        $this->insertOrder($bob, 200.0, 'active');

        $result = $this->orders->paginate(null, 1, 15, ['status' => 'active']);

        $this->assertSame(2, $result['total']);
        foreach ($result['data'] as $row) {
            $this->assertSame('active', $row['status']);
        }
    }

    public function test_filters_orders_by_client_name_or_email(): void
    {
        $alice = $this->insertClient('alice@example.test', 'Alice', 'Adams');
        $bob = $this->insertClient('bob@example.test', 'Bob', 'Brown');
        $this->insertOrder($alice, 100.0, 'active');
        $this->insertOrder($bob, 200.0, 'active');
        $this->insertOrder($bob, 300.0, 'active');

        // Partial name match.
        $byName = $this->orders->paginate(null, 1, 15, ['client' => 'ali']);
        $this->assertSame(1, $byName['total']);
        $this->assertSame('Alice', $byName['data'][0]['first_name']);

        // Email match.
        $byEmail = $this->orders->paginate(null, 1, 15, ['client' => 'bob@example']);
        $this->assertSame(2, $byEmail['total']);
    }

    public function test_filters_orders_by_id_and_total(): void
    {
        $alice = $this->insertClient('alice@example.test', 'Alice', 'Adams');
        $o1 = $this->insertOrder($alice, 19.99, 'active');
        $o2 = $this->insertOrder($alice, 49.50, 'pending');

        // "ORD-13" style input still resolves to a numeric id.
        $byId = $this->orders->paginate(null, 1, 15, ['id' => "ORD-{$o2}"]);
        $this->assertSame(1, $byId['total']);
        $this->assertSame($o2, (int) $byId['data'][0]['id']);

        // Amount matches the stored value.
        $byTotal = $this->orders->paginate(null, 1, 15, ['total' => '49.50']);
        $this->assertSame(1, $byTotal['total']);
        $this->assertEqualsWithDelta(49.50, (float) $byTotal['data'][0]['total'], 0.001);
    }

    public function test_filters_combine_with_and_and_with_status_tab(): void
    {
        $alice = $this->insertClient('alice@example.test', 'Alice', 'Adams');
        $bob = $this->insertClient('bob@example.test', 'Bob', 'Brown');
        $this->insertOrder($alice, 100.0, 'active');
        $this->insertOrder($alice, 50.0, 'pending');
        $this->insertOrder($bob, 100.0, 'active');

        // Status tab (active) AND column filter (client = alice) must both apply.
        $result = $this->orders->paginate('active', 1, 15, ['client' => 'alice']);

        $this->assertSame(1, $result['total']);
        $this->assertSame('active', $result['data'][0]['status']);
        $this->assertSame('Alice', $result['data'][0]['first_name']);
    }

    public function test_paginates_and_keeps_total_with_filters(): void
    {
        $alice = $this->insertClient('alice@example.test', 'Alice', 'Adams');
        $bob = $this->insertClient('bob@example.test', 'Bob', 'Brown');

        for ($i = 0; $i < 5; $i++) {
            $this->insertOrder($i % 2 === 0 ? $alice : $bob, 10.0 + $i, 'active');
        }

        $page1 = $this->orders->paginate(null, 1, 2, []);
        $this->assertSame(5, $page1['total']);
        $this->assertCount(2, $page1['data']);
        $this->assertSame(1, $page1['page']);

        $page2 = $this->orders->paginate(null, 2, 2, []);
        $this->assertSame(5, $page2['total']);
        $this->assertCount(2, $page2['data']);
        $this->assertSame(2, $page2['page']);

        // The two pages must not overlap (distinct ids).
        $page1Ids = array_map(static fn (array $r) => (int) $r['id'], $page1['data']);
        $page2Ids = array_map(static fn (array $r) => (int) $r['id'], $page2['data']);
        $this->assertSame([], array_intersect($page1Ids, $page2Ids));
    }

    public function test_unknown_filter_keys_are_ignored(): void
    {
        $alice = $this->insertClient('alice@example.test', 'Alice', 'Adams');
        $this->insertOrder($alice, 10.0, 'active');

        $result = $this->orders->paginate(null, 1, 15, ['not_a_column' => 'x', 'id' => '999']);

        // Unknown keys are dropped; the id filter still applies.
        $this->assertSame(0, $result['total']);
    }

    public function test_query_string_preserves_filters_extra_and_page(): void
    {
        $query = TableFilters::query(
            ['client' => 'ali', 'status' => 'active'],
            ['status' => 'pending'], // should be overridden/merged as extra
            null
        );

        $this->assertStringContainsString('filters[client]=ali', $query);
        $this->assertStringContainsString('filters[status]=active', $query);

        $paged = TableFilters::query(['id' => '13'], ['status' => 'pending'], 2);
        $this->assertStringContainsString('filters[id]=13', $paged);
        $this->assertStringContainsString('status=pending', $paged);
        $this->assertStringContainsString('page=2', $paged);

        $this->assertSame('', TableFilters::query([], [], null));
    }

    public function test_from_query_whitelists_and_trims(): void
    {
        $parsed = TableFilters::fromQuery(
            ['filters' => ['id' => '  13 ', 'client' => '', 'status' => 'active', 'bogus' => 'x']],
            ['id' => true, 'client' => true, 'status' => true]
        );

        $this->assertSame(['id' => '13', 'status' => 'active'], $parsed);
    }

    public function test_filter_row_and_pagination_partials_render(): void
    {
        new \CodeVault\Kernel(dirname(__DIR__, 2));

        $view = new View(dirname(__DIR__, 2) . '/resources/views');
        $filters = ['id' => '13'];
        $filterColumns = [
            ['filterable' => true, 'key' => 'id', 'label' => 'Order ID', 'type' => 'number', 'placeholder' => 'e.g. 13'],
            ['filterable' => true, 'key' => 'client', 'label' => 'Client', 'type' => 'text'],
            ['filterable' => false],
        ];

        $form = $view->partial('partials.table-filter', [
            'formId' => 'test-filter',
            'action' => '/admin/orders',
            'filters' => $filters,
            'preserve' => ['status' => 'pending'],
            'activeCount' => 1,
        ]);
        $this->assertStringContainsString('id="test-filter"', $form);
        $this->assertStringContainsString('Filters (1)', $form);

        $row = $view->partial('partials.table-filter-row', [
            'formId' => 'test-filter',
            'action' => '/admin/orders',
            'columns' => $filterColumns,
            'filters' => $filters,
            'preserve' => ['status' => 'pending'],
        ]);
        $this->assertStringContainsString('name="filters[id]"', $row);
        $this->assertStringContainsString('value="13"', $row);
        $this->assertStringContainsString('form="test-filter"', $row);
        // The active filter's cell is visible; the non-filterable cell is hidden.
        $this->assertStringContainsString('✕ Clear', $row);

        $pagination = $view->partial('partials.table-pagination', [
            'results' => ['data' => [], 'total' => 25, 'page' => 1, 'perPage' => 15],
            'action' => '/admin/orders',
            'filters' => $filters,
            'preserve' => ['status' => 'pending'],
            'label' => 'orders',
        ]);
        $this->assertStringContainsString('Page <strong>1</strong> of <strong>2</strong>', $pagination);
        $this->assertStringContainsString('25 total orders', $pagination);
        // Next link must carry the active filter + the status tab. `e()` keeps
        // the bracket chars intact in the URL, so filters[id]=13 is raw here.
        $this->assertStringContainsString('filters[id]=13', $pagination);
        $this->assertStringContainsString('status=pending', $pagination);
        $this->assertStringContainsString('page=2', $pagination);
    }

    public function test_sort_from_query_whitelists_column_and_direction(): void
    {
        // Valid column + explicit direction.
        $parsed = TableFilters::sortFromQuery(
            ['sort' => 'total', 'dir' => 'desc'],
            ['id' => 'o.id', 'client' => 'c.last_name', 'total' => 'o.total', 'status' => 'o.status']
        );
        $this->assertSame(['column' => 'total', 'dir' => 'desc'], $parsed);

        // Missing direction defaults to asc.
        $asc = TableFilters::sortFromQuery(
            ['sort' => 'client'],
            ['id' => 'o.id', 'client' => 'c.last_name', 'total' => 'o.total', 'status' => 'o.status']
        );
        $this->assertSame(['column' => 'client', 'dir' => 'asc'], $asc);

        // Unknown column -> no sort.
        $bogus = TableFilters::sortFromQuery(
            ['sort' => 'hacked`; DROP TABLE orders; --', 'dir' => 'asc'],
            ['id' => 'o.id']
        );
        $this->assertNull($bogus);

        // Missing sort param -> no sort.
        $this->assertNull(TableFilters::sortFromQuery(['page' => '2'], ['id' => 'o.id']));
    }

    public function test_order_by_builds_whitelisted_fragment_only(): void
    {
        $sortable = ['id' => 'o.id', 'client' => 'c.last_name', 'total' => 'o.total', 'status' => 'o.status'];

        $this->assertSame('ORDER BY o.total ASC', TableFilters::orderBy($sortable, ['column' => 'total', 'dir' => 'asc']));
        $this->assertSame('ORDER BY c.last_name DESC', TableFilters::orderBy($sortable, ['column' => 'client', 'dir' => 'desc']));
        // Unknown direction coerces to ASC.
        $this->assertSame('ORDER BY o.id ASC', TableFilters::orderBy($sortable, ['column' => 'id', 'dir' => 'sideways']));
        // Unknown column / no sort -> empty (caller keeps its default order).
        $this->assertSame('', TableFilters::orderBy($sortable, ['column' => 'nope', 'dir' => 'asc']));
        $this->assertSame('', TableFilters::orderBy($sortable, null));
    }

    public function test_sorts_orders_ascending_and_descending_by_total(): void
    {
        $alice = $this->insertClient('alice@example.test', 'Alice', 'Adams');
        $bob = $this->insertClient('bob@example.test', 'Bob', 'Brown');
        $this->insertOrder($alice, 100.0, 'active');
        $this->insertOrder($bob, 50.0, 'active');
        $this->insertOrder($alice, 200.0, 'active');

        $asc = $this->orders->paginate(null, 1, 15, [], ['column' => 'total', 'dir' => 'asc']);
        $this->assertSame(3, $asc['total']);
        $this->assertSame([50.0, 100.0, 200.0], array_map(static fn (array $r) => (float) $r['total'], $asc['data']));

        $desc = $this->orders->paginate(null, 1, 15, [], ['column' => 'total', 'dir' => 'desc']);
        $this->assertSame([200.0, 100.0, 50.0], array_map(static fn (array $r) => (float) $r['total'], $desc['data']));
    }

    public function test_sort_unknown_column_falls_back_to_default_order(): void
    {
        $alice = $this->insertClient('alice@example.test', 'Alice', 'Adams');
        $this->insertOrder($alice, 10.0, 'active', '2026-01-01 00:00:00');
        $this->insertOrder($alice, 20.0, 'active', '2026-01-02 00:00:00');

        // Default is newest first (id DESC) — the bogus sort must not change that.
        $result = $this->orders->paginate(null, 1, 15, [], ['column' => 'hacker', 'dir' => 'asc']);
        $ids = array_map(static fn (array $r) => (int) $r['id'], $result['data']);
        $expectedDesc = $ids;
        rsort($expectedDesc);
        $this->assertSame($expectedDesc, $ids);
    }

    public function test_query_string_includes_sort_and_preserves_it(): void
    {
        $q = TableFilters::query(['status' => 'active'], [], 1, ['column' => 'total', 'dir' => 'desc']);
        $this->assertStringContainsString('filters[status]=active', $q);
        $this->assertStringContainsString('sort=total', $q);
        $this->assertStringContainsString('dir=desc', $q);
        $this->assertStringContainsString('page=1', $q);

        $noSort = TableFilters::query(['status' => 'active'], [], 1, null);
        $this->assertStringNotContainsString('sort=', $noSort);
        $this->assertStringNotContainsString('dir=', $noSort);
    }

    public function test_header_sort_partial_renders_sort_link_and_preserves_filter(): void
    {
        new \CodeVault\Kernel(dirname(__DIR__, 2));

        $view = new View(dirname(__DIR__, 2) . '/resources/views');
        $out = $view->partial('partials.table-header-sort', [
            'key' => 'total',
            'label' => 'Total',
            'align' => 'right',
            'action' => '/admin/orders',
            'filters' => ['status' => 'active'],
            'preserve' => ['q' => 'bob'],
            'sort' => ['column' => 'total', 'dir' => 'asc'],
        ]);

        // First click asc -> next click flips to desc.
        $this->assertStringContainsString('dir=desc', $out);
        $this->assertStringContainsString('sort=total', $out);
        // Active filters + search are preserved in the sort link.
        $this->assertStringContainsString('filters[status]=active', $out);
        $this->assertStringContainsString('q=bob', $out);
        // Ascending indicator is shown on the active column.
        $this->assertStringContainsString('▲', $out);
        $this->assertStringContainsString('table-sort-link is-active', $out);
    }
}
