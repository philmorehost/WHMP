<?php

declare(strict_types=1);

namespace CodeVault\Catalog;

use CodeVault\Auth\AuthGuard;
use CodeVault\Database;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

final class ProductGroupController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly ProductGroupRepository $groups,
        private readonly ProductRepository $products,
        private readonly Database $db
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render('catalog.groups-index', ['groups' => $this->groups->all(), 'error' => null]);
    }

    public function store(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $name = trim((string) $request->input('name', ''));
        $description = trim((string) $request->input('description', '')) ?: null;

        if ($name !== '') {
            $this->groups->create($name, $description);
        }

        return Response::redirect('/admin/products/groups');
    }

    public function update(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $name = trim((string) $request->input('name', ''));
        $description = trim((string) $request->input('description', '')) ?: null;

        if ($name !== '') {
            $this->groups->update($id, $name, $description);
        }

        return Response::redirect('/admin/products/groups');
    }

    public function destroy(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $db = $this->db;

        $productCount = (int) $db->selectOne("SELECT COUNT(*) AS c FROM products WHERE product_group_id = ?", [$id])['c'];
        $action = $request->input('group_action');

        if ($productCount > 0 && $action === null) {
            $allGroups = $this->groups->all();
            $targetGroups = array_filter($allGroups, static fn($g) => (int)$g['id'] !== $id);
            $group = $db->selectOne("SELECT * FROM product_groups WHERE id = ?", [$id]);
            
            return $this->render('catalog.group-migrate-delete', [
                'group' => $group,
                'productCount' => $productCount,
                'targetGroups' => $targetGroups,
            ]);
        }

        if ($productCount > 0) {
            if ($action === 'migrate') {
                $targetGroupId = (int) $request->input('migrate_to_group_id');
                $db->update("UPDATE products SET product_group_id = ? WHERE product_group_id = ?", [$targetGroupId, $id]);
            } elseif ($action === 'delete_all') {
                $productIds = array_column($db->select("SELECT id FROM products WHERE product_group_id = ?", [$id]), 'id');
                foreach ($productIds as $pId) {
                    $fallbackProduct = $db->selectOne("SELECT id FROM products WHERE product_group_id != ? LIMIT 1", [$id]);
                    if ($fallbackProduct !== null) {
                        $db->update("UPDATE services SET product_id = ? WHERE product_id = ?", [$fallbackProduct['id'], $pId]);
                    }
                    $db->delete("DELETE FROM product_pricing WHERE product_id = ?", [$pId]);
                    $db->delete("DELETE FROM product_configurable_option_groups WHERE product_id = ?", [$pId]);
                    $db->delete("DELETE FROM products WHERE id = ?", [$pId]);
                }
            }
        }

        $this->groups->delete($id);

        return Response::redirect('/admin/products/groups');
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
            'title' => 'CodeVault Admin — Product Groups',
            'content' => $content,
        ]));
    }
}
