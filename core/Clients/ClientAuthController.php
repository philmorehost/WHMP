<?php

declare(strict_types=1);

namespace CodeVault\Clients;

use CodeVault\Affiliates\AffiliateService;
use CodeVault\Config;
use CodeVault\Mail\EmailDispatcher;
use CodeVault\Modules\SecurityQuestionModuleService;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Security\PasswordResetToken;
use CodeVault\Security\PasswordResetTokenRepository;
use CodeVault\Security\RecoveryCodes;
use CodeVault\Security\Totp;
use CodeVault\Session\SessionManager;
use CodeVault\View;
use Throwable;

final class ClientAuthController
{
    private const PENDING_2FA_SESSION_KEY = 'pending_2fa_client_id';
    private const PENDING_REGISTRATION_SESSION_KEY = 'pending_client_registration';
    private const RESET_ACCOUNT_TYPE = 'client';

    public function __construct(
        private readonly ClientAuthManager $auth,
        private readonly ClientAuthGuard $guard,
        private readonly View $view,
        private readonly AffiliateService $affiliateService,
        private readonly SessionManager $session,
        private readonly ClientRepository $clients,
        private readonly Totp $totp,
        private readonly RecoveryCodes $recoveryCodes,
        private readonly PasswordResetTokenRepository $resetTokens,
        private readonly PasswordResetToken $resetToken,
        private readonly EmailDispatcher $mail,
        private readonly Config $config,
        private readonly SecurityQuestionModuleService $securityQuestions,
        private readonly \CodeVault\Settings\SettingsRepository $settings,
        private readonly ClientRegistrationOtpRepository $registrationOtps,
        // Trailing and optional so the tests that build this controller by hand keep
        // working; the container always supplies both. Without them the controller
        // behaves exactly as it did before stores were isolated.
        private readonly ?\CodeVault\Reseller\ClientSiteAccess $siteAccess = null,
        private readonly ?\CodeVault\Reseller\CurrentReseller $currentStore = null,
        // Google sign-in for whichever site is being served. Optional for the same
        // reason; built from settings + the current store when absent.
        private readonly ?GoogleSignIn $google = null
    ) {
    }

    /**
     * True when this account may NOT be used on the site being served — it belongs to
     * another provider on the platform (ClientSiteAccess has the rule and its two
     * exceptions). Checked only AFTER the password is proven, so it cannot be used to
     * discover which addresses are registered where.
     *
     * @param array<string, mixed> $client
     */
    private function belongsElsewhere(array $client): bool
    {
        if ($this->siteAccess === null) {
            return false;
        }

        try {
            return !$this->siteAccess->canSignInHere($client);
        } catch (Throwable) {
            // A lookup failure must not lock everyone out; the owner check is the
            // common case and is answered without a query.
            return false;
        }
    }

    /**
     * Whether an existing account is "yours, here" or "another provider's".
     *
     * @param array<string, mixed> $client
     */
    private function existingAccountKind(array $client): string
    {
        return $this->belongsElsewhere($client) ? 'other_provider' : 'same_site';
    }

    /** @param array<string, mixed> $extra */
    private function registerPage(array $extra, ?array $googleUser, string $refCode): Response
    {
        return $this->page('client-auth.register', $extra + [
            'error' => null,
            'refCode' => $refCode,
            'googleUser' => $googleUser,
            'googleClientId' => $this->googleClientId(),
        ]);
    }

    public function loginForm(Request $request): Response
    {
        if ($this->guard->check()) {
            return Response::redirect('/client/dashboard');
        }

        return $this->page('client-auth.login', $this->loginData([
            'error' => self::googleErrorMessage((string) $request->query('error', '')),
            'resetSuccess' => $request->query('reset') === 'success',
        ]));
    }

    public function setPinForm(Request $request): Response
    {
        $client = $this->guard->currentClient();
        if ($client === null) {
            return Response::redirect('/client/login');
        }

        if (!empty($client['security_pin_hash'])) {
            return Response::redirect('/client/dashboard');
        }

        return $this->page('client-auth.set-pin', ['error' => null]);
    }

