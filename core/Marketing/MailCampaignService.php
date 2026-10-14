<?php

declare(strict_types=1);

namespace CodeVault\Marketing;

use CodeVault\Clients\ClientRepository;
use CodeVault\Mail\EmailContent;
use CodeVault\Mail\EmailDispatcher;
use CodeVault\Mail\EmailSuppression;
use Throwable;

/**
 * Mass-mail campaigns (blueprint §4.4/§5 marketing automation): sends an
 * admin-composed subject/body to every active client in a group (or every
 * active client, if no group is set), with recipient-specific client, domain,
 * billing and support merge fields substituted at send time and a unique
 * open-tracking pixel appended to each message.
 */
final class MailCampaignService
{
    /**
     * Auto-pauses a sending campaign once this many of its still-pending
     * recipients have a recorded failure. A couple of individually bad
     * addresses is normal and not worth interrupting a campaign over; five
     * failures piling up on one campaign, while the cron keeps retrying them
     * forever with `sent_at` staying NULL, is the signature of something
     * systemic (SMTP host down, bad credentials) — worth stopping so an
     * admin sees it instead of it silently never finishing.
     */
    private const AUTO_PAUSE_AFTER_FAILURES = 5;

    public function __construct(
        private readonly MailCampaignRepository $campaigns,
        private readonly ClientRepository $clients,
        private readonly EmailDispatcher $mail,
        // Invalid Email Blocker: addresses it would skip are left out of the
        // recipient list entirely, so the campaign's counts are honest.
        private readonly ?EmailSuppression $suppression = null
    ) {
    }

    /** Recipients left out of the last queue() call because their address is invalid. */
    private int $skippedInvalid = 0;

    public function skippedInvalid(): int
    {
        return $this->skippedInvalid;
    }

    /**
     * Splits the admin's pasted address list into validated, de-duplicated,
     * lower-cased addresses.
     *
     * Accepts commas, newlines and semicolons because that's what people
     * actually paste out of a spreadsheet or another mail tool. Invalid
     * entries are dropped rather than failing the whole send — one typo in a
     * list of 200 shouldn't stop the other 199 going out.
     *
     * @return array<int, string>
     */
    public static function parseExternalEmails(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $parts = preg_split('/[\s,;]+/', $raw) ?: [];
        $emails = [];

        foreach ($parts as $part) {
            $email = strtolower(trim($part));

            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }

            $emails[$email] = true;
        }

