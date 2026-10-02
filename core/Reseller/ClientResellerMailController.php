<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Session\SessionManager;
use CodeVault\Settings\SettingsRepository;
use CodeVault\View;

/**
 * The store's own support address: create it, choose it, and find out whether it works.
 *
 * WHY THIS IS A PAGE AND NOT A FIELD ON THE STORE FORM
 *
 * Because the honest answer to "is my address going to work?" is a DNS lookup, and that
 * belongs somewhere it can be explained. A text field that accepts `support@shop.example`
 * and says nothing when the domain has not authorised our server is how a reseller ends up
 * with support replies in their customers' spam folders, believing they are fine.
 *
 * A NEW CONTROLLER RATHER THAN METHODS ON ClientResellerController, which has thirteen
 * constructor dependencies and is built by hand in two test files — a fourteenth would
 * silently rebind every argument after it in both. Same reasoning as the ticket desk.
 *
 * CREATING THE MAILBOX DOES NOT BY ITSELF CHANGE THE SENDER, AND THAT IS DELIBERATE
 *
 * A store with no address set is ALREADY white-labelled: its name goes on the message and
 * the platform's aligned address carries it. Switching to an address whose domain does not
 * authorise us therefore makes deliverability WORSE, not better — from authenticated mail
 * signed with the store's name, to unauthenticated mail that gets filtered. So the mailbox
 * is created, the address is only adopted automatically when the check says aligned, and
 * otherwise the page explains what to add and lets the reseller take it on knowingly.
 *
 * NO STORE ID ANYWHERE. The store comes from the session guard, as everywhere else in the
 * reseller area.
 */
final class ClientResellerMailController
{
    /** The address is shown once, so it travels through a flash rather than a query string. */
    private const FLASH_CREATED = 'reseller_mailbox_created';

    public function __construct(
        private readonly ClientAuthGuard $guard,
        private readonly View $view,
        private readonly SessionManager $session,
        private readonly ResellerStoreRepository $stores,
        private readonly ResellerMailboxProvisioner $mailboxes,
        private readonly SettingsRepository $settings,
        // INJECTED rather than built here on purpose. A checker that builds its own DNS
        // lookup cannot be faked, so every test of this page would reach the network — and
        // a test that depends on real DNS is one that fails when a resolver is slow.
        private readonly MailDomainAlignment $alignment
    ) {
    }

    public function index(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $store = $this->stores->forClient((int) $client['id']);

        if ($store === null) {
            return Response::redirect('/client/reseller');
        }

        return $this->page('reseller.client-mail', [
            'store' => $store,
            'address' => ResellerMailIdentity::address($store),
            'domain' => ResellerStoreLocator::normaliseHost((string) ($store['custom_domain'] ?? '')),
            // Whether a mailbox could be created at all, and why not — the reason is the
            // useful part, so the button is not simply missing when it cannot work.
            'mailboxReady' => $this->mailboxReadiness($store),
            'warning' => ResellerMailIdentity::warning($store),
            'remediation' => ResellerMailIdentity::remediation($store),
            'aligned' => ResellerMailIdentity::isAligned($store),
            'checkedAt' => trim((string) ($store['support_email_checked_at'] ?? '')),
            'created' => $this->session->pullFlash(self::FLASH_CREATED, null),
            'notice' => $this->session->pullFlash('reseller_notice'),
            'error' => $this->session->pullFlash('reseller_error'),
            'platformSender' => $this->platformSender(),
        ]);
    }

    /** Choose the address customers are written to from. */
    public function save(Request $request): Response
    {
        $store = $this->storeOrRedirect();

        if ($store instanceof Response) {
            return $store;
        }

        $address = trim((string) $request->input('support_email', ''));

        if ($address === '') {
            $this->session->flash('reseller_error', 'Enter the address you want to send from, or clear it to use ours.');

            return Response::redirect('/client/reseller/mail');
        }

        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            // Refused here rather than stored and complained about later: a malformed sender
            // makes SmtpMailer throw, so the customer would receive NOTHING.
            $this->session->flash('reseller_error', 'That is not a valid email address.');

            return Response::redirect('/client/reseller/mail');
        }

        $this->stores->setSupportEmail((int) $store['id'], $address);
        $this->checkAlignment((int) $store['id'], $address);

        $this->session->flash('reseller_notice', 'Support address saved. Check the result below.');

