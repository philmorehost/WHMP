<?php

declare(strict_types=1);

namespace CodeVault\Marketing;

use CodeVault\Auth\AuthGuard;
use CodeVault\Clients\ClientGroupRepository;
use CodeVault\Catalog\ProductRepository;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

final class MailCampaignController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly MailCampaignRepository $campaigns,
        private readonly ClientGroupRepository $groups,
        private readonly MailCampaignService $service,
        private readonly \CodeVault\Clients\ClientRepository $clients,
        private readonly \CodeVault\Settings\SettingsRepository $settings,
        private readonly ProductRepository $products
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render('marketing.campaigns-index', [
            'campaigns' => $this->campaigns->all(),
            'groups' => $this->groups->all(),
            'clients' => $this->clients->all(),
            'products' => $this->products->all(),
            'error' => null
        ]);
    }

    public function store(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $subject = trim((string) $request->input('subject', ''));
        $body = trim((string) $request->input('body', ''));
        $targetType = (string) $request->input('target_type', 'all');

        $groupId = null;
        $clientId = null;
        $externalEmails = null;
        $onlyInactive = false;
        $productIds = null;
        if ($targetType === 'group' && (string) $request->input('client_group_id', '') !== '') {
            $groupId = (int) $request->input('client_group_id');
        } elseif ($targetType === 'individual' && (string) $request->input('client_id', '') !== '') {
            $clientId = (int) $request->input('client_id');
        } elseif ($targetType === 'external') {
            // Normalise at save time so the stored list is exactly what will be
            // sent — the admin can see on the campaign what it resolved to,
            // rather than discovering typos only after sending.
            $parsed = MailCampaignService::parseExternalEmails((string) $request->input('external_emails', ''));

            if ($parsed === []) {
                return $this->render('marketing.campaigns-index', [
                    'campaigns' => $this->campaigns->all(),
                    'groups' => $this->groups->all(),
                    'clients' => $this->clients->all(),
                    'products' => $this->products->all(),
                    'error' => 'Enter at least one valid email address for an external campaign.'
                ]);
            }

            $externalEmails = implode("\n", $parsed);
        } elseif ($targetType === 'inactive') {
            // Active accounts with no active service or domain — the
            // re-engagement audience. Stored as a flag, resolved at send time
            // so the list is always current.
            $onlyInactive = true;
        } elseif ($targetType === 'products') {
            // WHMCS-style product audience: clients holding an active or
            // suspended service of any selected product. Normalised to a
            // canonical CSV at save time; at least one product is required
            // (an empty product list must never silently mean "everyone").
            $productIds = MailCampaignService::parseProductIds($request->input('product_ids'));

            if ($productIds === '') {
                return $this->render('marketing.campaigns-index', [
                    'campaigns' => $this->campaigns->all(),
                    'groups' => $this->groups->all(),
                    'clients' => $this->clients->all(),
                    'products' => $this->products->all(),
                    'error' => 'Select at least one product for a product-targeted campaign.'
                ]);
            }
        }

        if ($subject === '' || $body === '') {
            return $this->render('marketing.campaigns-index', [
                'campaigns' => $this->campaigns->all(),
                'groups' => $this->groups->all(),
                'clients' => $this->clients->all(),
                'products' => $this->products->all(),
                'error' => 'Subject and body are required.'
            ]);
        }

        $this->campaigns->create($subject, $body, $groupId, $clientId, $externalEmails, $onlyInactive, $productIds);

        return Response::redirect('/admin/campaigns');
    }

    public function show(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $campaign = $this->campaigns->find((int) $params['id']);

        if ($campaign === null) {
            return Response::html('404 Not Found', 404);
        }

        return $this->render('marketing.campaign-show', [
            'campaign' => $campaign,
            'recipients' => $this->campaigns->recipients((int) $campaign['id']),
            // For the edit form's audience pickers (same lists the create form uses).
            'groups' => $this->groups->all(),
            'clients' => $this->clients->all(),
            'products' => $this->products->all(),
            // Shown in the preview's header bar so it mirrors the real email.
            'brandName' => trim((string) ($this->settings->get('theme.brand_name', '') ?? '')) ?: 'Email preview',
            'queuedCount' => $request->query('queued') !== null ? max(0, (int) $request->query('queued')) : null,
            // Left out by the Invalid Email Blocker (addresses marked invalid).
            'skippedInvalid' => max(0, (int) $request->query('skipped', 0)),
        ]);
    }

    public function send(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        // Queue only — the cron sends in throttled batches, so this returns
        // immediately regardless of audience size.
        $queued = $this->service->queue((int) $params['id']);
        $skipped = $this->service->skippedInvalid();

        return Response::redirect("/admin/campaigns/{$params['id']}?queued={$queued}" . ($skipped > 0 ? "&skipped={$skipped}" : ''));
    }

    /**
     * Stops a sending campaign so it can be reviewed — the cron's own filter
     * on `status = 'sending'` does the actual work; this just flips the flag.
     */
    public function pause(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $paused = $this->service->pause($id);

        return Response::redirect("/admin/campaigns/{$id}?" . ($paused ? 'paused=1' : 'pause_error=1'));
    }

    /** Puts a paused campaign back in front of the cron, with whatever edits were made while it was stopped. */
    public function resume(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $resumed = $this->service->resume($id);

        return Response::redirect("/admin/campaigns/{$id}?" . ($resumed ? 'resumed=1' : 'resume_error=1'));
    }

    /**
     * Re-sends a SENT campaign: opens a new round for the same content and
     * targeting. `skip_existing` (the checkbox on the campaign page) narrows
     * it to people who have no recipient row yet; without it, everyone the
     * targeting resolves to now gets another copy.
     */
    public function resend(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $queued = $this->service->resend($id, $request->input('skip_existing') !== null);
        $skipped = $this->service->skippedInvalid();

        $suffix = $queued === 0 ? '&resend_none=1' : '';

        return Response::redirect("/admin/campaigns/{$id}?queued={$queued}" . ($skipped > 0 ? "&skipped={$skipped}" : '') . $suffix);
    }

    /** Deletes a draft, paused or sent campaign — blocked while sending (pause first). */
    public function destroy(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $deleted = $this->service->delete((int) $params['id']);

        return Response::redirect('/admin/campaigns' . ($deleted ? '?deleted=1' : '?delete_error=1'));
    }

    /**
     * Edits a draft, paused or sent campaign's subject/body — and, for drafts
     * and sent campaigns, its audience targeting. Sending campaigns must be
     * paused first (the cron must never read half-edited content mid-batch);
     * paused campaigns are content-only because their pending recipients were
     * already resolved.
     */
    public function update(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $subject = trim((string) $request->input('subject', ''));
        $body = trim((string) $request->input('body', ''));

        if ($subject === '' || $body === '') {
            return Response::redirect("/admin/campaigns/{$id}?edit_error=" . urlencode('Subject and body are required.'));
        }

        $audience = null;

        if ($request->input('edit_audience') !== null) {
            $campaign = $this->campaigns->find($id);

            if ($campaign === null || !in_array($campaign['status'], ['draft', 'sent'], true)) {
                return Response::redirect("/admin/campaigns/{$id}?edit_error=" . urlencode('The audience can only be changed on a draft or a sent campaign.'));
            }

            $parsed = $this->parseAudienceInput($request);

            if (is_string($parsed)) {
                return Response::redirect("/admin/campaigns/{$id}?edit_error=" . urlencode($parsed));
            }

            $audience = $parsed;
        }

        $updated = $this->service->update($id, $subject, $body, $audience);

        return Response::redirect("/admin/campaigns/{$id}?" . ($updated ? 'updated=1' : 'edit_error=' . urlencode('This campaign cannot be edited right now — pause it first if it is sending, or it may already have been sent.')));
    }

    /**
     * Reads the campaign targeting fields off a request — the same six-way
     * choice the create form offers, normalised into the repository's column
     * shape. Returns the audience array, or an error message string when the
     * external list has no valid address, or a product-targeted campaign has
     * no product selected.
     *
     * @return array{client_group_id: ?int, client_id: ?int, external_emails: ?string, only_inactive: bool, product_ids: ?string}|string
     */
    private function parseAudienceInput(Request $request): array|string
    {
        $targetType = (string) $request->input('target_type', 'all');

        $groupId = null;
        $clientId = null;
        $externalEmails = null;
        $onlyInactive = false;

        if ($targetType === 'group' && (string) $request->input('client_group_id', '') !== '') {
            $groupId = (int) $request->input('client_group_id');
        } elseif ($targetType === 'individual' && (string) $request->input('client_id', '') !== '') {
            $clientId = (int) $request->input('client_id');
        } elseif ($targetType === 'external') {
            // Normalise exactly as store() does, so the stored list is what
            // will be sent and the edit form round-trips losslessly.
            $parsed = MailCampaignService::parseExternalEmails((string) $request->input('external_emails', ''));

            if ($parsed === []) {
                return 'Enter at least one valid email address for an external campaign.';
            }

            $externalEmails = implode("\n", $parsed);
        } elseif ($targetType === 'inactive') {
            $onlyInactive = true;
        } elseif ($targetType === 'products') {
            // Normalised exactly as store() does; the form must select at
            // least one product (empty must never mean "everyone").
            $productIds = MailCampaignService::parseProductIds($request->input('product_ids'));

            if ($productIds === '') {
                return 'Select at least one product for a product-targeted campaign.';
            }
        }

        return ['client_group_id' => $groupId, 'client_id' => $clientId, 'external_emails' => $externalEmails, 'only_inactive' => $onlyInactive, 'product_ids' => $productIds];
    }

    private function requirePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::SETTINGS_MANAGE)) {
            return Response::html('403 Forbidden — missing settings.manage permission', 403);
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    private function render(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Campaigns',
            'content' => $content,
        ]));
    }
}