        return array_keys($emails);
    }

    /**
     * Sends up to $limit queued emails, then finalises any campaign that has
     * finished draining.
     *
     * Called from the cron every minute, so a campaign to hundreds of people
     * leaves as a steady trickle instead of one burst that trips the mail
     * host's rate limit.
     *
     * @return int emails dispatched this run
     */
    public function dispatchQueued(int $limit): int
    {
        $sent = 0;

        foreach ($this->campaigns->pendingRecipients($limit) as $recipient) {
            $email = trim((string) ($recipient['email'] ?? ''));

            // No address (client deleted, or a blank row): stamp it so the
            // queue drains instead of retrying this row forever.
            if ($email === '') {
                $this->campaigns->markRecipientSent((int) $recipient['id']);
                continue;
            }

            $message = $this->buildMessage(
                (string) $recipient['subject'],
                (string) $recipient['body'],
                $recipient['first_name'] ?? null,
                $recipient['last_name'] ?? null,
                (string) $recipient['open_token'],
                $recipient
            );

            try {
                $logId = $this->mail->sendRaw(
                    $message['subject'],
                    $message['html'],
                    $email,
                    $recipient['client_id'] !== null ? (int) $recipient['client_id'] : null
                );
            } catch (Throwable $e) {
                // sendRaw() only throws if we couldn't even hand the email to
                // the queue (DB write or Redis push failing) — unlike an
                // actual delivery failure, which SendEmailJob always catches
                // internally and records in email_log instead of letting it
                // propagate here. Left pending (sent_at stays NULL) so the
                // cron retries it once whatever is down comes back.
                $this->campaigns->markRecipientFailed((int) $recipient['id'], $e->getMessage());
                continue;
            }

            $this->campaigns->markRecipientSent((int) $recipient['id'], $logId);
            $sent++;
        }

        $this->autoPauseFailingCampaigns();
        $this->campaigns->finaliseDrainedCampaigns();

        return $sent;
    }

    /**
     * A delivery failure only becomes knowable once SendEmailJob actually
     * runs and writes the outcome to email_log — synchronously under the
     * sync queue, but possibly much later, in a different process, under
     * Redis. So it can't be caught around sendRaw() above; instead this
     * reconciles email_log against every campaign still sending, and pauses
     * any whose confirmed-failure count has crossed the threshold, however
     * many cron ticks it took for those failures to become visible.
     */
    private function autoPauseFailingCampaigns(): void
    {
        foreach ($this->campaigns->sendingCampaignIds() as $campaignId) {
            if ($this->campaigns->syncConfirmedFailures($campaignId) >= self::AUTO_PAUSE_AFTER_FAILURES) {
                $this->campaigns->pauseCampaign($campaignId);
            }
        }
    }

    /**
     * Builds the recipient list and hands the campaign to the cron.
     *
     * Returns immediately after writing the queue rows, so "Send Now" responds
     * at once no matter how large the audience.
     *
     * @return int recipients queued
     */
    public function queue(int $campaignId): int
    {
        $campaign = $this->campaigns->find($campaignId);

        if ($campaign === null || $campaign['status'] !== 'draft') {
            return 0;
        }

        $this->campaigns->markSending($campaignId);

        $queued = 0;

        foreach ($this->resolveRecipients($campaign) as $recipient) {
            $this->campaigns->addRecipient(
                $campaignId,
                $recipient['client_id'],
                bin2hex(random_bytes(32)),
                $recipient['email'],
                true
            );
            $queued++;
        }

        // An empty audience would otherwise leave the campaign stuck in
        // "sending"; the cron's finalise step closes it on the next run.
        return $queued;
    }

    /** Pauses a sending campaign — see MailCampaignRepository::pauseCampaign(). */
    public function pause(int $campaignId): bool
    {
        return $this->campaigns->pauseCampaign($campaignId);
    }

    /** Resumes a paused campaign — see MailCampaignRepository::resumeCampaign(). */
    public function resume(int $campaignId): bool
    {
        return $this->campaigns->resumeCampaign($campaignId);
    }

    /**
     * Edits a draft or paused campaign's subject/body — the "review, edit"
     * step between pausing a campaign and resuming it. Recipients already
     * sent keep whatever they were sent; anyone still pending gets the
     * edited version once the campaign resumes.
     */
    public function update(int $campaignId, string $subject, string $body): bool
    {
        return $this->campaigns->update($campaignId, $subject, $body);
    }

    /**
     * The full audience for a campaign, de-duplicated by address.
     *
     * @param array<string, mixed> $campaign
     * @return array<int, array{client_id: ?int, email: string}>
     */
    private function resolveRecipients(array $campaign): array
    {
        $externalEmails = self::parseExternalEmails((string) ($campaign['external_emails'] ?? ''));

        if (!empty($campaign['client_id'])) {
            $singleClient = $this->clients->find((int) $campaign['client_id']);
            $clients = $singleClient !== null ? [$singleClient] : [];
        } elseif (!empty($campaign['only_inactive'])) {
            // Accounts with no active service or domain — the re-engagement
            // audience (see ClientRepository::activeWithoutProductsOrDomains).
            $clients = $this->clients->activeWithoutProductsOrDomains();
        } elseif ($externalEmails !== [] && $campaign['client_group_id'] === null) {
            // External-only: must not fan out to the whole client base.
            $clients = [];
        } else {
            $clientGroupId = $campaign['client_group_id'] !== null ? (int) $campaign['client_group_id'] : null;
            $clients = $this->clients->activeForGroup($clientGroupId);
        }

        $recipients = [];
        $seen = [];
        $this->skippedInvalid = 0;

        foreach ($clients as $client) {
            $email = strtolower(trim((string) $client['email']));

            if ($email === '' || isset($seen[$email])) {
                continue;
            }

            $seen[$email] = true;

            if ($this->isBlocked($email)) {
                continue;
            }

            $recipients[] = ['client_id' => (int) $client['id'], 'email' => $email];
        }

        foreach ($externalEmails as $email) {
            // A client who also appears in the pasted list gets one email.
            if (isset($seen[$email])) {
                continue;
            }

            $seen[$email] = true;

            if ($this->isBlocked($email)) {
                continue;
            }

            $recipients[] = ['client_id' => null, 'email' => $email];
        }

        return $recipients;
    }

    private function isBlocked(string $email): bool
    {
        if ($this->suppression?->reasonFor($email) === null) {
            return false;
        }

        $this->skippedInvalid++;

        return true;
    }

    public function send(int $campaignId): int
    {
        $campaign = $this->campaigns->find($campaignId);

        if ($campaign === null || $campaign['status'] !== 'draft') {
            return 0;
        }

        $this->campaigns->markSending($campaignId);

        $externalEmails = self::parseExternalEmails((string) ($campaign['external_emails'] ?? ''));

        if (!empty($campaign['client_id'])) {
            $singleClient = $this->clients->find((int) $campaign['client_id']);
            $recipients = $singleClient !== null ? [$singleClient] : [];
        } elseif (!empty($campaign['only_inactive'])) {
            // Accounts with no active service or domain — see resolveRecipients().
            $recipients = $this->clients->activeWithoutProductsOrDomains();
        } elseif ($externalEmails !== [] && $campaign['client_group_id'] === null) {
            // An external-only campaign must not silently fan out to every
            // active client — that's the difference between mailing 20
            // prospects and mailing the whole customer base by accident.
            $recipients = [];
        } else {
            $clientGroupId = $campaign['client_group_id'] !== null ? (int) $campaign['client_group_id'] : null;
            $recipients = $this->clients->activeForGroup($clientGroupId);
        }

        $sent = 0;
        $alreadySent = [];
        $primaryDomains = $this->campaigns->primaryDomainsForClients(array_map(
            static fn (array $client): int => (int) ($client['id'] ?? 0),
            $recipients
        ));

        foreach ($recipients as $client) {
            $openToken = bin2hex(random_bytes(32));
            $this->campaigns->addRecipient($campaignId, (int) $client['id'], $openToken);

            $mergeRecipient = $client;
            $mergeRecipient['domain'] = $primaryDomains[(int) $client['id']] ?? '';
            $message = $this->buildMessage(
                (string) $campaign['subject'],
                (string) $campaign['body'],
                $client['first_name'] ?? null,
                $client['last_name'] ?? null,
                $openToken,
                $mergeRecipient
            );

            $this->mail->sendRaw($message['subject'], $message['html'], $client['email'], (int) $client['id']);
            $alreadySent[strtolower(trim((string) $client['email']))] = true;
            $sent++;
        }

        foreach ($externalEmails as $email) {
            // A client who also appears in the pasted list gets one email, not
            // two — the campaign is the unit, not the source of the address.
            if (isset($alreadySent[$email])) {
                continue;
            }

            $openToken = bin2hex(random_bytes(32));
            $this->campaigns->addRecipient($campaignId, null, $openToken, $email);

            // No name on file for an external address — buildMessage() falls
            // back to a neutral greeting rather than leaving the merge tag
            // literally visible in the delivered email.
            $message = $this->buildMessage(
                (string) $campaign['subject'],
                (string) $campaign['body'],
                null,
                null,
                $openToken,
                ['email' => $email]
            );

            // No client id: these addresses have no account, so the email log
            // records them against no client rather than a wrong one.
            $this->mail->sendRaw($message['subject'], $message['html'], $email, null);
            $alreadySent[$email] = true;
            $sent++;
        }

        $this->campaigns->markSent($campaignId);

        return $sent;
    }

    /**
     * Personalises subject/body, converts the body to safe HTML, and appends
     * the open-tracking pixel — in that order, deliberately.
     *
     * Two bugs lived in the naive version of this (raw body . pixel, straight
     * into sendRaw()):
     *
     *  1. No substitution ran at all. The AI copilot's system prompt tells it
     *     to write "[Client Name]" as a greeting placeholder (see
     *     CampaignCopilotController), and nothing downstream ever replaced
     *     it — every campaign literally said "Hi [Client Name]," to every
     *     recipient, in both the AI-drafted and manually-typed case, because
     *     sendRaw() is "already-composed content, sent as-is" by design and
     *     performs no substitution of any kind.
     *
     *  2. For a PLAIN-TEXT body — what an admin gets by just typing prose
     *     into the compose textarea, which is what its own placeholder text
     *     invites ("What should this campaign say?") — appending the pixel
     *     to the raw body BEFORE EmailContent::toHtml() ran meant the pixel
     *     got caught by toHtml()'s escaping (isHtml() only recognises block
     *     tags like <p>/<div>, not <img>). The tracking pixel was delivered
     *     as literal, inert text — "&lt;img src=...&gt;" — never a real
     *     image tag, so opens could never be recorded for that email no
     *     matter what the recipient's mail client did. Confirmed empirically:
     *     an HTML-authored body (every existing test uses one) passed the
     *     pixel through untouched; a plain-text body destroyed it every time.
     *
     * Converting to HTML first (idempotent — a body that is already HTML,
     * like the AI copilot's, passes through toHtml() unchanged) and only then
     * appending the pixel means the pixel is never subject to escaping,
     * regardless of how the admin composed the body.
     *
     * @return array{subject: string, html: string}
     */
    private function buildMessage(
        string $subject,
        string $body,
        ?string $firstName,
        ?string $lastName,
        string $openToken,
        array $recipient = []
    ): array {
        $recipient['first_name'] = $firstName ?? ($recipient['first_name'] ?? null);
        $recipient['last_name'] = $lastName ?? ($recipient['last_name'] ?? null);
        $mergeFields = $this->mergeFields($recipient);

        $subject = $this->substituteMergeFields($subject, $mergeFields);

        // Plain-text bodies are escaped by EmailContent::toHtml() below. For
        // authored HTML, escape merge values now so client-controlled values
        // remain text instead of becoming markup or breaking an attribute.
        $bodyIsHtml = EmailContent::isHtml($body);
        $body = $this->substituteMergeFields($body, $mergeFields, $bodyIsHtml);

        $safeBody = EmailContent::toHtml($body);
        $html = $safeBody . '<img src="/campaigns/track/' . $openToken . '" width="1" height="1" alt="" style="display:none;">';

        return ['subject' => $subject, 'html' => $html];
    }

    /** @param array<string, mixed> $recipient @return array<string, string> */
    private function mergeFields(array $recipient): array
    {
        $first = trim((string) ($recipient['first_name'] ?? ''));
        $last = trim((string) ($recipient['last_name'] ?? ''));
        $fullName = trim($first . ' ' . $last);
        $email = trim((string) ($recipient['email'] ?? ''));
        $clientId = $recipient['client_id'] ?? $recipient['id'] ?? null;
        $clientId = $clientId !== null && (int) $clientId > 0 ? (string) (int) $clientId : '';
        $clientCompany = trim((string) ($recipient['client_company'] ?? $recipient['company_name'] ?? ''));
        $domain = trim((string) ($recipient['domain'] ?? ''));
        $websiteUrl = $this->billingWebsiteUrl();
        $billingUrl = $websiteUrl . '/client/invoices';
        $clientAreaUrl = $websiteUrl . '/client/dashboard';
        $supportUrl = $websiteUrl . '/client/tickets';
        $companyName = function_exists('brand_name') ? trim((string) \brand_name()) : '';

        if ($companyName === '') {
            $companyName = 'WHMP';
        }

        $emailDomain = '';
        $atPosition = strrpos($email, '@');

        if ($atPosition !== false) {
            $emailDomain = substr($email, $atPosition + 1);
        }

        $phone = trim((string) ($recipient['phone'] ?? ''));
        $city = trim((string) ($recipient['city'] ?? ''));
        $state = trim((string) ($recipient['state'] ?? ''));
        $postcode = trim((string) ($recipient['postcode'] ?? ''));
        $country = trim((string) ($recipient['country'] ?? ''));

        // Common aliases are included as real values so both bracketed labels
        // and {{snake_case}} tags can be normalised by substituteMergeFields().
        return [
            'client_name' => $fullName !== '' ? $fullName : 'there',
            'first_name' => $first !== '' ? $first : 'there',
            'last_name' => $last,
            'client_email' => $email,
            'email' => $email,
            'email_address' => $email,
            'email_domain' => $emailDomain,
            'client_domain' => $domain,
            'domain' => $domain,
            'domain_name' => $domain,
            'client_id' => $clientId,
            'account_id' => $clientId,
            'company_name' => $companyName,
            'billing_company_name' => $companyName,
            'client_company' => $clientCompany,
            'client_company_name' => $clientCompany,
            'phone' => $phone,
            'client_phone' => $phone,
            'city' => $city,
            'client_city' => $city,
            'state' => $state,
            'region' => $state,
            'postcode' => $postcode,
            'postal_code' => $postcode,
            'zip' => $postcode,
            'country' => $country,
            'client_country' => $country,
            'billing_website' => $websiteUrl,
            'billing_website_url' => $websiteUrl,
            'website_url' => $websiteUrl,
            'site_url' => $websiteUrl,
            'app_url' => $websiteUrl,
            'billing_url' => $billingUrl,
            'billing_portal_url' => $billingUrl,
            'invoice_url' => $billingUrl,
            'client_area_url' => $clientAreaUrl,
            'client_portal_url' => $clientAreaUrl,
            'support_url' => $supportUrl,
            'tickets_url' => $supportUrl,
        ];
    }

    private function billingWebsiteUrl(): string
    {
        // APP_URL is the canonical public base URL used by billing links.
        // Kernel/Config loads it from .env into the process environment at boot.
        $configuredUrl = $_ENV['APP_URL'] ?? getenv('APP_URL');
        $url = trim((string) ($configuredUrl ?: 'http://localhost'));

        return rtrim($url !== '' ? $url : 'http://localhost', '/');
    }

    /** @param array<string, string> $fields */
    private function substituteMergeFields(string $content, array $fields, bool $escapeHtml = false): string
    {
        $rendered = preg_replace_callback(
            '/\\{\\{\\s*([a-z][a-z0-9 _-]*)\\s*\\}\\}|\\[\\s*([a-z][a-z0-9 _-]*)\\s*\\]/i',
            static function (array $matches) use ($fields, $escapeHtml): string {
                $rawToken = (string) (($matches[1] ?? '') !== '' ? $matches[1] : ($matches[2] ?? ''));
                $token = strtolower(trim($rawToken));
                $key = (string) preg_replace('/[\\s-]+/', '_', $token);

                if (!array_key_exists($key, $fields)) {
                    return $matches[0];
                }

                $value = $fields[$key];

                return $escapeHtml
                    ? htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    : $value;
            },
            $content
        );

        return $rendered ?? $content;
    }
}