        return Response::redirect('/client/reseller/mail');
    }

    /** Go back to the platform's address, still with the store's name on the message. */
    public function clear(Request $request): Response
    {
        $store = $this->storeOrRedirect();

        if ($store instanceof Response) {
            return $store;
        }

        $this->stores->setSupportEmail((int) $store['id'], null);

        $this->session->flash(
            'reseller_notice',
            'Cleared. Your customers are still written to as your store — our address carries the message and your name is on it.'
        );

        return Response::redirect('/client/reseller/mail');
    }

    /** Create the mailbox on the store's own domain, on the hosting panel. */
    public function create(Request $request): Response
    {
        $store = $this->storeOrRedirect();

        if ($store instanceof Response) {
            return $store;
        }

        $result = $this->mailboxes->provision($store);

        if (!$result['ok']) {
            $this->session->flash('reseller_error', $result['message']);

            return Response::redirect('/client/reseller/mail');
        }

        $address = (string) $result['address'];

        // Measure FIRST, then adopt, then RECORD — in that order, and the order is the
        // subtle part. setSupportEmail() clears the stored result by design, because an
        // address nobody has checked must not inherit a previous domain's pass. So
        // recording the check before adopting would have our own adoption wipe the very
        // result we just measured, and the page would report "not checked" for an address
        // it had just confirmed.
        $check = $this->alignment->check($address);
        $status = (string) $check['status'];
        $adopted = false;

        // Adopted only when the domain actually authorises us — see the class docblock.
        if ($status === MailDomainAlignment::ALIGNED) {
            $this->stores->setSupportEmail((int) $store['id'], $address);
            $adopted = true;
        }

        $this->stores->recordMailCheck((int) $store['id'], $status, $check['detail']);

        $this->session->flash(self::FLASH_CREATED, [
            'address' => $address,
            'password' => $result['password'],
            'adopted' => $adopted,
            'status' => $status,
            'message' => $result['message'],
        ]);

        return Response::redirect('/client/reseller/mail');
    }

    /** Re-run the DNS check on the address in use. */
    public function check(Request $request): Response
    {
        $store = $this->storeOrRedirect();

        if ($store instanceof Response) {
            return $store;
        }

        $address = ResellerMailIdentity::address($store);

        if ($address === null) {
            $this->session->flash('reseller_error', 'Set an address first — there is nothing to check yet.');

            return Response::redirect('/client/reseller/mail');
        }

        $status = $this->checkAlignment((int) $store['id'], $address);

        $this->session->flash(
            $status === MailDomainAlignment::ALIGNED ? 'reseller_notice' : 'reseller_error',
            $status === MailDomainAlignment::ALIGNED
                ? 'Your domain authorises us to send as ' . $address . '.'
                : 'Not authorised yet — see what to add below.'
        );

        return Response::redirect('/client/reseller/mail');
    }

    // ------------------------------------------------------------- internals ---

    /**
     * The store, or a redirect when there is not one.
     *
     * @return array<string, mixed>|Response
     */
    private function storeOrRedirect(): array|Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $store = $this->stores->forClient((int) $client['id']);

        return $store ?? Response::redirect('/client/reseller');
    }

    /**
     * Run the check and store the outcome. Returns the status.
     *
     * 'unavailable' is recorded as itself rather than as a failure, so the page can say
     * "not confirmed yet" instead of sending somebody to edit DNS that may be correct.
     */
    private function checkAlignment(int $storeId, string $address): string
    {
        $result = $this->alignment->check($address);
        $this->stores->recordMailCheck($storeId, (string) $result['status'], $result['detail']);

        return (string) $result['status'];
    }

    /** The address our own mail goes out as — what the store's mail falls back to. */
    private function platformSender(): string
    {
        return trim((string) $this->settings->get('smtp.from_email', ''));
    }

    /**
     * Can a mailbox be created for this store, and if not, why not?
     *
     * Reported rather than used to hide the button: "add your domain on the Domains page
     * first" is actionable, whereas a missing button is not.
     *
     * @param array<string, mixed> $store
     * @return array{ok: bool, message: string}
     */
    private function mailboxReadiness(array $store): array
    {
        $domain = ResellerStoreLocator::normaliseHost((string) ($store['custom_domain'] ?? ''));

        if ($domain === '') {
            return ['ok' => false, 'message' => 'Claim a domain for your store first — a mailbox has to live on one.'];
        }

        $provisioned = ResellerStoreLocator::normaliseHost((string) ($store['domain_provisioned_host'] ?? ''));

        if ($provisioned === '' || $provisioned !== $domain) {
            return [
                'ok' => false,
                'message' => $domain . ' is not on the hosting panel yet. Once it is, we can create '
                    . 'support@' . $domain . ' for you.',
            ];
        }

        return ['ok' => true, 'message' => 'We can create support@' . $domain . ' on the hosting panel.'];
    }

    /** @param array<string, mixed> $data */
    private function page(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.client', [
            'title' => 'Store support address',
            'content' => $content,
        ]));
    }
}
