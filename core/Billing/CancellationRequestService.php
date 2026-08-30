<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Database;
use CodeVault\Mail\EmailDispatcher;
use CodeVault\Provisioning\ProvisioningService;
use CodeVault\Provisioning\ServerRepository;

/**
 * Owns every cancellation path — client self-serve cancel, admin approval of
 * a request, and the cron's end-of-period sweep — so the rules are enforced
 * in one place:
 *
 *  - Cancelling a service cancels its unpaid invoices (the current invoice on
 *    an immediate cancel; the renewal invoice on an end-of-period request).
 *  - Shared-hosting control panels (cPanel/CyberPanel) are terminated on the
 *    server via the provisioning module — immediately for an immediate
 *    cancel, or when the end-of-period date arrives.
 *  - VPS / dedicated servers have no API termination for now: the service is
 *    marked cancelled per the mode and the client immediately stops seeing
 *    its IPs/management in the portal.
 *  - Every completed cancellation emails the admin a full report with the
 *    service details.
 */
final class CancellationRequestService
{
    /** Control-panel modules that support real server-side termination. */
    private const TERMINATABLE_MODULES = ['cpanel', 'cyberpanel'];

    public function __construct(
        private readonly CancellationRequestRepository $cancellations,
        private readonly ServiceRepository $services,
        private readonly EmailDispatcher $mail,
        private readonly Database $db,
        private readonly InvoiceRepository $invoices,
        private readonly ServerRepository $servers,
        private readonly ProvisioningService $provisioning
    ) {
    }

    /**
     * Client-side cancellation entry point.
     *
     *  - immediate → cancels the service now (terminating the server account
     *    when the module supports it), cancels the unpaid invoices, notifies,
     *    and returns 0 (no pending request is recorded for an instant cancel).
     *  - due_date / end_of_period → records a pending request whose
     *    cancellation_type is 'due_date' at the end of the current billing
     *    period (or an explicit date), and cancels the renewal invoices so the
     *    client is not billed again. The admin approves; the cron terminates
     *    at the date.
     *
     * @return int the request id, or 0 when the cancellation was immediate
     */
    public function clientRequestsCancellation(int $serviceId, int $clientId, string $type, string $reason, ?string $cancelDate = null): int
    {
        $service = $this->services->findById($serviceId);

        // Whichever mode, stop billing: cancel the service's unpaid invoices
        // (current invoice for immediate, renewal invoice for end-of-period).
        $this->invoices->cancelUnpaidForService($serviceId);

        if ($type === 'immediate') {
            $this->cancelService($serviceId);
            $this->notifyClientOfApproval($clientId, $serviceId);
            $this->notifyAdminsOfCancellationCompleted($serviceId, 'Immediate', 'completed', $reason);

            return 0;
        }

        $effectiveDate = $cancelDate !== null && $cancelDate !== ''
            ? $cancelDate
            : (string) ($service['next_due_date'] ?? '');

        $id = $this->cancellations->create($serviceId, $clientId, 'due_date', $reason, $effectiveDate);
        $this->notifyAdminsOfCancellationRequest($serviceId, $id, 'Scheduled', $reason);

        return $id;
    }

    public function requestCancellation(int $serviceId, int $clientId, string $type, string $reason, ?string $cancelDate = null): int
    {
        return $this->clientRequestsCancellation($serviceId, $clientId, $type, $reason, $cancelDate);
    }

    /**
     * Approve a cancellation and report its outcome so the admin page can show
     * exactly what happened:
     *   - 'completed' when the service is ALREADY cancelled/terminated (the
     *     admin may have cancelled it straight from the service page) or was
     *     immediately cancelled just now,
     *   - 'approved' for a scheduled (due-date) cancellation still awaiting its
     *     effective date (the cancellation-processor cron completes it then).
     *
     * Never throws — a provisioning hiccup must not 500 the admin's Approve
     * click.
     *
     * @return array{success: bool, message: string, status: string}
     */
    public function approveCancellation(int $requestId, int $adminId, ?string $notes = null): array
    {
        $request = $this->cancellations->findById($requestId);

        if ($request === null) {
            return ['success' => false, 'message' => 'Cancellation request not found.', 'status' => 'unknown'];
        }

        $serviceId = (int) $request['service_id'];
        $service = $this->services->findById($serviceId);
        $serviceStatus = $service !== null ? (string) $service['status'] : '';
        $clientId = (int) ($request['client_id'] ?? 0);

        // Idempotent: mark approved first (records reviewer + notes).
        $this->cancellations->approve($requestId, $adminId, $notes);

        // Billing stops regardless of the mode — cancel the service's unpaid
        // invoices (the renewal invoice for an end-of-period cancellation).
        $this->invoices->cancelUnpaidForService($serviceId);

        // Intelligent completion — if the service is already cancelled (e.g. the
        // admin set it cancelled from the service page), the request is done.
        if (in_array($serviceStatus, ['cancelled', 'terminated'], true)) {
            $this->cancellations->markCompleted($requestId);
            $this->notifyClientOfApproval($clientId, $serviceId);
            $this->notifyAdminsOfCancellationCompleted($serviceId, 'Immediate', 'completed', (string) ($request['reason'] ?? ''));

            return [
                'success' => true,
                'message' => 'Approved — the service is already cancelled, so the request was marked completed.',
                'status' => 'completed',
            ];
        }

        if (($request['cancellation_type'] ?? 'immediate') === 'immediate') {
            $cancelled = $this->cancelService($serviceId);
            $this->cancellations->markCompleted($requestId);
            $this->notifyClientOfApproval($clientId, $serviceId);
            $this->notifyAdminsOfCancellationCompleted($serviceId, 'Immediate', 'completed', (string) ($request['reason'] ?? ''));

            return [
                'success' => true,
                'message' => $cancelled
                    ? 'Approved — service cancelled and request completed.'
                    : 'Approved and completed, but the service could not be cancelled automatically — please review it from the service page.',
                'status' => 'completed',
            ];
        }

        // Scheduled (due date): stays approved until the effective date.
        $this->notifyClientOfApproval($clientId, $serviceId);
        $this->notifyAdminsOfCancellationCompleted($serviceId, 'Scheduled', 'approved', (string) ($request['reason'] ?? ''));

        return [
            'success' => true,
            'message' => 'Approved — the service will be cancelled on its scheduled date.',
            'status' => 'approved',
        ];
    }

