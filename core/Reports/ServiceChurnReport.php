<?php

declare(strict_types=1);

namespace CodeVault\Reports;

use CodeVault\Database;
use CodeVault\Modules\ReportModule;
use DateTimeImmutable;

/**
 * The reference ReportModule implementation (R27) — proves the SDK
 * end-to-end the same way TopClientsWidget did for WidgetModule in R21: a
 * real, useful report rather than a placeholder. Surfaces data
 * ReportRepository's built-in reports don't: cancelled/terminated services
 * grouped by product, with each service's recurring amount normalized to a
 * monthly-equivalent figure ("lost MRR") so cycles of different lengths
 * (monthly vs annually vs biennially, ...) can be compared and summed
 * meaningfully — a real WHMCS-parity concept ("lost revenue"/churn
 * reporting) with no existing surface in this app.
 *
 * `services` has no dedicated `cancelled_at`/`terminated_at` column (R5
 * schema), so `updated_at` is used as the churn-date proxy — accurate as
 * long as a cancelled/terminated service isn't updated again afterward,
 * which holds for how the app actually uses this status today.
 */
final class ServiceChurnReport implements ReportModule
{
    /** @var array<string, int> billing cycle -> months per cycle; one_time has no recurring value */
    private const MONTHS_PER_CYCLE = [
        'monthly' => 1,
        'quarterly' => 3,
        'semi_annually' => 6,
        'annually' => 12,
        'biennially' => 24,
        'triennially' => 36,
    ];

    public function __construct(
        private readonly Database $db
    ) {
    }

    public function metadata(): array
    {
        return [
            'name' => 'Service Churn',
            'description' => 'Cancelled/terminated services and lost monthly recurring revenue by product, for a date range.',
            'version' => '1.0.0',
            'author' => 'CodeVault',
        ];
    }

    public function configOptions(): array
    {
        return [];
    }

    public function filters(): array
    {
        return [
            'start_date' => ['type' => 'date', 'label' => 'Start Date'],
            'end_date' => ['type' => 'date', 'label' => 'End Date'],
        ];
    }

    public function generate(array $filters): array
    {
        $start = (string) ($filters['start_date'] ?? (new DateTimeImmutable('-1 year'))->format('Y-m-d'));
        $end = (string) ($filters['end_date'] ?? (new DateTimeImmutable())->format('Y-m-d'));

        $rows = $this->db->select(
            <<<'SQL'
            SELECT product_name, billing_cycle, status, amount
            FROM services
            WHERE status IN ('cancelled', 'terminated')
              AND DATE(updated_at) BETWEEN ? AND ?
            SQL,
            [$start, $end]
        );

        $byProduct = [];

        foreach ($rows as $row) {
            $product = (string) $row['product_name'];
            $byProduct[$product] ??= ['cancelled' => 0, 'terminated' => 0, 'lost_mrr' => 0.0];

            $byProduct[$product][(string) $row['status']]++;

            $months = self::MONTHS_PER_CYCLE[$row['billing_cycle']] ?? 0;
            if ($months > 0) {
                $byProduct[$product]['lost_mrr'] += ((float) $row['amount']) / $months;
            }
        }

        ksort($byProduct);

        $tableRows = [];
        foreach ($byProduct as $product => $stats) {
            $tableRows[] = [
                $product,
                $stats['cancelled'],
                $stats['terminated'],
                round($stats['lost_mrr'], 2),
            ];
        }

        return [
            'columns' => ['Product', 'Cancelled', 'Terminated', 'Lost Monthly Recurring Revenue'],
            'rows' => $tableRows,
        ];
    }
}
