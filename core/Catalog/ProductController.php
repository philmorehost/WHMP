<?php

declare(strict_types=1);

namespace CodeVault\Catalog;

use CodeVault\Auth\AuthGuard;
use CodeVault\Config;
use CodeVault\Database;
use CodeVault\Provisioning\ServerGroupRepository;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

final class ProductController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly ProductRepository $products,
        private readonly ProductGroupRepository $groups,
        private readonly ProductPricingRepository $pricing,
        private readonly ConfigurableOptionGroupRepository $optionGroups,
        private readonly ServerGroupRepository $serverGroups,
        private readonly Database $db,
        private readonly Config $config
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render('catalog.products-index', [
            'products' => $this->products->all(),
            // Every product's direct order link — /store/{id} — works
            // the same regardless of whether the product was created here
            // or came in via the WHMCS importer, since it's keyed purely
            // off this app's own local product id.
            'baseUrl' => rtrim((string) $this->config->env('APP_URL', ''), '/'),
        ]);
    }

    public function createForm(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render('catalog.product-form', $this->formData(null, null, null));
    }

    public function store(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $fields = $this->extractFields($request);

        if ($fields['name'] === '' || $fields['product_group_id'] === null) {
            return $this->render('catalog.product-form', $this->formData(null, null, null, 'Name and product group are required.'));
        }

        $id = $this->products->create($fields);
        $this->savePricing($id, $request);
        $this->saveOptionGroups($id, $request);

        return Response::redirect("/admin/products/{$id}/edit");
    }

    public function editForm(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $product = $this->products->find($id);

        if ($product === null) {
            return Response::html('404 Not Found', 404);
        }

        return $this->render('catalog.product-form', $this->formData($product, $id, null));
    }

    public function update(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $fields = $this->extractFields($request);

        $this->products->update($id, $fields);
        $this->savePricing($id, $request);
        $this->saveOptionGroups($id, $request);

        return Response::redirect("/admin/products/{$id}/edit");
    }

    public function destroy(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $db = $this->db;

        $activeCount = (int) $db->selectOne("SELECT COUNT(*) AS c FROM services WHERE product_id = ?", [$id])['c'];
        $migrateTo = $request->input('migrate_to_product_id');

        if ($activeCount > 0 && $migrateTo === null) {
            $allProducts = $this->products->all();
            $targetProducts = array_filter($allProducts, static fn($p) => (int)$p['id'] !== $id);
            $product = $this->products->find($id);
            
            return $this->render('catalog.product-migrate-delete', [
                'product' => $product,
                'activeCount' => $activeCount,
                'targetProducts' => $targetProducts,
            ]);
        }

        if ($activeCount > 0 && $migrateTo !== null && $migrateTo !== '') {
            $targetProductId = (int) $migrateTo;
            $targetProduct = $this->products->find($targetProductId);
            if ($targetProduct !== null) {
                $db->update("UPDATE services SET product_id = ?, product_name = ? WHERE product_id = ?", [$targetProductId, $targetProduct['name'], $id]);
            }
        }

        $this->products->delete($id);

        return Response::redirect('/admin/products');
    }

    private function savePricing(int $productId, Request $request): void
    {
        $prices = (array) $request->input('price', []);
        $setupFees = (array) $request->input('setup_fee', []);
        $enabled = (array) $request->input('cycle_enabled', []);

        foreach (BillingCycle::keys() as $cycle) {
            if (!isset($enabled[$cycle])) {
                $this->pricing->removePricing($productId, $cycle);
                continue;
            }

            $this->pricing->setPricing(
                $productId,
                $cycle,
                (float) ($setupFees[$cycle] ?? 0),
                (float) ($prices[$cycle] ?? 0)
            );
        }
    }

    private function saveOptionGroups(int $productId, Request $request): void
    {
        $groupIds = array_map('intval', (array) $request->input('option_groups', []));
        $this->optionGroups->syncForProduct($productId, $groupIds);
    }

    /** @return array<string, mixed> */
    private function extractFields(Request $request): array
    {
        $groupId = $request->input('product_group_id');
        $serverGroupId = $request->input('server_group_id');
        $stock = trim((string) $request->input('stock_quantity', ''));
        $autosetup = (string) $request->input('autosetup', 'payment');
        if (!in_array($autosetup, ['order', 'payment', 'on_accept', 'off'], true)) {
            $autosetup = 'payment';
        }

        return [
            'product_group_id' => $groupId !== null && $groupId !== '' ? (int) $groupId : null,
            'server_group_id' => $serverGroupId !== null && $serverGroupId !== '' ? (int) $serverGroupId : null,
            'autosetup' => $autosetup,
            'name' => trim((string) $request->input('name', '')),
            'description' => trim((string) $request->input('description', '')) ?: null,
            'status' => (string) $request->input('status', 'active'),
            'type' => (string) $request->input('type', 'other'),
            'is_upsell' => (string) $request->input('is_upsell', '') === '1',
            'require_domain' => (string) $request->input('require_domain', '') === '1',
            'upsell_pitch' => trim((string) $request->input('upsell_pitch', '')) ?: null,
            'stock_quantity' => $stock === '' ? null : (int) $stock,
        ];
    }

    /** @return array<string, mixed> */
    private function formData(?array $product, ?int $productId, ?array $unused, ?string $error = null): array
    {
        return [
            'product' => $product,
            'groups' => $this->groups->all(),
            'serverGroups' => $this->serverGroups->all(),
            'pricing' => $productId !== null ? $this->pricing->forProduct($productId) : [],
            'cycles' => BillingCycle::labels(),
            'optionGroups' => $this->optionGroups->all(),
            'attachedOptionGroups' => $productId !== null ? $this->optionGroups->idsForProduct($productId) : [],
            'error' => $error,
        ];
    }

    private function requirePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::PRODUCTS_MANAGE)) {
            return Response::html('403 Forbidden — missing products.manage permission', 403);
        }

        return null;
    }

    private function render(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Products',
            'content' => $content,
        ]));
    }
}
