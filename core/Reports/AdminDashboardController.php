<?php

declare(strict_types=1);

namespace CodeVault\Reports;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Auth\AuthGuard;
use CodeVault\Billing\CancellationRequestRepository;
use CodeVault\Billing\InvoiceRepository;
use CodeVault\Billing\OrderRepository;
use CodeVault\Billing\ServiceRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Domains\DomainRepository;
use CodeVault\Hooks\HookPoints;
use CodeVault\Modules\WidgetModuleService;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Support\TicketRepository;
use CodeVault\View;
use DateTimeImmutable;

/**
 * Replaces the placeholder inline closure that used to live in
 * routes/web.php — that version only ever showed a client count and the
 * hook-point catalog, never the income/orders/tickets/renewals picture the
 * blueprint's own §4.3 spec calls for (R17).
 */
final class AdminDashboardController
{
    /** How far ahead "renewing soon" looks. */
    private const RENEWAL_WINDOW_DAYS = 7;

    /** WidgetModule::placement() value this page renders. */
    private const WIDGET_PLACEMENT = 'dashboard';

    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly ActivityLogger $activity,
        private readonly ClientRepository $clients,
        private readonly OrderRepository $orders,
        private readonly InvoiceRepository $invoices,
        private readonly TicketRepository $tickets,
        private readonly ServiceRepository $services,
        private readonly DomainRepository $domains,
        private readonly ReportRepository $reports,
        private readonly SvgChartRenderer $chart,
        private readonly WidgetModuleService $widgets,
        private readonly CancellationRequestRepository $cancellations,
        private readonly \CodeVault\Billing\CurrencyService $currency,
        private readonly \CodeVault\Billing\CurrencyRepository $currencies
    ) {
    }

    public function index(Request $request): Response
    {
        $admin = $this->guard->currentAdmin();

        if ($admin === null) {
            return Response::redirect('/login');
        }

        $content = $this->view->render('pages.admin-dashboard', [
            'admin' => $admin,
            'clientCount' => $this->clients->countAll(),
            'newClientsThisMonth' => $this->clients->countNewThisMonth(),
            'incomeThisMonth' => $this->invoices->totalPaidThisMonth(),
            // Per-currency breakdowns so the figures are honest on a
            // multi-currency install, plus the default currency for labelling
            // — the template used to hardcode "$".
            'incomeByCurrency' => $this->labelCurrencies($this->invoices->paidThisMonthByCurrency()),
            'overdueByCurrency' => $this->labelCurrencies($this->invoices->overdueByCurrency()),
            'defaultCurrency' => $this->currencies->default(),
            'pendingOrders' => $this->orders->countPending(),
            'pendingCancellations' => $this->cancellations->countPending(),
            'overdueInvoiceCount' => $this->invoices->countOverdue(),
            'overdueInvoiceTotal' => $this->invoices->sumOverdue(),
            'openTickets' => $this->tickets->countOpen(),
            'renewingServices' => $this->services->countDueForBilling(self::RENEWAL_WINDOW_DAYS),
            'renewingDomains' => $this->domains->countDueForRenewal(self::RENEWAL_WINDOW_DAYS),
            'revenueChart' => $this->chart->bar($this->sixMonthRevenue()),
            'recentActivity' => $this->activity->recent(10),
            'hookPointCount' => count(HookPoints::all()),
            'widgetOutputs' => array_map(
                static fn ($widget) => $widget->render(),
                $this->widgets->activeWidgetsForPlacement(self::WIDGET_PLACEMENT)
            ),
        ]);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Dashboard',
            'content' => $content,
        ]));
    }

    /**
     * Attaches each row's currency code and symbol.
     *
     * A NULL currency_id means the invoice never locked one, which resolves to
     * the system default — the same rule the client-facing pages follow.
     *
     * @param array<int, array{currency_id: ?int, amount: float, invoices: int}> $rows
     * @return array<int, array<string, mixed>>
     */
    private function labelCurrencies(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $currency = $this->currency->resolveLocked($row['currency_id']);

            $out[] = $row + [
                'code' => (string) ($currency['code'] ?? ''),
                'symbol' => (string) ($currency['symbol'] ?? ''),
                'is_default' => $row['currency_id'] === null,
            ];
        }

        return $out;
    }

    /**
     * incomeByMonth() only returns months that actually had a paid invoice
     * (sparse) — this fills the trailing 6-month window with zeros so the
     * chart always has exactly 6 bars, even for a brand-new install.
     *
     * @return array<int, array{label: string, value: float}>
     */
    private function sixMonthRevenue(): array
    {
        $now = new DateTimeImmutable('first day of this month');
        $years = array_unique([
            (int) $now->format('Y'),
            (int) $now->modify('-5 months')->format('Y'),
        ]);

        $byMonth = [];

        foreach ($years as $year) {
            foreach ($this->reports->incomeByMonth($year) as $row) {
                $byMonth[$row['month']] = (float) $row['total'];
            }
        }

        $points = [];
        $cursor = new DateTimeImmutable('first day of this month');
        $cursor = $cursor->modify('-5 months');

        for ($i = 0; $i < 6; $i++) {
            $key = $cursor->format('Y-m');
            $points[] = ['label' => $cursor->format('M'), 'value' => $byMonth[$key] ?? 0.0];
            $cursor = $cursor->modify('+1 month');
        }

        return $points;
    }
}
