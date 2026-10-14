<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Billing\PromotionRepository;
use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Marketing\PromoBannerInput;
use CodeVault\Marketing\PromoBannerPages;
use CodeVault\Marketing\PromoBannerRepository;
use CodeVault\Marketing\PromoBannerTemplates;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Session\SessionManager;
use CodeVault\View;

/**
 * A store's OWN discount codes and promo popup.
 *
 * WHY A STORE HAS ITS OWN
 *
 * The platform's promo popup used to appear on every reseller's website, because
 * banners had no owner and the popup asked only "which banner targets this page?".
 * That put OUR offer, with OUR coupon, inside somebody else's white-label shop —
 * and the coupon then discounted the reseller's retail. Banners and codes now
 * belong to exactly one site (migration 0204), and this page is where a reseller
 * manages the ones that belong to their store.
 *
 * ISOLATION IS ENFORCED IN THE QUERIES, NOT HERE
 *
 * No store id is ever read from the request. The store is resolved from the
 * session guard, and every repository call below carries that id INTO the SQL
 * (`... AND reseller_id = ?`), so an id in a URL that belongs to another store —
 * or to the platform — matches no row and changes nothing. A reseller cannot see,
 * edit, pause or delete anyone else's banner or code, and a banner can only
 * advertise one of the store's own codes (PromoBannerInput looks the coupon up in
 * the store's scope).
 *
 * WHAT A DISCOUNT COSTS, AND WHO PAYS IT
 *
 * A store's code reduces what THEIR customer pays; what the reseller owes us for
 * the order is unchanged (CartService keeps the cost total separate from the
 * discount). So a reseller's campaign is spent from their own margin, never ours,
 * which is what makes it safe to let them run one without approval.
 *
 * A separate controller rather than methods on ClientResellerController, for the
 * same reason as the mail and ticket pages: that one is built by hand in tests and
 * every new constructor dependency is a test edit.
 */
final class ClientResellerPromotionsController
{
    private const BASE = '/client/reseller/promotions';

    public function __construct(
        private readonly ClientAuthGuard $guard,
        private readonly View $view,
        private readonly SessionManager $session,
        private readonly ResellerStoreRepository $stores,
        private readonly PromotionRepository $promotions,
        private readonly PromoBannerRepository $banners
    ) {
    }

    public function index(Request $request): Response
    {
        $store = $this->storeOrRedirect();

        if ($store instanceof Response) {
            return $store;
        }

        return $this->page($store, null);
    }

    // ------------------------------------------------------------- promo codes

    /** Create a code, or update the store's existing code with the same name. */
    public function saveCode(Request $request): Response
    {
        $store = $this->storeOrRedirect();

        if ($store instanceof Response) {
            return $store;
        }

        $code = strtoupper(trim((string) $request->input('code', '')));
        $type = (string) $request->input('type', 'percentage');
        $value = $request->input('value', '');
        $maxRedemptions = trim((string) $request->input('max_redemptions', ''));
        $minOrder = trim((string) $request->input('min_order_amount', ''));
        $startsAt = $this->date((string) $request->input('starts_at', ''));
        $expiresAt = $this->date((string) $request->input('expires_at', ''));
        $status = (string) $request->input('status', 'active') === 'inactive' ? 'inactive' : 'active';

        $error = null;

        if (preg_match('~^[A-Z0-9_-]{3,50}$~', $code) !== 1) {
            $error = 'A code is 3-50 letters, numbers, dashes or underscores — for example SAVE10.';
        } elseif (!in_array($type, ['percentage', 'fixed'], true)) {
            $error = 'Choose a percentage or a fixed amount off.';
        } elseif (!is_numeric($value) || (float) $value <= 0.0 || !is_finite((float) $value)) {
            $error = 'Enter the discount as a number greater than zero.';
        } elseif ($type === 'percentage' && (float) $value > 100.0) {
            $error = 'A percentage discount cannot be more than 100%.';
        } elseif ($maxRedemptions !== '' && (!ctype_digit($maxRedemptions) || (int) $maxRedemptions < 1)) {
            $error = 'The usage limit must be a whole number of at least 1, or blank for unlimited.';
        } elseif ($minOrder !== '' && (!is_numeric($minOrder) || (float) $minOrder < 0.0)) {
            $error = 'The minimum order must be zero or more.';
        } elseif ($startsAt !== null && $expiresAt !== null && $expiresAt < $startsAt) {
            $error = 'The code cannot expire before it starts.';
        }

        if ($error !== null) {
            $this->session->flash('reseller_error', $error);

            return Response::redirect(self::BASE);
        }

        $existed = $this->promotions->findByCode($code, (int) $store['id']) !== null;

        $this->promotions->save([
            'code' => $code,
            'type' => $type,
            'value' => round((float) $value, 2),
            'max_redemptions' => $maxRedemptions === '' ? null : (int) $maxRedemptions,
            'min_order_amount' => $minOrder === '' ? 0.0 : round((float) $minOrder, 2),
            'starts_at' => $startsAt,
            'expires_at' => $expiresAt,
            'status' => $status,
        ], (int) $store['id']);

        $this->session->flash(
            'reseller_notice',
            ($existed ? 'Updated ' : 'Created ') . $code . '. It works only on your store.'
        );

        return Response::redirect(self::BASE);
    }

