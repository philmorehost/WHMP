<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Mail\EmailDispatcher;
use CodeVault\Database;

final class OrderCancellationService
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly EmailDispatcher $mail,
        private readonly Database $db
    ) {
    }

    public function clientCancelOrder(int $orderId, int $clientId, string $reason): bool
    {
        $order = $this->orders->findById($orderId);
        if (!$order || (int)$order['client_id'] !== $clientId) {
            return false;
        }

        if (in_array($order['status'], ['cancelled', 'completed'])) {
            return false;
        }

        $this->db->update(
            'UPDATE orders SET is_cancelled = 1, cancelled_at = NOW(), cancellation_reason = ? WHERE id = ?',
            [$reason, $orderId]
        );

        $client = $this->db->selectOne('SELECT email, first_name FROM clients WHERE id = ?', [$clientId]);
        $this->notifyAdminOfCancellation($orderId, $client);
        $this->notifyClientOfCancellation($client, $orderId);

        return true;
    }

    // EmailDispatcher has no send() method — the raw-content entry point is
    // sendRaw($subject, $html, $to, $clientId), a different name and argument
    // order, so both notifications below were fatal on every call.
    private function notifyAdminOfCancellation(int $orderId, ?array $client): void
    {
        $name = $client['first_name'] ?? 'A client';
        $admins = $this->db->select('SELECT email FROM admins', []);
        foreach ($admins as $admin) {
            $this->mail->sendRaw(
                "Order #$orderId Cancelled by Client",
                htmlspecialchars("Client {$name} has cancelled order #$orderId. Please review in the admin dashboard.", ENT_QUOTES, 'UTF-8'),
                (string)$admin['email']
            );
        }
    }

    private function notifyClientOfCancellation(?array $client, int $orderId): void
    {
        if (!$client) return;
        $this->mail->sendRaw(
            "Order Cancellation Confirmed",
            htmlspecialchars("Your order #$orderId has been cancelled successfully.", ENT_QUOTES, 'UTF-8'),
            (string)$client['email'],
            isset($client['id']) ? (int)$client['id'] : null
        );
    }
}
