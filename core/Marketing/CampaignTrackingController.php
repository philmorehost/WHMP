<?php

declare(strict_types=1);

namespace CodeVault\Marketing;

use CodeVault\Request;
use CodeVault\Response;

final class CampaignTrackingController
{
    // A 1x1 transparent GIF, the standard email open-tracking pixel payload.
    private const PIXEL_BASE64 = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBTAA7';

    public function __construct(
        private readonly MailCampaignRepository $campaigns
    ) {
    }

    public function pixel(Request $request, array $params): Response
    {
        $this->campaigns->recordOpen((string) $params['token']);

        return (new Response((string) base64_decode(self::PIXEL_BASE64), 200))
            ->withHeader('Content-Type', 'image/gif')
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate');
    }
}
