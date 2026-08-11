<?php

declare(strict_types=1);

namespace CodeVault\Catalog;

use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

/**
 * Admin configuration for product add-ons (Admin → Products → Add-ons):
 * which products may be sold as recurring add-ons to which parent products.
 * A config row may pin a billing cycle (NULL = available on any cycle the
 * parent service runs on); the add-on's own product_pricing supplies the
 * price for whichever cycle is in play.
 */
final class ProductAddonController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly ProductAddonRepository $addons,
        private readonly ProductRepository $products
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $selectedParentId = (int) ($request->query('parent_id') ?: 0);

        return $this->render('catalog.product-addons-index', [
            'rows' => $this->addons->all(),
            'products' => $this->products->all(),
            'selectedParentId' => $selectedParentId,
            'current' => $selectedParentId > 0 ? $this->addons->forParentProduct($selectedParentId) : [],
            'error' => $request->query('error'),
            'message' => $request->query('msg'),
        ]);
    }

    public function attach(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $parentProductId = (int) $request->input('parent_product_id', 0);
        $addonProductId = (int) $request->input('addon_product_id', 0);
        $cycle = (string) $request->input('billing_cycle', '');
        $sortOrder = max(0, (int) $request->input('sort_order', 0));

        if ($parentProductId <= 0 || $addonProductId <= 0 || $parentProductId === $addonProductId) {
            return Response::redirect('/admin/products/addons?error=' . urlencode('Choose a parent product and a different add-on product.'));
        }

        $this->addons->attach($parentProductId, $addonProductId, $cycle !== '' ? $cycle : null, $sortOrder);

        return Response::redirect('/admin/products/addons?parent_id=' . $parentProductId . '&msg=' . urlencode('Add-on linked.'));
    }

    public function detach(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $configId = (int) ($params['id'] ?? 0);

        // Look up the parent product for the redirect by reading the config
        // row directly.
        $db = \CodeVault\Support\App::container()->make(\CodeVault\Database::class);
        $config = $db->selectOne('SELECT * FROM product_addons WHERE id = ?', [$configId]);

        if ($config === null) {
            return Response::redirect('/admin/products/addons?error=' . urlencode('Add-on link not found.'));
        }

        $parentProductId = (int) $config['parent_product_id'];

        $this->addons->deleteById($configId);

        return Response::redirect('/admin/products/addons?parent_id=' . $parentProductId . '&msg=' . urlencode('Add-on unlinked.'));
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
            'title' => 'CodeVault Admin — Product Add-ons',
            'content' => $content,
        ]));
    }
}
