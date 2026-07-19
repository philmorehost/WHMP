<?php

declare(strict_types=1);

namespace CodeVault\Reports;

use CodeVault\Billing\InvoiceRepository;
use CodeVault\Modules\WidgetModule;

/**
 * The reference WidgetModule implementation (R21) — proves the SDK
 * end-to-end the same way SystemDiagnosticsAddon did for AddonModule in
 * R20: a real, useful widget rather than a placeholder. Ranks clients by
 * all-time paid revenue, a WHMCS-parity concept (§4.4 Reports engine names
 * "top clients") that had no dashboard surface until now.
 */
final class TopClientsWidget implements WidgetModule
{
    private const LIMIT = 5;

    public function __construct(
        private readonly InvoiceRepository $invoices
    ) {
    }

    public function metadata(): array
    {
        return [
            'name' => 'Top Clients by Revenue',
            'description' => 'Ranks clients by all-time paid invoice total.',
            'version' => '1.0.0',
            'author' => 'CodeVault',
        ];
    }

    public function configOptions(): array
    {
        return [];
    }

    public function placement(): string
    {
        return 'dashboard';
    }

    public function render(): string
    {
        $clients = $this->invoices->topClientsByRevenue(self::LIMIT);

        if ($clients === []) {
            return <<<'HTML'
            <div class="cv-card">
                <h2 class="cv-card__title">Top Clients by Revenue</h2>
                <p style="color:var(--cv-text-secondary);">No paid invoices yet.</p>
            </div>
            HTML;
        }

        $rows = '';
        foreach ($clients as $rank => $client) {
            $name = trim($client['first_name'] . ' ' . $client['last_name']);
            $rows .= '<tr>'
                . '<td>' . ((int) $rank + 1) . '</td>'
                . '<td><a href="/admin/clients/' . (int) $client['client_id'] . '">' . e($name) . '</a><div style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);">' . e($client['email']) . '</div></td>'
                . '<td>' . e(number_format($client['total_paid'], 2)) . '</td>'
                . '</tr>';
        }

        return <<<HTML
        <div class="cv-card">
            <h2 class="cv-card__title">Top Clients by Revenue</h2>
            <table class="cv-table">
                <thead><tr><th>#</th><th>Client</th><th>Paid</th></tr></thead>
                <tbody>{$rows}</tbody>
            </table>
        </div>
        HTML;
    }
}
