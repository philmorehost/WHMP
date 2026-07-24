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

    private function notifyAdminsOfCancellationRequest(int $serviceId, int $requestId, string $type, string $reason): void
    {
        $service = $this->services->findById($serviceId);
        if (!$service) return;

        $adminEmails = $this->db->select('SELECT email FROM admins WHERE is_active = 1', []);
        $typeLabel = $type === 'immediate' ? 'Immediate' : 'Scheduled';
        
        foreach ($adminEmails as $admin) {
            $this->mail->send(
                (string) $admin['email'],
                'New Cancellation Request',
                "A client has requested to cancel service: {$service['product_name']}\n\nType: {$typeLabel}\nReason: {$reason}\n\nPlease review in the admin dashboard."
            );
        }
    }

    private function notifyClientOfApproval(int $clientId, int $serviceId): void
    {
        $client = $this->db->selectOne('SELECT email FROM clients WHERE id = ?', [$clientId]);
        if (!$client) return;

        $this->mail->send(
            (string) $client['email'],
            'Cancellation Request Approved',
            'Your cancellation request has been approved. Your service will be cancelled according to your selected option.'
        );
    }

    private function notifyClientOfRejection(int $clientId, int $serviceId, string $reason): void
    {
        $client = $this->db->selectOne('SELECT email FROM clients WHERE id = ?', [$clientId]);
        if (!$client) return;

        $this->mail->send(
            (string) $client['email'],
            'Cancellation Request Rejected',
            "Your cancellation request has been rejected.\n\nReason: {$reason}"
        );
    }

    private function notifyAdminsOfTerminationFailure(int $serviceId, string $errorMessage): void
    {
        $service = $this->services->findById($serviceId);
        if (!$service) return;

        $adminEmails = $this->db->select('SELECT email FROM admins WHERE is_active = 1', []);
        
        foreach ($adminEmails as $admin) {
            $this->mail->send(
                (string) $admin['email'],
                'Service Termination Failed',
                "Failed to terminate service: {$service['product_name']}\n\nError: {$errorMessage}\n\nPlease review manually in the admin dashboard."
            );
        }
    }
}
