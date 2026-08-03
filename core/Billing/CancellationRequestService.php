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

    public function approveCancellation(int $requestId, int $adminId, ?string $notes = null): void
    {
        $request = $this->cancellations->findById($requestId);
        if (!$request) return;

        $this->cancellations->approve($requestId, $adminId, $notes);

        if ($request['cancellation_type'] === 'immediate') {
            $this->processImmediateCancellation((int) $request['service_id']);
        }

        $this->notifyClientOfApproval((int) $request['client_id'], (int) $request['service_id']);
    }

    public function rejectCancellation(int $requestId, int $adminId, string $notes): void
    {
        $request = $this->cancellations->findById($requestId);
        if (!$request) return;

        $this->cancellations->reject($requestId, $adminId, $notes);
        $this->notifyClientOfRejection((int) $request['client_id'], (int) $request['service_id'], $notes);
    }

    public function processImmediateCancellation(int $serviceId): void
    {
        $service = $this->services->findById($serviceId);
        if (!$service) return;

        $this->services->updateStatus($serviceId, 'cancelled');
        
        if ($service['server_id']) {
            try {
                // Termination logic would go here
                // This would call the provisioning module to terminate on the server
            } catch (\Throwable $e) {
                $this->notifyAdminsOfTerminationFailure($serviceId, $e->getMessage());
            }
        }
    }

    public function processDueCancellations(): void
    {
        $dueCancellations = $this->cancellations->findDueCancellations();

        foreach ($dueCancellations as $cancellation) {
            try {
                $this->processImmediateCancellation((int) $cancellation['service_id']);
                $this->cancellations->markCompleted((int) $cancellation['id']);
            } catch (\Throwable $e) {
                $this->notifyAdminsOfTerminationFailure((int) $cancellation['service_id'], $e->getMessage());
            }
        }
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
