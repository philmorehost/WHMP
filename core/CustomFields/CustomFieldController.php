<?php

declare(strict_types=1);

namespace CodeVault\CustomFields;

use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

final class CustomFieldController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly CustomFieldRepository $fields
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render('custom-fields.index', ['fields' => $this->fields->forType('client')]);
    }

    public function createForm(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render('custom-fields.form', ['field' => null, 'error' => null]);
    }

    public function store(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $name = trim((string) $request->input('name', ''));
        $type = (string) $request->input('type', 'text');
        $options = trim((string) $request->input('options', '')) ?: null;
        $required = (bool) $request->input('required', false);
        $adminOnly = (bool) $request->input('admin_only', false);

        if ($name === '') {
            return $this->render('custom-fields.form', ['field' => null, 'error' => 'Field name is required.']);
        }

        $this->fields->create('client', $name, $type, $options, $required, $adminOnly);

        return Response::redirect('/admin/custom-fields');
    }

    public function editForm(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $field = $this->fields->find((int) $params['id']);

        if ($field === null) {
            return Response::html('404 Not Found', 404);
        }

        return $this->render('custom-fields.form', ['field' => $field, 'error' => null]);
    }

    public function update(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $name = trim((string) $request->input('name', ''));
        $type = (string) $request->input('type', 'text');
        $options = trim((string) $request->input('options', '')) ?: null;
        $required = (bool) $request->input('required', false);
        $adminOnly = (bool) $request->input('admin_only', false);

        $this->fields->update($id, $name, $type, $options, $required, $adminOnly);

        return Response::redirect('/admin/custom-fields');
    }

    public function destroy(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $this->fields->delete((int) $params['id']);

        return Response::redirect('/admin/custom-fields');
    }

    private function requirePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::CUSTOM_FIELDS_MANAGE)) {
            return Response::html('403 Forbidden — missing custom_fields.manage permission', 403);
        }

        return null;
    }

    private function render(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Custom Fields',
            'content' => $content,
        ]));
    }
}
