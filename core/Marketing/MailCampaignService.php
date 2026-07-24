<?php

declare(strict_types=1);

namespace CodeVault\Marketing;

use CodeVault\Clients\ClientRepository;
use CodeVault\Mail\EmailDispatcher;

/**
 * Mass-mail campaigns (blueprint §4.4/§5 marketing automation): sends an
 * admin-composed subject/body to every active client in a group (or every
 * active client, if no group is set), with a per-recipient open-tracking
 * pixel appended to the body.
 */
final class MailCampaignService
{
    public function __construct(
        private readonly MailCampaignRepository $campaigns,
        private readonly ClientRepository $clients,
        private readonly EmailDispatcher $mail
    ) {
    }

    public function send(int $campaignId): int
    {
        $campaign = $this->campaigns->find($campaignId);

        if ($campaign === null || $campaign['status'] !== 'draft') {
            return 0;
        }

        $this->campaigns->markSending($campaignId);

        if (!empty($campaign['client_id'])) {
            $singleClient = $this->clients->find((int) $campaign['client_id']);
            $recipients = $singleClient !== null ? [$singleClient] : [];
        } else {
            $clientGroupId = $campaign['client_group_id'] !== null ? (int) $campaign['client_group_id'] : null;
            $recipients = $this->clients->activeForGroup($clientGroupId);
        }

        $sent = 0;

        foreach ($recipients as $client) {
            $openToken = bin2hex(random_bytes(32));
            $this->campaigns->addRecipient($campaignId, (int) $client['id'], $openToken);

            $html = $campaign['body'] . "<img src=\"/campaigns/track/{$openToken}\" width=\"1\" height=\"1\" alt=\"\" style=\"display:none;\">";

            $this->mail->sendRaw($campaign['subject'], $html, $client['email'], (int) $client['id']);
            $sent++;
        }

        $this->campaigns->markSent($campaignId);

        return $sent;
    }
}
