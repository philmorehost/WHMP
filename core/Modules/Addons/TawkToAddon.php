<?php

declare(strict_types=1);

namespace CodeVault\Modules\Addons;

use CodeVault\Marketing\TawkToPages;
use CodeVault\Modules\AddonModule;
use CodeVault\Modules\AddonModuleRepository;

/**
 * Tawk.To Live Chat — paste the embed snippet and choose which pages the
 * chat widget appears on.
 *
 * The addon itself is the admin-facing config page, per the AddonModule
 * SDK's shape. Config (the pasted widget code + selected page keys) is
 * persisted through AddonModuleRepository::setConfig(), exactly like the
 * activation flag, so it survives a restart and doesn't need its own table.
 *
 * The widget itself is rendered into the client and admin layouts by the
 * partials/tawk-widget.php partial, which checks the repository the same
 * way DomainChangerAddon's client surface does — AddonModule::render() only
 * has a hook into the admin area, so the storefront rendering happens in a
 * layout partial that reads the saved config directly.
 *
 * CSP: the pasted snippet is an inline <script> that also injects a script
 * from https://embed.tawk.to and talks to *.tawk.to over WebSocket. Those
 * are only allowed while this addon is active with a saved widget code —
 * Kernel::handle() sets SecurityHeaders::setAllowTawkTo() and the partial
 * stamps each inline script tag with the per-request nonce, so script-src
 * keeps 'unsafe-inline' out.
 */
final class TawkToAddon implements AddonModule
{
    public const SLUG = 'tawk-to';

    public function __construct(
        private readonly AddonModuleRepository $repo
    ) {
    }

    public function metadata(): array
    {
        return [
            'name' => 'Tawk.To Live Chat',
            'description' => 'Add the Tawk.To live-chat widget and pick which pages it appears on — storefront, client area, and admin panel.',
            'version' => '1.0.0',
            'author' => 'CodeVault',
        ];
    }

    public function configOptions(): array
    {
        return [];
    }

    /** @return array{success: bool, message: string} */
    public function activate(): array
    {
        return ['success' => true, 'message' => 'Tawk.To Live Chat activated — open the addon and paste your widget code to start chatting.'];
    }

    /** @return array{success: bool, message: string} */
    public function deactivate(): array
    {
        return ['success' => true, 'message' => 'Tawk.To Live Chat deactivated — the widget is removed from every page until re-activated.'];
    }

    public function hooks(): array
    {
        return [];
    }

    public function render(array $params): string
    {
        $saved = false;
        $error = null;

        if (!empty($params['save'])) {
            $widgetCode = (string) ($params['widget_code'] ?? '');
            $submittedPages = (array) ($params['pages'] ?? []);

            $pages = [];
            foreach ($submittedPages as $key) {
                $key = (string) $key;
                if ($key === TawkToPages::ALL || isset(TawkToPages::PAGES[$key])) {
                    $pages[] = $key;
                }
            }

            if (in_array(TawkToPages::ALL, $pages, true)) {
                $pages = [TawkToPages::ALL];
            }

            $this->repo->setConfig(self::SLUG, [
                'widget_code' => $widgetCode,
                'pages' => $pages,
            ]);
            $saved = true;
        }

        $config = $this->repo->getConfig(self::SLUG);
        $widgetCode = (string) ($config['widget_code'] ?? '');
        $selected = (array) ($config['pages'] ?? []);
        $allSelected = in_array(TawkToPages::ALL, $selected, true);

        // Injected by AddonController::show() so render() stays unit-testable.
        $csrf = (string) ($params['csrf_field'] ?? '');

        $pageCheckboxes = '';
        foreach (TawkToPages::PAGES as $key => $label) {
            $checked = !$allSelected && in_array($key, $selected, true) ? ' checked' : '';
            $pageCheckboxes .= '<label style="display:flex;align-items:center;gap:var(--cv-space-2);cursor:pointer;">'
                . '<input type="checkbox" name="pages[]" value="' . e($key) . '"' . $checked . '> ' . e($label) . '</label>';
        }

        $allChecked = $allSelected ? ' checked' : '';
        $statusBadge = $allSelected
            ? '<span class="cv-badge cv-badge--success">Every page</span>'
            : (count($selected) > 0
                ? '<span class="cv-badge cv-badge--success">' . count($selected) . ' page' . (count($selected) === 1 ? '' : 's') . ' selected</span>'
                : '<span class="cv-badge cv-badge--neutral">No pages selected — widget hidden everywhere</span>');

        $banner = $saved
            ? '<div class="cv-badge cv-badge--success" style="display:block;padding:var(--cv-space-3);margin-bottom:var(--cv-space-4);">Tawk.To settings saved.</div>'
            : '';
        $errorHtml = $error !== null
            ? '<div class="cv-field-error" style="margin-bottom:var(--cv-space-4);">' . e($error) . '</div>'
            : '';

        $currentCode = $widgetCode === ''
            ? '<p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">No widget code saved yet. Paste the snippet from your Tawk.To dashboard to enable the chat widget.</p>'
            : '<p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">Widget code is saved. ' . $statusBadge . '</p>';

        $slug = self::SLUG;
        $widgetCodeEscaped = e($widgetCode);

        return $banner . $errorHtml . <<<HTML
        <form method="post" action="/admin/addons/{$slug}">
            {$csrf}
            <input type="hidden" name="save" value="1">
            <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
                <h2 class="cv-card__title">Chat Widget</h2>
                {$currentCode}
                <div class="cv-field">
                    <label class="cv-label">Tawk.To embed code</label>
                    <textarea class="cv-input" name="widget_code" rows="10" style="width:100%;font-family:monospace;font-size:var(--cv-text-xs);white-space:pre;" placeholder="&lt;!--Start of Tawk.to Script--&gt;&#10;&lt;script type=&quot;text/javascript&quot;&gt; ...">{$widgetCodeEscaped}</textarea>
                    <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);margin-top:var(--cv-space-1);">
                        Paste the full snippet from <strong>Tawk.To Dashboard → Admin → Chat Widget → Get Code</strong>.
                    </p>
                </div>
            </div>
            <div class="cv-card">
                <h2 class="cv-card__title">Show the widget on</h2>
                <label style="display:flex;align-items:center;gap:var(--cv-space-2);cursor:pointer;margin-bottom:var(--cv-space-2);font-weight:600;">
                    <input type="checkbox" name="pages[]" value="all"{$allChecked}> All pages (storefront, client area &amp; admin panel)
                </label>
                <div style="display:grid;gap:var(--cv-space-2);margin-bottom:var(--cv-space-3);padding-left:var(--cv-space-2);">
                    {$pageCheckboxes}
                </div>
                <button class="cv-btn" type="submit">Save Settings</button>
            </div>
        </form>
        HTML;
    }
}
