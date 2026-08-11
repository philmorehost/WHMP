<?php

declare(strict_types=1);

namespace CodeVault\Marketing;

use CodeVault\Clients\ClientRepository;
use CodeVault\Mail\EmailContent;
use CodeVault\Mail\EmailDispatcher;
use Throwable;

/**
 * Mass-mail campaigns (blueprint §4.4/§5 marketing automation): sends an
 * admin-composed subject/body to every active client in a group (or every
 * active client, if no group is set), with a per-recipient open-tracking
 * pixel appended to the body and `[Client Name]`/`{{client_name}}` merge
 * tags substituted with the real recipient.
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
        private readonly EmailDispatcher $mail
    ) {
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
                (string) $recipient['open_token']
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

        foreach ($clients as $client) {
            $email = strtolower(trim((string) $client['email']));

            if ($email === '' || isset($seen[$email])) {
                continue;
            }

            $seen[$email] = true;
            $recipients[] = ['client_id' => (int) $client['id'], 'email' => $email];
        }

        foreach ($externalEmails as $email) {
            // A client who also appears in the pasted list gets one email.
            if (isset($seen[$email])) {
                continue;
            }

            $seen[$email] = true;
            $recipients[] = ['client_id' => null, 'email' => $email];
        }

        return $recipients;
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

        foreach ($recipients as $client) {
            $openToken = bin2hex(random_bytes(32));
            $this->campaigns->addRecipient($campaignId, (int) $client['id'], $openToken);

            $message = $this->buildMessage(
                (string) $campaign['subject'],
                (string) $campaign['body'],
                $client['first_name'] ?? null,
                $client['last_name'] ?? null,
                $openToken
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
            $message = $this->buildMessage((string) $campaign['subject'], (string) $campaign['body'], null, null, $openToken);

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
    private function buildMessage(string $subject, string $body, ?string $firstName, ?string $lastName, string $openToken): array
    {
        $name = trim(trim((string) $firstName) . ' ' . trim((string) $lastName));
        $first = trim((string) $firstName);

        // "there" matches the fallback this app already uses elsewhere
        // (ServiceDetailsNotifier) for "no name on file" — never leave the
        // literal merge tag in a delivered email.
        $fullReplacement = $name !== '' ? $name : 'there';
        $firstReplacement = $first !== '' ? $first : 'there';

        $patterns = [
            '/\[\s*client\s*name\s*\]/i' => $fullReplacement,
            '/\{\{\s*client_name\s*\}\}/i' => $fullReplacement,
            '/\[\s*first\s*name\s*\]/i' => $firstReplacement,
            '/\{\{\s*first_name\s*\}\}/i' => $firstReplacement,
        ];

        $subject = preg_replace(array_keys($patterns), array_values($patterns), $subject) ?? $subject;
        $body = preg_replace(array_keys($patterns), array_values($patterns), $body) ?? $body;

        $safeBody = EmailContent::toHtml($body);
        $html = $safeBody . '<img src="/campaigns/track/' . $openToken . '" width="1" height="1" alt="" style="display:none;">';

        return ['subject' => $subject, 'html' => $html];
    }
}
