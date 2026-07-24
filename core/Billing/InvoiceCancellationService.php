<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Mail\EmailDispatcher;
use CodeVault\Database;

final class InvoiceCancellationService
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly EmailDispatcher $mail,
        private readonly Database $db
    ) {
    }

    public function clientCancelInvoice(int $invoiceId, int $clientId, string $reason): bool
    {
        $invoice = $this->invoices->findById($invoiceId);
        if (!$invoice || (int)$invoice['client_id'] !== $clientId) {
            return false;
        }

        if ($invoice['status'] === 'paid' || $invoice['status'] === 'cancelled') {
            return false;
        }

        $this->db->update(
            'UPDATE invoices SET is_cancelled = 1, cancelled_at = NOW(), cancellation_reason = ? WHERE id = ?',
            [$reason, $invoiceId]
        );

        $client = $this->db->selectOne('SELECT email, first_name FROM clients WHERE id = ?', [$clientId]);
        $this->notifyAdminOfCancellation($invoiceId, $client);
        $this->notifyClientOfCancellation($client, $invoiceId);

        return true;
    }

    public function isCancelled(int $invoiceId): bool
    {
        $invoice = $this->invoices->findById($invoiceId);
        return $invoice && (bool)$invoice['is_cancelled'];
    }

    private function notifyAdminOfCancellation(int $invoiceId, ?array $client): void
    {
        $admins = $this->db->select('SELECT email FROM admins WHERE is_active = 1', []);
        foreach ($admins as $admin) {
            $this->mail->send(
                (string)$admin['email'],
                "Invoice #$invoiceId Cancelled by Client",
                "Client {$client['first_name']} has cancelled invoice #$invoiceId. This will prevent automated billing attempts."
            );
        }
    }

    private function notifyClientOfCancellation(?array $client, int $invoiceId): void
    {
        if (!$client) return;
        $this->mail->send(
            (string)$client['email'],
            "Invoice Cancellation Confirmed",
            "Invoice #$invoiceId has been cancelled. You will not be billed for this invoice."
        );
    }
}