    public function rejectCancellation(int $requestId, int $adminId, string $notes): void
    {
        $request = $this->cancellations->findById($requestId);
        if (!$request) return;

        $this->cancellations->reject($requestId, $adminId, $notes);
        $this->notifyClientOfRejection((int) $request['client_id'], (int) $request['service_id'], $notes);
    }

    /**
     * Explicitly complete a request (used when the admin sees the service is
     * already cancelled and wants the record to reflect it).
     *
     * @return array{success: bool, message: string, status: string}
     */
    public function markCompleted(int $requestId): array
    {
        $request = $this->cancellations->findById($requestId);

        if ($request === null) {
            return ['success' => false, 'message' => 'Cancellation request not found.', 'status' => 'unknown'];
        }

        $this->cancellations->markCompleted($requestId);
        $this->invoices->cancelUnpaidForService((int) $request['service_id']);
        $this->notifyAdminsOfCancellationCompleted(
            (int) $request['service_id'],
            (string) ($request['cancellation_type'] ?? 'due_date') === 'immediate' ? 'Immediate' : 'Scheduled',
            'completed',
            (string) ($request['reason'] ?? '')
        );

        return ['success' => true, 'message' => 'Cancellation marked as completed.', 'status' => 'completed'];
    }

    public function processImmediateCancellation(int $serviceId): void
    {
        $this->invoices->cancelUnpaidForService($serviceId);
        $this->cancelService($serviceId);
    }

    public function processDueCancellations(): void
    {
        $dueCancellations = $this->cancellations->findDueCancellations();

        foreach ($dueCancellations as $cancellation) {
            try {
                $this->invoices->cancelUnpaidForService((int) $cancellation['service_id']);
                $this->cancelService((int) $cancellation['service_id']);
                $this->cancellations->markCompleted((int) $cancellation['id']);
                $this->notifyAdminsOfCancellationCompleted(
                    (int) $cancellation['service_id'],
                    'Scheduled',
                    'completed',
                    (string) ($cancellation['reason'] ?? '')
                );
            } catch (\Throwable $e) {
                $this->notifyAdminsOfTerminationFailure((int) $cancellation['service_id'], $e->getMessage());
            }
        }
    }

    /**
     * Cancel a service. Control-panel hosting (cPanel/CyberPanel) is
     * terminated on the server through the provisioning module; VPS /
     * dedicated / local services have no API termination for now and are
     * simply marked cancelled. Never throws — a termination hiccup is
     * reported to admins and surfaced as false (the service is still marked
     * cancelled so billing stops).
     */
    private function cancelService(int $serviceId): bool
    {
        $service = $this->services->findById($serviceId);

        if ($service === null) {
            return false;
        }

        if ($this->shouldTerminateViaModule($service)) {
            try {
                $result = $this->provisioning->terminate($serviceId);
                $ok = (bool) ($result['success'] ?? false);

                if (!$ok) {
                    $this->notifyAdminsOfTerminationFailure($serviceId, (string) ($result['message'] ?? 'Unknown error'));
                }

                return $ok;
            } catch (\Throwable $e) {
                $this->notifyAdminsOfTerminationFailure($serviceId, $e->getMessage());

                return false;
            }
        }

        try {
            $this->services->updateStatus($serviceId, 'cancelled');
        } catch (\Throwable $e) {
            $this->notifyAdminsOfTerminationFailure($serviceId, $e->getMessage());

            return false;
        }

        return true;
    }

    /**
     * Whether a service lives on a control-panel server whose module can
     * terminate the account. VPS/dedicated modules (interserver-*, nocix-*)
     * are intentionally excluded — there is no API automation for them yet.
     *
     * @param array<string, mixed>|null $service
     */
    private function shouldTerminateViaModule(?array $service): bool
    {
        if ($service === null || empty($service['server_id'])) {
            return false;
        }

        $server = $this->servers->find((int) $service['server_id']);

        if ($server === null) {
            return false;
        }

        return in_array((string) ($server['module_slug'] ?? ''), self::TERMINATABLE_MODULES, true);
    }

