<?php

declare(strict_types=1);

namespace CodeVault\Clients;

use CodeVault\Config;
use CodeVault\Provisioning\CurlHttpClient;
use CodeVault\Provisioning\HttpClient;
use CodeVault\Request;
use CodeVault\Reseller\CurrentReseller;
use CodeVault\Security\SecretBox;
use CodeVault\Settings\SettingsRepository;
use Throwable;

/**
 * "Sign in with Google" for the website being served — the platform's own site OR a
 * reseller's white-label store, each with its OWN Google app.
 *
 * Why every store brings its own Google app rather than borrowing the platform's:
 *
 *  - Google sends the visitor back to a redirect address that must be registered in
 *    the Google app's console. The platform's app only knows the platform's host, so a
 *    store customer using it would be bounced onto the platform's domain — exposing it
 *    (strict isolation forbids that) and finishing sign-up as the PLATFORM's customer.
 *  - Google's consent screen shows the app's name and logo. With the reseller's own
 *    app, their customers see the reseller's brand, never ours.
 *
 * So the rule is simple and has no fallbacks: on a store, only that store's own
 * credentials are used (and only when the store has switched Google on and the super
 * admin allows resellers to); on the platform, only the platform's. A store never
 * inherits the platform's app, and the platform never uses a store's.
 *
 * The flow itself (one place, for both kinds of site):
 *   redirect   → a random `state` is kept in the session together with the exact
 *                redirect address used, and the browser is sent to Google;
 *   callback   → the state must match (stops a forged callback logging someone into
 *                the attacker's Google account), the code is swapped for a token at
 *                Google, and the verified profile is returned.
 */
final class GoogleSignIn
{
    public const KEY_CLIENT_ID = 'auth.google_client_id';
    public const KEY_CLIENT_SECRET = 'auth.google_client_secret';
    /** Super-admin switch: may resellers offer Google sign-in on their stores? Default yes. */
    public const KEY_RESELLERS_ALLOWED = 'auth.google_resellers_allowed';

    public const CALLBACK_PATH = '/client/auth/google/callback';
    public const SESSION_KEY = 'google_oauth';
    /** How long a started sign-in may take before the callback is refused. */
    public const STATE_TTL_SECONDS = 900;

    public const AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    public const USERINFO_URL = 'https://www.googleapis.com/oauth2/v2/userinfo';

