<?php

declare(strict_types=1);

namespace CodeVault\Marketing;

use CodeVault\Cart\Cart;
use CodeVault\Reseller\CurrentReseller;
use CodeVault\Request;
use CodeVault\Response;

/**
 * The unauthenticated "Apply Now" click target for a promo banner — anyone
 * browsing anonymously can hit this, so it does nothing but record a click
 * and hand the code to Cart, the same session store CheckoutController's own
 * coupon field writes to. No new coupon-application logic exists here:
 * CartService::priced() re-validates the code fresh on every render exactly
 * as it already does for a manually-typed code (see PromotionService).
 *
 * The banner is looked up IN THE SCOPE OF THE SITE BEING SERVED: a platform
 * banner id posted to a storefront (or another store's id) is simply not found,
 * so one site's banner can never drop its code into another site's cart.
 */
final class PromoBannerPublicController
{
    public function __construct(
        private readonly PromoBannerRepository $banners,
        private readonly Cart $cart,
        private readonly ?CurrentReseller $currentReseller = null
    ) {
    }

    public function apply(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $banner = $this->banners->findScoped($id, $this->currentReseller?->id());

        if ($banner !== null) {
            $this->banners->incrementClicks($id);
            $this->cart->setPromoCode((string) $banner['coupon_code']);
        }

        return Response::redirect('/cart');
    }
}
