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

    private function notifyAdminOfCancellation(int $orderId, ?array $client): void
    {
        $admins = $this->db->select('SELECT email FROM admins WHERE is_active = 1', []);
        foreach ($admins as $admin) {
            $this->mail->send(
                (string)$admin['email'],
                "Order #$orderId Cancelled by Client",
                "Client {$client['first_name']} has cancelled order #$orderId. Please review in the admin dashboard."
            );
        }
    }

    private function notifyClientOfCancellation(?array $client, int $orderId): void
    {
        if (!$client) return;
        $this->mail->send(
            (string)$client['email'],
            "Order Cancellation Confirmed",
            "Your order #$orderId has been cancelled successfully."
        );
    }
}