    /**
     * Every notification below was written against `EmailDispatcher::send($to,
     * $subject, $body)`, which does not exist — the dispatcher's raw-content
     * entry point is `sendRaw($subject, $html, $to, $clientId)`, a different
     * name *and* a different argument order. The bodies are authored as plain
     * text, so they go through nl2br(htmlspecialchars(...)) before being
     * handed over as HTML.
     */
    private function sendPlainText(string $subject, string $body, string $toEmail, ?int $clientId = null): void
    {
        $this->mail->sendRaw(
            $subject,
            nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')),
            $toEmail,
            $clientId
        );
    }

    /** @return array<int, string> */
    private function adminEmails(): array
    {
        // `admins` has no is_active column — filtering on it threw on every
        // call, which on the termination-failure path meant the notification
        // meant to report a failure became a second, louder failure.
        return array_map(
            static fn (array $row): string => (string) $row['email'],
            $this->db->select('SELECT email FROM admins', [])
        );
    }

    private function notifyAdminsOfCancellationRequest(int $serviceId, int $requestId, string $type, string $reason): void
    {
        $service = $this->services->findById($serviceId);
        if (!$service) return;

        $typeLabel = $type === 'immediate' ? 'Immediate' : 'Scheduled';

        foreach ($this->adminEmails() as $email) {
            $this->sendPlainText(
                'New Cancellation Request',
                "A client has requested to cancel service: {$service['product_name']}\n\nType: {$typeLabel}\nReason: {$reason}\n\nPlease review in the admin dashboard.",
                $email
            );
        }
    }

    private function notifyClientOfApproval(int $clientId, int $serviceId): void
    {
        $client = $this->db->selectOne('SELECT email FROM clients WHERE id = ?', [$clientId]);
        if (!$client) return;

        $this->sendPlainText(
            'Cancellation Request Approved',
            'Your cancellation request has been approved. Your service will be cancelled according to your selected option.',
            (string) $client['email'],
            $clientId
        );
    }

    private function notifyClientOfRejection(int $clientId, int $serviceId, string $reason): void
    {
        $client = $this->db->selectOne('SELECT email FROM clients WHERE id = ?', [$clientId]);
        if (!$client) return;

        $this->sendPlainText(
            'Cancellation Request Rejected',
            "Your cancellation request has been rejected.\n\nReason: {$reason}",
            (string) $client['email'],
            $clientId
        );
    }

    private function notifyAdminsOfTerminationFailure(int $serviceId, string $errorMessage): void
    {
        $service = $this->services->findById($serviceId);
        if (!$service) return;

        foreach ($this->adminEmails() as $email) {
            $this->sendPlainText(
                'Service Termination Failed',
                "Failed to terminate service: {$service['product_name']}\n\nError: {$errorMessage}\n\nPlease review manually in the admin dashboard.",
                $email
            );
        }
    }

    /**
     * Full cancellation report to every admin — includes the service details
     * (product, id, domain/hostname, amount, cycle, mode, dates, reason) so
     * the admin has everything without opening the dashboard.
     */
    private function notifyAdminsOfCancellationCompleted(int $serviceId, string $modeLabel, string $statusLabel, string $reason): void
    {
        $service = $this->services->findById($serviceId);
        if ($service === null) {
            return;
        }

        $client = $this->db->selectOne('SELECT first_name, last_name, email FROM clients WHERE id = ?', [(int) ($service['client_id'] ?? 0)]);
        $clientName = $client ? trim(($client['first_name'] ?? '') . ' ' . ($client['last_name'] ?? '')) : 'Unknown client';
        $clientEmail = (string) ($client['email'] ?? '');

        $target = (string) ($service['domain'] ?? '') !== '' ? (string) $service['domain'] : (string) ($service['hostname'] ?? 'N/A');
        $amount = (float) ($service['amount'] ?? 0.0);

        $lines = [
            'CANCELLATION REPORT',
            '-------------------',
            "Status: {$statusLabel}",
            "Mode: {$modeLabel}",
            '',
            'SERVICE',
            "  ID: #{$service['id']}",
            "  Product: {$service['product_name']}",
            "  Billing: " . (string) ($service['billing_cycle'] ?? 'N/A') . ' @ ' . number_format($amount, 2),
            "  Domain/Hostname: {$target}",
            "  Next due date: " . (string) ($service['next_due_date'] ?? 'N/A'),
            "  Current status: " . (string) ($service['status'] ?? ''),
            '',
            'CLIENT',
            "  {$clientName} <{$clientEmail}> (ID #" . (int) ($service['client_id'] ?? 0) . ')',
            '',
            'REASON',
            '  ' . ($reason !== '' ? $reason : '(not provided)'),
        ];

        $body = implode("\n", $lines);

        foreach ($this->adminEmails() as $email) {
            $this->sendPlainText(
                "Cancellation {$statusLabel} — {$service['product_name']} #{$service['id']}",
                $body,
                $email
            );
        }
    }
}
