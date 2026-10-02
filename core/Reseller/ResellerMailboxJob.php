<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Cron\CronJob;

/**
 * Gives every store that is live on the hosting panel a mailbox on its own domain.
 *
 * THE AUTOMATION THE RESELLER ASKED FOR: the store exists, the domain is verified and on the
 * panel, and a support address appears without anybody filing a ticket about it. Nobody
 * enjoys asking for an email account, and the alternative — an admin making mailboxes by
 * hand for every reseller — does not scale past about three.
 *
 * IT ADOPTS THE ADDRESS ONLY WHEN THE DOMAIN AUTHORISES US, WHICH IS THE WHOLE POINT
 *
 * A store with no address set is ALREADY white-labelled: its name is on the message and the
 * platform's authenticated address carries it. So switching a store to
 * support@their-domain before that domain authorises our server would not be an upgrade —
 * it would replace authenticated mail with mail that gets filtered, and neither the reseller
 * nor anybody else would be told. The mailbox is created either way, because the reseller
 * needs to be able to READ replies sent to it; the sender changes only once the DNS says it
 * is safe, and the portal shows them exactly what to add in the meantime.
 *
 * WHY IT IS A SWEEP RATHER THAN PART OF DomainVerificationJob
 *
 * Because verification is not the only way a store becomes eligible. A domain can be
 * approved before provisioning is switched on, an admin can add the domain by hand, and a
 * panel call can fail and need retrying. A job that asks "who is eligible?" every day
 * handles all of those in one definition of eligible, and idempotently — the repository's
 * mailbox_host record is what stops it working the same store twice, so it is safe to run
 * after an outage, on a new install, and on an install that has been running for years.
 */
final class ResellerMailboxJob implements CronJob
{
    public function __construct(
        private readonly ResellerStoreRepository $stores,
        private readonly ResellerMailboxProvisioner $mailboxes,
        private readonly MailDomainAlignment $alignment,
        private readonly ActivityLogger $activity
    ) {
    }

    public function name(): string
    {
        return 'reseller-mailbox-provisioning';
    }

    /** Daily, like domain verification: this is not a time-sensitive operation. */
    public function frequencyMinutes(): int
    {
        return 1440;
    }

    public function handle(): void
    {
        foreach ($this->stores->mailboxCandidates() as $store) {
            $storeId = (int) $store['id'];
            $domain = (string) ($store['custom_domain'] ?? '');

            $result = $this->mailboxes->provision($store);

            if (!$result['ok']) {
                // OFF is not a failure. The switch means "this application does not touch
                // the hosting panel", and the admin's instructions for that install already
                // say a mailbox is theirs to create — so recording an error on every store
                // would fill the portal with a problem nobody has.
                if (($result['skipped'] ?? false) === true) {
                    continue;
                }

                $this->stores->recordMailboxError($storeId, (string) $result['message']);

                continue;
            }

            $address = (string) $result['address'];

            // Recorded before the check, and the check is recorded LAST — the same ordering
            // the portal uses, and for the same reason: setSupportEmail() clears the stored
            // result by design, so recording before adopting would wipe the answer.
            $this->stores->markMailboxProvisioned($storeId, $domain);

            $check = $this->alignment->check($address);
            $adopted = (string) $check['status'] === MailDomainAlignment::ALIGNED;

            if ($adopted) {
                $this->stores->setSupportEmail($storeId, $address);
            }

            $this->stores->recordMailCheck($storeId, (string) $check['status'], $check['detail']);

            // Built from a variable rather than inline: `'a' . $x === 'b' ? ... : ...`
            // groups as `('a' . $x) === 'b'`, because concatenation binds tighter than
            // comparison — so the message would take the "not adopted" branch every time
            // and quietly describe the opposite of what happened.
            $outcome = $adopted
                ? ' and set it as the store\'s sending address'
                : ' (not adopted as the sending address — the domain does not authorise this server yet)';

            $this->activity->log(
                'system',
                null,
                'reseller.store.mailbox_created',
                'reseller',
                $storeId,
                'Created ' . $address . ' on the hosting panel for ' . $domain . $outcome,
                null
            );
        }
    }
}
