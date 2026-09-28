<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Auth\AuthGuard;
use CodeVault\Billing\CurrencyService;
use CodeVault\Clients\ClientRepository;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Session\SessionManager;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

/**
 * The payout queue: what resellers have asked for, and answering it.
 *
 * THE QUEUE IS A WORK LIST, so pending requests are listed oldest first — the one
 * that has been waiting longest is the one that has been waiting longest — and the
 * decided ones are kept below it as history rather than mixed in.
 *
 * WHAT THIS CONTROLLER DELIBERATELY DOES NOT DO: move money. The admin makes a
 * bank transfer in their own banking interface, comes back, and records the
 * reference. That is the whole design of Phase B (plan §9 item 8), and it is the
 * right first version of anything that pays out: the failure mode is a wrong
 * reference that a human can see, not a wrong transfer that nobody can undo.
 *
 * A reference is mandatory on payment and enforced in ResellerPayoutService, not
 * here. Validating it in the controller would be a second place to remember, and
 * the one that matters is the one guarding the write.
 *
 * Every action re-derives the admin from the guard and re-checks the permission;
 * none of them trusts an id from the form to decide who is acting.
 */
final class AdminResellerPayoutsController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly SessionManager $session,
        private readonly ResellerPayoutService $payouts,
        private readonly ResellerStoreRepository $stores,
        private readonly ClientRepository $clients,
        private readonly CurrencyService $currency,
        private readonly ActivityLogger $activity
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $summary = $this->payouts->adminSummary();

        return $this->render('reseller.admin-payouts', [
            'pending' => $this->withStores($summary['pending']),
            'decided' => $this->withStores($summary['decided']),
            'totals' => $summary['totals'],
            'baseCode' => strtoupper(trim($this->currency->codeFor(null))),
            'labels' => ResellerPayoutService::STATUS_LABELS,
            'notice' => $this->session->pullFlash('reseller_notice'),
            'error' => $this->session->pullFlash('reseller_error'),
        ]);
    }

    /**
     * Record that the transfer was made.
     *
     * The service refuses a blank reference, so the admin is sent back with a
     * reason rather than a payout recorded as paid with nothing tying it to a bank
     * line.
     */
    public function markPaid(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $payoutId = (int) ($params['payoutId'] ?? 0);
        $reference = trim((string) $request->input('reference', ''));

        $result = $this->payouts->markPaid($payoutId, $reference, $this->adminId());

        if (!$result['ok']) {
            $this->session->flash('reseller_error', (string) $result['error']);

            return Response::redirect('/admin/resellers/payouts');
        }

        $this->activity->log(
            'admin',
            $this->adminId(),
            'reseller.payout.paid',
            null,
            null,
            'Recorded reseller payout #' . $payoutId . ' as paid, reference ' . $reference,
            $request->ip()
        );

        $this->session->flash(
            'reseller_notice',
            'Payout #' . $payoutId . ' recorded as paid against reference ' . $reference
            . '. The account was already debited when the request was made, so nothing changed there.'
        );

        return Response::redirect('/admin/resellers/payouts');
    }

    /**
     * Refuse the request, returning the funds to the reseller's account.
     *
     * A note is optional, unlike the payment reference: refusing does not need to
     * be tied to a bank line, and the reversal is self-explanatory in the ledger.
     * It is still strongly worth writing, and the form says so.
     */
    public function reject(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $payoutId = (int) ($params['payoutId'] ?? 0);
        $note = trim((string) $request->input('note', ''));

        $result = $this->payouts->reject($payoutId, $note === '' ? null : $note, $this->adminId());

        if (!$result['ok']) {
            $this->session->flash('reseller_error', (string) $result['error']);

            return Response::redirect('/admin/resellers/payouts');
        }

        $this->activity->log(
            'admin',
            $this->adminId(),
            'reseller.payout.rejected',
            null,
            null,
            'Rejected reseller payout #' . $payoutId . ($note === '' ? '' : ': ' . $note),
            $request->ip()
        );

        $this->session->flash(
            'reseller_notice',
            'Payout #' . $payoutId . ' rejected and the funds returned to the reseller\'s account.'
        );

        return Response::redirect('/admin/resellers/payouts');
    }

    /**
     * Attach the store and the owner to each payout row.
     *
     * The rows carry a reseller id and a client id, which are numbers an admin
     * cannot act on. Looked up here rather than joined in the repository so the
     * repository stays storage-only and the identity of a row is a presentation
     * concern.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function withStores(array $rows): array
    {
        foreach ($rows as $i => $row) {
            $store = $this->stores->find((int) $row['reseller_id']);
            $client = $row['client_id'] === null ? null : $this->clients->find((int) $row['client_id']);

            $rows[$i]['store'] = $store;
            $rows[$i]['owner'] = $client === null
                ? null
                : trim((string) ($client['first_name'] ?? '') . ' ' . (string) ($client['last_name'] ?? ''));

            // The row stores a currency ID, which is not a label. Resolved here so
            // the view does not need a currency service to print an amount, and so
            // there is one place deciding what unit a payout was denominated in.
            $rows[$i]['amount_code'] = $row['currency_id'] === null
                ? strtoupper(trim($this->currency->codeFor(null)))
                : strtoupper(trim($this->currency->codeFor((int) $row['currency_id'])));
        }

        return $rows;
    }

    private function adminId(): ?int
    {
        $admin = $this->guard->current();

        return $admin === null ? null : (int) $admin['id'];
    }

    private function requirePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::RESELLERS_MANAGE)) {
            return Response::html('403 Forbidden — missing resellers.manage permission', 403);
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    private function render(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Reseller payouts',
            'content' => $content,
        ]));
    }
}