    public function setPin(Request $request): Response
    {
        $client = $this->guard->currentClient();
        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $pin = trim((string) $request->input('security_pin', ''));
        $confirmPin = trim((string) $request->input('confirm_security_pin', ''));

        if (strlen($pin) < 4) {
            return $this->page('client-auth.set-pin', ['error' => 'Security PIN must be at least 4 characters long.']);
        }

        if ($pin !== $confirmPin) {
            return $this->page('client-auth.set-pin', ['error' => 'Security PINs do not match. Please try again.']);
        }

        $this->clients->updateSecurityPin((int) $client['id'], $pin);

        return Response::redirect('/client/dashboard');
    }

    public function login(Request $request): Response
    {
        $email = trim((string) $request->input('email', ''));
        $password = (string) $request->input('password', '');

        $result = $this->auth->attempt($email, $password, $request->ip());

        // The right password on the WRONG SITE: an account of another provider on this
        // platform. Refused before any session state is written (including the pending
        // 2FA marker), with the same wording whichever provider it belongs to.
        if (($result->isSuccess() || $result->requiresTwoFactor()) && $result->client !== null && $this->belongsElsewhere($result->client)) {
            return $this->page('client-auth.login', $this->loginData(['error' => \CodeVault\Reseller\ClientSiteAccess::otherProviderMessage()]));
        }

        if ($result->requiresTwoFactor()) {
            $this->session->set(self::PENDING_2FA_SESSION_KEY, $result->client['id']);

            return Response::redirect('/client/login/2fa');
        }

        if ($result->isSuccess()) {
            $this->guard->login($result->client);

            // Resume what they were doing: a Free Reseller application, an order
            // (the cart), or simply the dashboard.
            return $this->afterSignIn();
        }

        $message = $result->status === 'blocked' ? 'Access denied. <a href="/client/recover-pin" style="color:var(--cv-color-brand-500);text-decoration:underline;">Recover with Security PIN</a>' : 'Invalid email or password.';
        $status = $result->status === 'blocked' ? 403 : 200;

        return $this->page('client-auth.login', $this->loginData(['error' => $message]), $status);
    }

    public function twoFactorForm(Request $request): Response
    {
        if ($this->pendingClient() === null) {
            return Response::redirect('/client/login');
        }

        return $this->page('client-auth.two-factor', ['error' => null]);
    }

    public function verifyTwoFactor(Request $request): Response
    {
        $client = $this->pendingClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $code = trim((string) $request->input('code', ''));

        if ($this->totp->verify((string) $client['two_factor_secret'], $code)) {
            return $this->completeTwoFactorLogin($client);
        }

        $remainingCodes = $this->recoveryCodes->verifyAndConsume($code, $client['two_factor_recovery_codes']);

        if ($remainingCodes !== null) {
            $this->clients->updateRecoveryCodes((int) $client['id'], $remainingCodes);

            return $this->completeTwoFactorLogin($client);
        }

        return $this->page('client-auth.two-factor', ['error' => 'Invalid code. Check your authenticator app or use a recovery code.']);
    }

    /** @param array<string, mixed> $client */
    private function completeTwoFactorLogin(array $client): Response
    {
        $this->session->remove(self::PENDING_2FA_SESSION_KEY);
        $this->guard->login($client);

        return $this->afterSignIn();
    }

    /** @return array<string, mixed>|null */
    private function pendingClient(): ?array
    {
        $id = $this->session->get(self::PENDING_2FA_SESSION_KEY);

        return $id === null ? null : $this->clients->find((int) $id);
    }

    public function registerForm(Request $request): Response
    {
        if ($this->guard->check()) {
            return Response::redirect('/client/dashboard');
        }

        $googleUser = $this->session->get('google_user');

        return $this->page('client-auth.register', [
            'error' => null,
            'refCode' => (string) $request->query('ref', ''),
            'googleClientId' => $this->googleClientId(),
            'googleUser' => $googleUser,
        ]);
    }

