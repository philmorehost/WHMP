<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Domains\DomainRenewalBillingService;
use CodeVault\Domains\DomainRepository;
use CodeVault\Request;
use CodeVault\Response;

/**
 * Client-initiated renewals ("Renew Now") for services and domains —
 * blueprint §4.4, the client-facing counterpart to the cron sweeps in
 * RecurringBillingService and DomainRenewalBillingService.
 *
 * The two entities share one controller because the shape is identical and
 * the difference is a single delegated call; splitting it would duplicate
 * the ownership check, the redirect contract and the manual-payment note
 * for no gain. It is deliberately separate from ClientServiceController /
 * ClientDomainController so neither of those — both already carrying every
 * management action for their entity — gains two more collaborators for a
 * feature that touches neither of them.
 *
 * What happens on a click: an unpaid renewal invoice is raised (or an
 * existing one for this cycle is reused) and the client is redirected to it.
 * That is the whole of it. The renewal — the due-date roll-forward for a
 * service, the registrar renew() for a domain — only happens once the
 * invoice is *paid*, through the InvoicePaid listeners wired in Kernel:
 *
 *   - online payment: the gateway callback settles the invoice, the listener
 *     fires, and the renewal takes effect in the same request;
 *   - manual/bank transfer: the invoice stays unpaid until an admin confirms
 *     receipt (PaymentService), and the renewal then takes effect. The client
 *     is therefore sent to the invoice, never told "renewed", so the state
 *     they see is the real one.
 *
 * Ownership is enforced by looking the row up and comparing client_id — an
 * id belonging to anyone else is a 404, exactly like the other client pages.
 */
final class ClientRenewalController
{
    public function __construct(
        private readonly ClientAuthGuard $guard,
        private readonly ServiceRepository $services,
        private readonly DomainRepository $domains,
        private readonly RecurringBillingService $serviceRenewals,
        private readonly DomainRenewalBillingService $domainRenewals
    ) {
    }

    /**
     * Raise (or reuse) the renewal invoice for one of the client's services
     * and send them to it.
     */
    public function service(Request $request, array $params): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $serviceId = (int) $params['id'];
        $service = $this->services->find($serviceId);

        if ($service === null || (int) $service['client_id'] !== (int) $client['id']) {
            return Response::html('404 Not Found', 404);
        }

        $result = $this->serviceRenewals->generateForService($serviceId);

        if (($result['success'] ?? false) !== true) {
            return $this->back(
                "/client/services/{$serviceId}",
                'err',
                (string) ($result['message'] ?? 'A renewal invoice could not be started for this service.')
            );
        }

        return $this->toInvoice((int) $result['invoiceId']);
    }

    /**
     * Raise (or reuse) the renewal invoice for one of the client's domains
     * and send them to it.
     */
    public function domain(Request $request, array $params): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $domainId = (int) $params['id'];
        $domain = $this->domains->find($domainId);

        if ($domain === null || (int) $domain['client_id'] !== (int) $client['id']) {
            return Response::html('404 Not Found', 404);
        }

        $result = $this->domainRenewals->generateForDomain($domainId);

        if (($result['success'] ?? false) !== true) {
            return $this->back(
                "/client/domains/{$domainId}",
                'error',
                (string) ($result['message'] ?? 'A renewal invoice could not be started for this domain.')
            );
        }

        return $this->toInvoice((int) $result['invoiceId']);
    }

    /**
     * The `payment=renewal` flag tells the invoice page to show the
     * "pay online and it renews instantly / pay by transfer and contact us"
     * notice — see resources/views/billing/client-invoice-show.php.
     */
    private function toInvoice(int $invoiceId): Response
    {
        return Response::redirect("/client/invoices/{$invoiceId}?payment=renewal");
    }

    private function back(string $path, string $param, string $message): Response
    {
        return Response::redirect($path . '?' . $param . '=' . urlencode($message));
    }
}
