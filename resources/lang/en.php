<?php

declare(strict_types=1);

// Storefront + common-chrome strings (blueprint §5 localization). Scope
// note: this covers the public storefront/cart/checkout and shared
// header/footer chrome — not the entire admin/client surface. See
// LocalizationService's docblock and the R11 status note in the blueprint.

return [
    'nav.store' => 'Store',
    'nav.cart' => 'View Cart',
    'nav.login' => 'Log In',
    'nav.register' => 'Register',
    'nav.logout' => 'Log Out',
    'footer.rights' => 'All rights reserved.',

    'store.title' => 'Store',
    'store.view' => 'View',
    'store.no_products' => 'No products available yet.',
    'store.no_products_in_group' => 'No products in this group yet.',

    'product.back_to_store' => 'Back to store',
    'product.billing_cycle' => 'Billing Cycle',
    'product.quantity' => 'Quantity',
    'product.setup' => 'setup',
    'product.add_to_cart' => 'Add to Cart',
    'product.not_available' => "This product isn't currently available for order.",

    'cart.title' => 'Your Cart',
    'cart.continue_shopping' => 'Continue shopping',
    'cart.product' => 'Product',
    'cart.cycle' => 'Cycle',
    'cart.qty' => 'Qty',
    'cart.total' => 'Total',
    'cart.remove' => 'Remove',
    'cart.empty' => 'Your cart is empty.',
    'cart.out_of_stock' => 'Out of stock',
    'cart.subtotal' => 'Subtotal',
    'cart.setup_fees' => 'Setup Fees',
    'cart.place_order' => 'Place Order',
    'cart.login_prompt' => 'Log in or create an account to check out.',
    'cart.upsell_title' => 'You might also like',
];