    public const OWNER_PLATFORM = 'platform';
    public const OWNER_STORE = 'store';

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly Config $config,
        private readonly ?CurrentReseller $currentStore = null,
        private readonly ?SecretBox $secrets = null,
        private readonly ?HttpClient $http = null
    ) {
    }

    // ----------------------------------------------------------------------
    // Which Google app applies to the site being served
    // ----------------------------------------------------------------------

    /**
     * The credentials for THIS site, or null when Google sign-in is off here.
     *
     * @return array{clientId: string, clientSecret: string, owner: string, storeId: ?int}|null
     */
    public function credentials(): ?array
    {
        $store = $this->currentStore?->get();

        if ($store !== null) {
            return $this->storeCredentials($store);
        }

        $clientId = trim((string) $this->settings->get(self::KEY_CLIENT_ID, ''));
        $secret = trim((string) $this->settings->get(self::KEY_CLIENT_SECRET, ''));

        if ($clientId === '' || $secret === '') {
            return null;
        }

        return ['clientId' => $clientId, 'clientSecret' => $secret, 'owner' => self::OWNER_PLATFORM, 'storeId' => null];
    }

    /**
     * A store's own credentials, when it may and does use Google sign-in.
     *
     * @param array<string, mixed> $store a `resellers` row
     * @return array{clientId: string, clientSecret: string, owner: string, storeId: ?int}|null
     */
    public function storeCredentials(array $store): ?array
    {
        if (!$this->resellersAllowed()) {
            return null;
        }

        if ((int) ($store['google_enabled'] ?? 0) !== 1 || ($store['status'] ?? 'active') !== 'active') {
            return null;
        }

        $clientId = trim((string) ($store['google_client_id'] ?? ''));
        $secret = $this->openSecret(isset($store['google_client_secret']) ? (string) $store['google_client_secret'] : null);

        if ($clientId === '' || $secret === null || $secret === '') {
            return null;
        }

        return ['clientId' => $clientId, 'clientSecret' => $secret, 'owner' => self::OWNER_STORE, 'storeId' => (int) $store['id']];
    }

    public function enabled(): bool
    {
        return $this->credentials() !== null;
    }

    /** The client id to show the button for, or '' when the button must not appear. */
    public function buttonClientId(): string
    {
        return $this->credentials()['clientId'] ?? '';
    }

    public function resellersAllowed(): bool
    {
        return (string) $this->settings->get(self::KEY_RESELLERS_ALLOWED, '1') !== '0';
    }

    // ----------------------------------------------------------------------
    // Redirect addresses
    // ----------------------------------------------------------------------

    /**
     * Where Google must send the visitor back to, for the site being served.
     *
     * On a store: the host the store was MATCHED on (CurrentReseller::host()), never
     * the raw Host header. On the platform: APP_URL, as before, falling back to the
     * request's own address only for a local install.
     */
    public function redirectUri(Request $request): string
    {
        $storeHost = $this->currentStore?->get() !== null ? $this->currentStore->host() : null;

        if ($storeHost !== null && $storeHost !== '') {
            $scheme = str_starts_with($request->baseUrl(), 'https://') ? 'https' : $this->platformScheme();

            return $scheme . '://' . $storeHost . self::CALLBACK_PATH;
        }

        return rtrim(self::platformBaseUrl($this->configuredAppUrl(), $request->baseUrl()), '/') . self::CALLBACK_PATH;
    }

    /** The platform's own callback address, for the admin's setup instructions. */
    public static function platformBaseUrl(string $configuredUrl, string $requestBaseUrl): string
    {
        return ($configuredUrl !== '' && !str_contains($configuredUrl, 'localhost')) ? $configuredUrl : $requestBaseUrl;
    }

    /**
     * Every callback address a store's Google app should list: one per host the store
     * can be served on (its free address and its own domain).
     *
     * @param array<int, string|null> $hosts
     * @return list<string>
     */
    public static function callbackUrlsFor(array $hosts, string $scheme = 'https'): array
    {
        $urls = [];

        foreach ($hosts as $host) {
            $host = strtolower(trim((string) $host));

            if ($host !== '') {
                $urls[$host] = $scheme . '://' . $host . self::CALLBACK_PATH;
            }
        }

        return array_values($urls);
    }

    // ----------------------------------------------------------------------
    // The OAuth round trip
    // ----------------------------------------------------------------------

    /**
     * Google's consent address. `select_account` lets someone signed in to several
     * Google accounts pick the right one instead of being signed in as whichever
     * happens to be first.
     */
    public static function authorizeUrl(string $clientId, string $redirectUri, string $state): string
    {
        return self::AUTHORIZE_URL . '?' . http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'access_type' => 'online',
            'prompt' => 'select_account',
            'state' => $state,
        ]);
    }

    public static function newState(): string
    {
        return bin2hex(random_bytes(24));
    }

    /**
     * The pending sign-in saved in the session, if it matches the state Google returned
     * and has not expired. Null means "refuse this callback".
     *
     * @param mixed $pending what was stored under SESSION_KEY
     * @return array{state: string, redirect: string, owner: string, storeId: ?int, at: int}|null
     */
    public static function matchPending(mixed $pending, string $returnedState, ?int $now = null): ?array
    {
        if (!is_array($pending) || $returnedState === '') {
            return null;
        }

        $state = (string) ($pending['state'] ?? '');
        $at = (int) ($pending['at'] ?? 0);

        if ($state === '' || !hash_equals($state, $returnedState)) {
            return null;
        }

        if (($now ?? time()) - $at > self::STATE_TTL_SECONDS) {
            return null;
        }

        return [
            'state' => $state,
            'redirect' => (string) ($pending['redirect'] ?? ''),
            'owner' => (string) ($pending['owner'] ?? ''),
            'storeId' => isset($pending['storeId']) ? (int) $pending['storeId'] : null,
            'at' => $at,
        ];
    }

    /**
     * Swaps Google's one-time code for the visitor's verified profile.
     *
     * Only an address Google has VERIFIED is accepted: an unverified Gmail-less Google
     * account can carry any address, and signing in with it would hand over the
     * account of whoever really owns that mailbox.
     *
     * @param array{clientId: string, clientSecret: string} $credentials
     * @return array{email: string, first_name: string, last_name: string, google_id: string}|null
     */
    public function fetchProfile(array $credentials, string $code, string $redirectUri): ?array
    {
        $http = $this->http ?? new CurlHttpClient(timeoutSeconds: 20);

        try {
            $tokenResponse = $http->request('POST', self::TOKEN_URL, [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept' => 'application/json',
            ], http_build_query([
                'client_id' => $credentials['clientId'],
                'client_secret' => $credentials['clientSecret'],
                'code' => $code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $redirectUri,
            ]));

            $token = json_decode((string) ($tokenResponse['body'] ?? ''), true);
            $accessToken = is_array($token) ? (string) ($token['access_token'] ?? '') : '';

            if ($accessToken === '') {
                return null;
            }

            $userResponse = $http->request('GET', self::USERINFO_URL, [
                'Authorization' => 'Bearer ' . $accessToken,
                'Accept' => 'application/json',
            ]);
        } catch (Throwable) {
            return null;
        }

        return self::profileFrom((string) ($userResponse['body'] ?? ''));
    }

    /**
     * @return array{email: string, first_name: string, last_name: string, google_id: string}|null
     */
    public static function profileFrom(string $body): ?array
    {
        $user = json_decode($body, true);

        if (!is_array($user)) {
            return null;
        }

        $email = strtolower(trim((string) ($user['email'] ?? '')));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        // v2 userinfo says `verified_email`; the OpenID endpoint says `email_verified`.
        $verified = $user['verified_email'] ?? $user['email_verified'] ?? null;

        if ($verified !== true && $verified !== 'true') {
            return null;
        }

        return [
            'email' => $email,
            'first_name' => trim((string) ($user['given_name'] ?? '')),
            'last_name' => trim((string) ($user['family_name'] ?? '')),
            'google_id' => (string) ($user['id'] ?? $user['sub'] ?? ''),
        ];
    }

    // ----------------------------------------------------------------------
    // Validating and storing a store's credentials
    // ----------------------------------------------------------------------

    /** The shape Google issues: `<digits>-<hash>.apps.googleusercontent.com`. */
    public static function looksLikeClientId(string $value): bool
    {
        return (bool) preg_match('/^[0-9]{6,}-[a-z0-9_]{8,}\.apps\.googleusercontent\.com$/i', $value);
    }

    /** Google secrets are `GOCSPX-…` today; older ones are plain base64url. No spaces, no markup. */
    public static function looksLikeSecret(string $value): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_\-]{16,191}$/', $value);
    }

    /**
     * Checks what a reseller submitted. A blank secret means "keep the saved one".
     *
     * @return array{success: bool, error: ?string, enabled: bool, clientId: ?string, secret: ?string}
     */
    public static function validateStoreSettings(bool $enabled, string $clientId, string $secret, bool $hasSavedSecret): array
    {
        $clientId = trim($clientId);
        $secret = trim($secret);
        $fail = static fn (string $error): array => ['success' => false, 'error' => $error, 'enabled' => $enabled, 'clientId' => null, 'secret' => null];

        if ($clientId !== '' && !self::looksLikeClientId($clientId)) {
            return $fail('That is not a Google client ID. It ends in .apps.googleusercontent.com — copy it from '
                . 'Google Cloud Console → APIs & Services → Credentials.');
        }

        if ($secret !== '' && !self::looksLikeSecret($secret)) {
            return $fail('That is not a Google client secret. It usually starts with GOCSPX- and has no spaces.');
        }

        if ($enabled && $clientId === '') {
            return $fail('Paste your Google client ID before switching Google sign-in on.');
        }

        if ($enabled && $secret === '' && !$hasSavedSecret) {
            return $fail('Paste your Google client secret before switching Google sign-in on.');
        }

        return ['success' => true, 'error' => null, 'enabled' => $enabled, 'clientId' => $clientId === '' ? null : $clientId, 'secret' => $secret === '' ? null : $secret];
    }

    /** How a secret is written to the database: encrypted when APP_KEY allows it. */
    public function sealSecret(string $secret): string
    {
        if ($this->secrets !== null && $this->secrets->available()) {
            return $this->secrets->encrypt($secret);
        }

        return $secret;
    }

    /** The plaintext of a stored secret (encrypted or, on an install without APP_KEY, plain). */
    public function openSecret(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        if (str_starts_with($stored, 'sb1:')) {
            return $this->secrets?->decrypt($stored);
        }

        return $stored;
    }

    private function configuredAppUrl(): string
    {
        return (string) ($this->config->get('app.url') ?: $this->config->env('APP_URL', ''));
    }

    private function platformScheme(): string
    {
        $scheme = (string) (parse_url($this->configuredAppUrl(), PHP_URL_SCHEME) ?: 'https');

        return in_array($scheme, ['http', 'https'], true) ? $scheme : 'https';
    }
}