    public function register(Request $request): Response
    {
        $googleUser = $this->session->get('google_user');

        $email = trim((string) $request->input('email', ''));
        if ($googleUser && !empty($googleUser['email'])) {
            $email = $googleUser['email'];
        }

        $password = (string) $request->input('password', '');
        if ($googleUser && $password === '') {
            $password = bin2hex(random_bytes(16));
        }

        $firstName = trim((string) $request->input('first_name', ''));
        $lastName = trim((string) $request->input('last_name', ''));
        $refCode = trim((string) $request->input('ref', ''));
        $country = strtoupper(trim((string) $request->input('country', '')));
        $vatNumber = trim((string) $request->input('vat_number', ''));
        $phone = trim((string) $request->input('phone', ''));
        $address1 = trim((string) $request->input('address1', ''));
        $city = trim((string) $request->input('city', ''));
        $postcode = trim((string) $request->input('postcode', ''));

        $securityPin = trim((string) $request->input('security_pin', ''));

        if ($email === '' || $firstName === '' || $lastName === '') {
            return $this->page('client-auth.register', ['error' => 'Email, first name, and last name are required.', 'refCode' => $refCode, 'googleUser' => $googleUser, 'googleClientId' => $this->googleClientId()]);
        }

        // The address submitted here is the recipient of the OTP email, and
        // registration is reachable without any login — so it must be a real,
        // single email address before it is used. Without this check a crafted
        // value (e.g. containing CR/LF) would flow straight into the mail
        // transport, which the SMTP layer now also refuses to send (see
        // SmtpMailer::assertSafeAddress) — defence in depth at both ends.
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->page('client-auth.register', ['error' => 'Enter a valid email address.', 'refCode' => $refCode, 'googleUser' => $googleUser, 'googleClientId' => $this->googleClientId()]);
        }

        if (strlen($securityPin) < 4) {
            return $this->page('client-auth.register', ['error' => 'A Security PIN of at least 4 characters is required.', 'refCode' => $refCode, 'googleUser' => $googleUser, 'googleClientId' => $this->googleClientId()]);
        }

        if (strlen($password) < 8) {
            return $this->page('client-auth.register', ['error' => 'Password must be at least 8 characters.', 'refCode' => $refCode, 'googleUser' => $googleUser, 'googleClientId' => $this->googleClientId()]);
        }

        // Say WHICH kind of "already exists" this is. "You already have an account here"
        // and "that address belongs to another provider on this platform" need different
        // next steps (sign in, versus sign in THERE and ask for an account move), and a
        // reseller's customer trying to sign up with the platform or another reseller
        // deserves to be told which. Never which provider: that is another business's
        // customer list.
        $existing = $this->clients->findByEmail($email);

        if ($existing !== null) {
            return $this->registerPage([
                'error' => 'An account with that email already exists.',
                'accountExists' => $this->existingAccountKind($existing),
            ], $googleUser, $refCode);
        }

        $pending = compact('email', 'password', 'firstName', 'lastName', 'refCode', 'country', 'vatNumber', 'phone', 'address1', 'city', 'postcode', 'securityPin');
        // Which website this sign-up started on. Kept with the pending registration so
        // the OTP step can refuse to finish it anywhere else.
        $pending['storeId'] = $this->currentStore?->id();

        // Google already proved this person controls the email address they
        // signed up with — an OTP round-trip to the same inbox verifies
        // nothing further. Plain email/password signup is the only path
        // where nobody has confirmed the address is real and reachable, so
        // that's the only one gated behind a code.
        if ($googleUser) {
            return $this->finishRegistration($pending, $request->ip());
        }

        // Registration, the OTP resend and both password-reset endpoints are
        // all reachable with no login, so each is throttled — a cooldown per
        // address and a cap per IP — so the app's own mail transport cannot
        // be scripted into sending bulk email. See
        // ClientRegistrationOtpRepository::cooldownRemaining()/tooManyIssuesFromIp().
        if ($this->registrationOtps->cooldownRemaining($email) > 0 || $this->registrationOtps->tooManyIssuesFromIp($request->ip())) {
            return $this->page('client-auth.register', [
                'error' => 'Too many verification codes have been requested. Please wait a little while and try again.',
                'refCode' => $refCode,
                'googleUser' => $googleUser,
                'googleClientId' => $this->googleClientId(),
            ]);
        }

        $this->session->set(self::PENDING_REGISTRATION_SESSION_KEY, $pending);
        $this->sendRegistrationOtp($email, $firstName, $request->ip());