    public function toggleCode(Request $request, array $params): Response
    {
        $store = $this->storeOrRedirect();

        if ($store instanceof Response) {
            return $store;
        }

        $promotion = $this->promotions->findForReseller((int) ($params['id'] ?? 0), (int) $store['id']);

        if ($promotion === null) {
            return $this->notYours();
        }

        $promotion['status'] = ($promotion['status'] ?? 'active') === 'active' ? 'inactive' : 'active';
        $this->promotions->save($promotion, (int) $store['id']);

        $this->session->flash(
            'reseller_notice',
            $promotion['code'] . ($promotion['status'] === 'active' ? ' is active again.' : ' is switched off.')
        );

        return Response::redirect(self::BASE);
    }

    public function deleteCode(Request $request, array $params): Response
    {
        $store = $this->storeOrRedirect();

        if ($store instanceof Response) {
            return $store;
        }

        $promotion = $this->promotions->findForReseller((int) ($params['id'] ?? 0), (int) $store['id']);

        if ($promotion === null) {
            return $this->notYours();
        }

        $this->promotions->deleteForReseller((int) $promotion['id'], (int) $store['id']);
        $this->session->flash('reseller_notice', 'Deleted ' . $promotion['code'] . '.');

        return Response::redirect(self::BASE);
    }

    // ------------------------------------------------------------ promo banners

    public function createBanner(Request $request): Response
    {
        $store = $this->storeOrRedirect();

        if ($store instanceof Response) {
            return $store;
        }

        [$fields, $error] = $this->readBanner($request, (int) $store['id']);

        if ($error !== null) {
            return $this->page($store, $error);
        }

        $fields['status'] = 'active';
        $this->banners->create($fields, (int) $store['id']);
        $this->session->flash('reseller_notice', 'Banner created — it is live on your storefront now.');

        return Response::redirect(self::BASE);
    }

    public function updateBanner(Request $request, array $params): Response
    {
        $store = $this->storeOrRedirect();

        if ($store instanceof Response) {
            return $store;
        }

        $id = (int) ($params['id'] ?? 0);

        if ($this->banners->findScoped($id, (int) $store['id']) === null) {
            return $this->notYours();
        }

        [$fields, $error] = $this->readBanner($request, (int) $store['id']);

        if ($error !== null) {
            return $this->page($store, $error);
        }

        $this->banners->update($id, $fields, (int) $store['id']);
        $this->session->flash('reseller_notice', 'Banner updated.');

        return Response::redirect(self::BASE);
    }

    public function pauseBanner(Request $request, array $params): Response
    {
        return $this->setBannerStatus($params, 'paused', 'Banner paused — it is hidden from your storefront.');
    }

    public function resumeBanner(Request $request, array $params): Response
    {
        return $this->setBannerStatus($params, 'active', 'Banner resumed — it is live on your storefront.');
    }

    public function deleteBanner(Request $request, array $params): Response
    {
        $store = $this->storeOrRedirect();

        if ($store instanceof Response) {
            return $store;
        }

        if (!$this->banners->delete((int) ($params['id'] ?? 0), (int) $store['id'])) {
            return $this->notYours();
        }

        $this->session->flash('reseller_notice', 'Banner deleted.');

        return Response::redirect(self::BASE);
    }

    // ----------------------------------------------------------------- helpers

    /** @param array<string, mixed> $params */
    private function setBannerStatus(array $params, string $status, string $notice): Response
    {
        $store = $this->storeOrRedirect();

        if ($store instanceof Response) {
            return $store;
        }

        $id = (int) ($params['id'] ?? 0);

        // The scoped lookup is the ownership check; setStatus is scoped too, so
        // even a race cannot touch another site's row.
        if ($this->banners->findScoped($id, (int) $store['id']) === null) {
            return $this->notYours();
        }

        $this->banners->setStatus($id, $status, (int) $store['id']);
        $this->session->flash('reseller_notice', $notice);

        return Response::redirect(self::BASE);
    }

    /** @return array{0: array<string, mixed>, 1: string|null} */
    private function readBanner(Request $request, int $storeId): array
    {
        return PromoBannerInput::read(
            $request,
            $this->promotions,
            $storeId,
            'create it under "Your promo codes" on this page first.'
        );
    }

    /**
     * Deliberately the same answer for "does not exist" and "belongs to someone
     * else", so the page cannot be used to probe for other stores' ids.
     */
    private function notYours(): Response
    {
        $this->session->flash('reseller_error', 'That item was not found in your store.');

        return Response::redirect(self::BASE);
    }

    /** @return array<string, mixed>|Response */
    private function storeOrRedirect(): array|Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $store = $this->stores->forClient((int) $client['id']);

        if ($store === null) {
            $this->session->flash('reseller_error', 'Open your store first — promotions run on your storefront.');

            return Response::redirect('/client/reseller/store');
        }

        return $store;
    }

    private function date(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }

    /** @param array<string, mixed> $store */
    private function page(array $store, ?string $formError): Response
    {
        $storeId = (int) $store['id'];

        $content = $this->view->render('reseller.client-promotions', [
            'store' => $store,
            'promotions' => $this->promotions->all($storeId),
            'banners' => $this->banners->all($storeId),
            'templates' => PromoBannerTemplates::TEMPLATES,
            'pages' => PromoBannerPages::PAGES,
            'formError' => $formError,
            'notice' => $this->session->pullFlash('reseller_notice'),
            'error' => $this->session->pullFlash('reseller_error'),
        ]);

        return Response::html($this->view->render('layouts.client', [
            'title' => 'Store promotions',
            'content' => $content,
        ]));
    }
}
