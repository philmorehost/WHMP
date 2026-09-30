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

    // EmailDispatcher has no send() method — the raw-content entry point is
    // sendRaw($subject, $html, $to, $clientId), a different name and argument
    // order, so both notifications below were fatal on every call.
    private function notifyAdminOfCancellation(int $invoiceId, ?array $client): void
    {
        $name = $client['first_name'] ?? 'A client';
        $admins = $this->db->select('SELECT email FROM admins', []);
        foreach ($admins as $admin) {
            // The body goes over as PLAIN TEXT, interpolated raw. It used to be wrapped
            // in htmlspecialchars(), which converted it twice -- sendRaw() ->
            // wrapInModernLayout() -> FormattedText::toHtml() escapes and formats it
            // itself -- so a client whose name contains an apostrophe or ampersand
            // arrived doubly escaped (O'Brien rendered as O&#039;Brien). Escaping the
            // raw value here would be the bug, not the safety: toHtml() escapes the
            // whole body exactly once, downstream.
            // See tests/Unit/EmailBodyRenderingTest.php.
            $this->mail->sendRaw(
                "Invoice #$invoiceId Cancelled by Client",
                "Client {$name} has cancelled invoice #$invoiceId. This will prevent automated billing attempts.",
                (string)$admin['email']
            );
        }
    }

    private function notifyClientOfCancellation(?array $client, int $invoiceId): void
    {
        if (!$client) return;
        $this->mail->sendRaw(
            "Invoice Cancellation Confirmed",
            "Invoice #$invoiceId has been cancelled. You will not be billed for this invoice.",
            (string)$client['email'],
            isset($client['id']) ? (int)$client['id'] : null
        );
    }
}
