<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Database;
use CodeVault\Mail\EmailDispatcher;

final class CancellationRequestService
{
    public function __construct(
        private readonly CancellationRequestRepository $cancellations,
        private readonly ServiceRepository $services,
        private readonly EmailDispatcher $mail,
        private readonly Database $db
    ) {
    }

    public function requestCancellation(int $serviceId, int $clientId, string $type, string $reason, ?string $cancelDate = null): int
    {
        $id = $this->cancellations->create($serviceId, $clientId, $type, $reason, $cancelDate);
        $this->notifyAdminsOfCancellationRequest($serviceId, $id, $type, $reason);
        return $id;
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

        // Idempotent: mark approved first (records reviewer + notes).
        $this->cancellations->approve($requestId, $adminId, $notes);

        // Intelligent completion — if the service is already cancelled (e.g. the
        // admin set it cancelled from the service page), the request is done.
        if (in_array($serviceStatus, ['cancelled', 'terminated'], true)) {
            $this->cancellations->markCompleted($requestId);
            $this->notifyClientOfApproval((int) $request['client_id'], $serviceId);

            return [
                'success' => true,
                'message' => 'Approved — the service is already cancelled, so the request was marked completed.',
                'status' => 'completed',
            ];
        }

        if (($request['cancellation_type'] ?? 'immediate') === 'immediate') {
            $cancelled = $this->cancelService($serviceId);
            $this->cancellations->markCompleted($requestId);
            $this->notifyClientOfApproval((int) $request['client_id'], $serviceId);

            return [
                'success' => true,
                'message' => $cancelled
                    ? 'Approved — service cancelled and request completed.'
                    : 'Approved and completed, but the service could not be cancelled automatically — please review it from the service page.',
                'status' => 'completed',
            ];
        }

        // Scheduled (due date): stays approved until the effective date.
        $this->notifyClientOfApproval((int) $request['client_id'], $serviceId);

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

        return ['success' => true, 'message' => 'Cancellation marked as completed.', 'status' => 'completed'];
    }

    public function processImmediateCancellation(int $serviceId): void
    {
        $this->cancelService($serviceId);
    }

    public function processDueCancellations(): void
    {
        $dueCancellations = $this->cancellations->findDueCancellations();

        foreach ($dueCancellations as $cancellation) {
            try {
                $this->cancelService((int) $cancellation['service_id']);
                $this->cancellations->markCompleted((int) $cancellation['id']);
            } catch (\Throwable $e) {
                $this->notifyAdminsOfTerminationFailure((int) $cancellation['service_id'], $e->getMessage());
            }
        }
    }

    /**
     * Cancel a service: mark it cancelled locally (idempotent — an already-
     * cancelled service is simply re-stamped) and best-effort terminate on the
     * provisioning server. Never throws; a failure is reported to admins and
     * surfaced as false so the caller can say the service still needs review.
     */
    private function cancelService(int $serviceId): bool
    {
        $service = $this->services->findById($serviceId);

        if ($service === null) {
            return false;
        }

        try {
            $this->services->updateStatus($serviceId, 'cancelled');
        } catch (\Throwable $e) {
            $this->notifyAdminsOfTerminationFailure($serviceId, $e->getMessage());

            return false;
        }

        if (!empty($service['server_id'])) {
            try {
                // Termination would go through the provisioning module here.
            } catch (\Throwable $e) {
                $this->notifyAdminsOfTerminationFailure($serviceId, $e->getMessage());

                return false;
            }
        }

        return true;
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
}