        return Response::redirect('/client/register/verify');
    }

    public function registerVerifyForm(Request $request): Response
    {
        $pending = $this->session->get(self::PENDING_REGISTRATION_SESSION_KEY);

        if ($pending === null) {
            return Response::redirect('/client/register');
        }

        return $this->page('client-auth.register-verify', [
            'email' => $pending['email'],
            'error' => null,
            'resent' => $request->query('resent') === '1',
        ]);
    }

    public function registerVerify(Request $request): Response
    {
        $pending = $this->session->get(self::PENDING_REGISTRATION_SESSION_KEY);

        if ($pending === null) {
            return Response::redirect('/client/register');
        }

        // A sign-up started on one website is finished on that website only. Sessions
        // are per host so this should never differ, but the store an account belongs to
        // is too important to rest on that alone.
        if (array_key_exists('storeId', $pending) && $pending['storeId'] !== $this->currentStore?->id()) {
            $this->session->remove(self::PENDING_REGISTRATION_SESSION_KEY);

            return Response::redirect('/client/register');
        }

        $code = trim((string) $request->input('code', ''));

        if ($code === '' || !$this->registrationOtps->verify($pending['email'], $code)) {
            return $this->page('client-auth.register-verify', [
                'email' => $pending['email'],
                'error' => 'That code is incorrect or has expired. Please try again or request a new one.',
                'resent' => false,
            ]);
        }

        return $this->finishRegistration($pending, $request->ip());
    }

    public function registerResendOtp(Request $request): Response
    {
        $pending = $this->session->get(self::PENDING_REGISTRATION_SESSION_KEY);

        if ($pending === null) {
            return Response::redirect('/client/register');
        }

        $email = (string) $pending['email'];

        // Same no-login throttle as register(): this endpoint re-sends an
        // email on every hit, so without a cooldown it is a one-line loop for
        // flooding a single inbox.
        if ($this->registrationOtps->cooldownRemaining($email) > 0 || $this->registrationOtps->tooManyIssuesFromIp($request->ip())) {
            return $this->page('client-auth.register-verify', [
                'email' => $email,
                'error' => 'A verification code was sent very recently. Please wait a moment before requesting another one.',
                'resent' => false,
            ]);
        }

        $this->sendRegistrationOtp($email, (string) $pending['firstName'], $request->ip());

        return Response::redirect('/client/register/verify?resent=1');
    }

    /** @param array<string, mixed> $pending */
    private function finishRegistration(array $pending, string $ip): Response
    {
        $googleUser = $this->session->get('google_user');

        $result = $this->auth->register(
            $pending['email'],
            $pending['password'],
            $pending['firstName'],
            $pending['lastName'],
            $ip,
            $pending['country'],
            $pending['vatNumber'],
            $pending['phone'],
            $pending['address1'],
            $pending['city'],
            $pending['postcode'],
            $pending['securityPin'],
            // Stamped in the INSERT itself: an account created on a store's website is
            // that store's customer from its very first moment.
            $this->registrationStoreId($pending)
        );

        if (!$result['success']) {
            // The address was taken between the form and the code (a second tab, or a
            // race): same two-way answer as register() gives.
            $raced = $this->clients->findByEmail((string) $pending['email']);

            return $this->registerPage([
                'error' => $result['error'],
                'accountExists' => $raced !== null ? $this->existingAccountKind($raced) : null,
            ], $googleUser, (string) $pending['refCode']);
        }

        $result['client'] = $this->claimForCurrentStore($result['client']);

        $this->session->remove('google_user');
        $this->session->remove(self::PENDING_REGISTRATION_SESSION_KEY);
        $this->registrationOtps->invalidate($pending['email']);
        $this->affiliateService->registerReferral($pending['refCode'], (int) $result['client']['id']);
        $this->guard->login($result['client']);

        return $this->afterSignIn();
    }

    /**
     * Where a client lands once they are signed in.
     *
     * A Free Reseller application started as a guest comes first: the applicant
     * left the form only to get an account, and their answers are waiting at
     * /free-reseller/apply. Main website only — that programme is never offered on
     * a reseller's store, so a draft can never pull a store's customer into it.
     * Otherwise an order in progress resumes at the cart, as it always has.
     */
    private function afterSignIn(): Response
    {
        if ($this->currentStore?->id() === null
            && is_array($this->session->get(\CodeVault\Reseller\FreeResellerProgramme::DRAFT_SESSION_KEY))) {
            return Response::redirect('/free-reseller/apply?resume=1');
        }

        if (!empty($this->session->get('cart_items', []))) {
            return Response::redirect('/cart');
        }

        return Response::redirect('/client/dashboard');
    }

    /**
     * The store an account being registered belongs to: the store whose website is
     * serving this request. Null on the platform's own site.
     *
     * @param array<string, mixed> $pending
     */
    private function registrationStoreId(array $pending): ?int
    {
        $storeId = $this->currentStore?->id();

        return $storeId !== null && $storeId > 0 ? $storeId : null;
    }

    /**
     * An account created on a store's site is that store's customer FROM THE START.
     *
     * Previously the claim waited for the first order, so somebody who registered on a
     * store and had not bought yet was, as far as the data knew, ours — written to as
     * us, and able to sign in on our site. Stamping it here is the same atomic,
     * claim-only-if-unclaimed update the checkout uses (setResellerIfUnclaimed), so it
     * can never take an account away from an owner it already has.
     *
     * @param array<string, mixed> $client
     * @return array<string, mixed>
     */
    private function claimForCurrentStore(array $client): array
    {
        $storeId = $this->currentStore?->id();

        if ($storeId === null || empty($client['id'])) {
            return $client;
        }

        try {
            $this->clients->setResellerIfUnclaimed((int) $client['id'], $storeId);

            return $this->clients->find((int) $client['id']) ?? $client;
        } catch (Throwable $e) {
            // Not silent: an account that ends up on the wrong side of the isolation
            // line must leave a trace. (The INSERT already carries the store, so this
            // is a second line of defence only.)
            error_log('[CodeVault] could not assign new client #' . (int) $client['id'] . ' to store #' . $storeId . ': ' . $e->getMessage());
            return $client;
        }
    }

    private function sendRegistrationOtp(string $email, string $firstName, string $ip): void
    {
        $code = $this->registrationOtps->issue($email, $ip);

        $this->mail->sendTemplate('client_registration_otp', $email, [
            'first_name' => $firstName !== '' ? $firstName : 'there',
            'code' => $code,
            'expiry_minutes' => (string) ClientRegistrationOtpRepository::EXPIRY_MINUTES,
            'company_name' => brand_name(),
        ]);
    }

    public function logout(Request $request): Response
    {
        $this->guard->logout();

        return Response::redirect('/client/login');
    }

    public function recoverPinForm(Request $request): Response
    {
        if ($this->guard->check()) {
            return Response::redirect('/client/dashboard');
        }

        return $this->page('client-auth.recover-pin', ['error' => null, 'success' => false]);
    }

    public function recoverPin(Request $request): Response
    {
        if ($this->guard->check()) {
            return Response::redirect('/client/dashboard');
        }

        $email = trim((string) $request->input('email', ''));
        $pin = trim((string) $request->input('security_pin', ''));

        if ($email === '' || $pin === '') {
            return $this->page('client-auth.recover-pin', ['error' => 'Email and Security PIN are required.', 'success' => false]);
        }

        $isValid = $this->auth->verifySecurityPin($email, $pin, $request->ip());

        if (!$isValid) {
            return $this->page('client-auth.recover-pin', ['error' => 'Invalid email or Security PIN.', 'success' => false], 403);
        }

        return $this->page('client-auth.recover-pin', ['error' => null, 'success' => true]);
    }

    public function forgotPasswordForm(Request $request): Response
    {
        return $this->page('client-auth.forgot-password', ['sent' => false]);
    }

    /**
     * Always shows the same "if that email exists" confirmation whether or
     * not the account exists — telling a bad actor which emails are
     * registered is exactly the enumeration leak a reset-request endpoint
     * must not create.
     */
    public function sendResetLink(Request $request): Response
    {
        $email = trim((string) $request->input('email', ''));
        $client = $email !== '' ? $this->clients->findByEmail($email) : null;

        // Public + one email per call: only issue/send when the account has
        // not just had a reset link (see PasswordResetTokenRepository::
        // recentlyIssued()). The response stays identical either way so the
        // anti-enumeration property is preserved.
        if ($client !== null && !$this->resetTokens->recentlyIssued(self::RESET_ACCOUNT_TYPE, (int) $client['id'])) {
            $issued = $this->resetToken->generate();
            $this->resetTokens->issue(self::RESET_ACCOUNT_TYPE, (int) $client['id'], $issued['hash']);

            $baseUrl = rtrim((string) $this->config->env('APP_URL', ''), '/');
            $resetUrl = "{$baseUrl}/client/password/reset/{$issued['token']}";

            try {
                $this->mail->sendTemplate('client_password_reset', $client['email'], [
                    'first_name' => $client['first_name'],
                    'reset_url' => $resetUrl,
                    'company_name' => brand_name(),
                ], (int) $client['id']);
            } catch (Throwable) {
                // Template missing/misconfigured shouldn't leak via the response.
            }
        }

        return $this->page('client-auth.forgot-password', ['sent' => true]);
    }

    public function resetPasswordForm(Request $request, array $params): Response
    {
        $tokenRow = $this->resetTokens->findValid(self::RESET_ACCOUNT_TYPE, $this->resetToken->hash((string) $params['token']));

        if ($tokenRow === null) {
            return $this->page('client-auth.reset-password-invalid', []);
        }

        return $this->page('client-auth.reset-password', [
            'token' => $params['token'],
            'error' => null,
            'securityQuestion' => $this->securityQuestions->promptFor((int) $tokenRow['account_id']),
        ]);
    }

    /**
     * A valid reset token proves email possession ("something you have").
     * When a client has opted into a security question, this adds
     * "something you know" on top of that — real defense-in-depth for the
     * case where the reset link itself is what's compromised (a hijacked
     * inbox), not a replacement for the token. Clients who never set one up
     * see no change at all.
     */
    public function resetPassword(Request $request, array $params): Response
    {
        $token = (string) $params['token'];
        $tokenRow = $this->resetTokens->findValid(self::RESET_ACCOUNT_TYPE, $this->resetToken->hash($token));

        if ($tokenRow === null) {
            return $this->page('client-auth.reset-password-invalid', []);
        }

        $accountId = (int) $tokenRow['account_id'];
        $securityQuestion = $this->securityQuestions->promptFor($accountId);

        if ($securityQuestion !== null) {
            $answer = (string) $request->input('security_answer', '');

            if (!$this->securityQuestions->verify($accountId, $answer)) {
                return $this->page('client-auth.reset-password', [
                    'token' => $token,
                    'error' => 'That answer did not match — your password was not changed.',
                    'securityQuestion' => $securityQuestion,
                ]);
            }
        }

        $newPassword = (string) $request->input('new_password', '');

        if (strlen($newPassword) < 8) {
            return $this->page('client-auth.reset-password', [
                'token' => $token,
                'error' => 'Password must be at least 8 characters.',
                'securityQuestion' => $securityQuestion,
            ]);
        }

        $this->clients->updatePassword($accountId, $newPassword);
        $this->resetTokens->consume((int) $tokenRow['id']);

        return Response::redirect('/client/login?reset=success');
    }

    public function returnToAdmin(Request $request): Response
    {
        $originalAdminId = $this->session->get('original_admin_id');

        if ($originalAdminId !== null) {
            $this->session->set('admin_id', $originalAdminId);
            $this->session->remove('original_admin_id');
            $this->session->remove('client_id');
            return Response::redirect('/admin/clients');
        }

        return Response::redirect('/client/dashboard');
    }

    /**
     * Starts "Sign in with Google" with THIS site's own Google app — the platform's on
     * the main website, the reseller's own on their store (see GoogleSignIn for why a
     * store never borrows ours).
     */
    public function googleRedirect(Request $request): Response
    {
        $google = $this->google();
        $credentials = $google->credentials();

        if ($credentials === null) {
            return Response::redirect('/client/login');
        }

        $state = GoogleSignIn::newState();
        $redirectUri = $google->redirectUri($request);

        // The exact redirect address goes in the session with the state: Google insists
        // the token request repeats it byte for byte, and the callback must not rebuild
        // it from a Host header that could differ.
        $this->session->set(GoogleSignIn::SESSION_KEY, [
            'state' => $state,
            'redirect' => $redirectUri,
            'owner' => $credentials['owner'],
            'storeId' => $credentials['storeId'],
            'at' => time(),
        ]);

        return Response::redirect(GoogleSignIn::authorizeUrl($credentials['clientId'], $redirectUri, $state));
    }

    public function googleCallback(Request $request): Response
    {
        $google = $this->google();
        $credentials = $google->credentials();

        $pending = GoogleSignIn::matchPending(
            $this->session->get(GoogleSignIn::SESSION_KEY),
            (string) $request->query('state', '')
        );
        $this->session->remove(GoogleSignIn::SESSION_KEY);

        if ((string) $request->query('error', '') !== '') {
            return Response::redirect('/client/login?error=google_cancelled');
        }

        // A callback we did not start (or started on another site's Google app) is
        // refused: otherwise a forged link could sign a visitor into SOMEONE ELSE's
        // account, or carry a sign-in from one provider's site onto another's.
        if (
            $credentials === null
            || $pending === null
            || $pending['owner'] !== $credentials['owner']
            || $pending['storeId'] !== $credentials['storeId']
        ) {
            return Response::redirect('/client/login?error=google_expired');
        }

        $code = (string) $request->query('code', '');

        if ($code === '') {
            return Response::redirect('/client/login?error=google_failed');
        }

        $profile = $google->fetchProfile($credentials, $code, $pending['redirect']);

        if ($profile === null) {
            return Response::redirect('/client/login?error=google_failed');
        }

        $existing = $this->clients->findByEmail($profile['email']);

        if ($existing !== null) {
            // Same isolation as the password path: proving you own the address at
            // Google does not make another provider's account usable on this site.
            if ($this->belongsElsewhere($existing)) {
                return $this->page('client-auth.login', $this->loginData(['error' => \CodeVault\Reseller\ClientSiteAccess::otherProviderMessage()]));
            }

            if (($existing['status'] ?? '') === 'closed') {
                return $this->page('client-auth.login', $this->loginData(['error' => 'This account is closed. Please contact support.']));
            }

            // Google proves the email address, not the second factor. An account that
            // has two-factor sign-in on still has to give its code.
            $twoFactorOn = $this->settings->get('security.2fa_enabled', '1') === '1'
                && (int) ($existing['two_factor_enabled'] ?? 0) === 1;

            if ($twoFactorOn) {
                $this->session->set(self::PENDING_2FA_SESSION_KEY, $existing['id']);

                return Response::redirect('/client/login/2fa');
            }

            return $this->completeTwoFactorLogin($existing);
        }

        // New here: finish sign-up with the details Google gave us. The registration
        // belongs to the site being served (a store's customer on a store), exactly as
        // a password sign-up would.
        $this->session->set('google_user', $profile);

        return Response::redirect('/client/register');
    }

    /** The Google client id when the button should be shown on this site, else ''. */
    private function googleClientId(): string
    {
        try {
            return $this->google()->buttonClientId();
        } catch (Throwable) {
            // A settings read failing must not take the sign-in page down with it.
            return '';
        }
    }

    private function google(): GoogleSignIn
    {
        return $this->google ?? new GoogleSignIn($this->settings, $this->config, $this->currentStore);
    }

    /**
     * Everything the login page needs, in one place — the Google button used to be
     * missing because the login page was the one render that never passed it.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function loginData(array $extra = []): array
    {
        return $extra + [
            'error' => null,
            'resetSuccess' => false,
            'googleClientId' => $this->googleClientId(),
        ];
    }

    /** The friendly message for a ?error= code Google sign-in sends back to the login page. */
    public static function googleErrorMessage(string $code): ?string
    {
        return match ($code) {
            'google_cancelled' => 'Google sign-in was cancelled. You can try again or sign in with your email and password.',
            'google_expired' => 'That Google sign-in link has expired. Please click "Sign in with Google" again.',
            'google_failed' => 'We could not sign you in with Google. Please try again, or sign in with your email and password.',
            default => null,
        };
    }

    private function page(string $template, array $data, int $status = 200): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.client', [
            'title' => 'CodeVault',
            'content' => $content,
        ]), $status);
    }
}
