# Storefront (`/store`) — design and white-label rules

`/store` is the **front page of every reseller's store**, served on the reseller's
own domain. It is the single most visible surface of a white-label install, which
makes it the easiest place to leak the platform's identity without noticing.

Route: `routes/cart.php` → `GET /store` → `CheckoutController::store()`.
View: `resources/views/cart/store.php`.
Stylesheet: `public/assets/css/store.css`.

## The two rules

### 1. Never hard-code a brand colour in the storefront

The active store's accent colour reaches the page as CSS custom properties, set
per tenant by `resources/views/layouts/client.php`:

```html
<style>:root {
  --cv-color-brand-500: <?= e($theme['primaryColor']) ?>;
  --cv-color-brand-600: <?= e($theme['primaryColorDark']) ?>;
}</style>
```

**Only those two steps are overridden.** `--cv-color-brand-50`, `-100`, `-200`,
`-300`, `-400`, `-700` and `-900` still describe the *platform's* palette, so
using one for a tinted surface (a pill, a hover wash, a border) silently shows the
platform's colour on a reseller's store — while looking like an ordinary bit of
polish.

To tint with the tenant's own accent, mix against step 500:

```css
background: color-mix(in srgb, var(--cv-color-brand-500) 12%, var(--cv-bg-surface));
```

The storefront this replaced carried **38 literal hex values** (`#1a1a2e`,
`#f59e0b`, …) and used the token twice, so a reseller who chose red still got the
platform's navy-and-gold. The view now contains no colour literals at all; the
only literals in the stylesheet are neutrals (white, near-black) used for contrast
against the brand gradient.

### 2. Show nothing that belongs to us

A storefront must not carry the platform's marketing copy, the platform's brand
name, or the name of the platform's upstream vendors.

Removed when this page was rebuilt:

- A hard-coded headline, *"Premium Hosting Solutions"*, and its subtitle — the
  platform's own marketing, shown on every reseller's store.
- A branch on the literal string `'ResellerClub Email Hosting'` in the icon
  logic — the platform's upstream email vendor, named on a reseller's storefront.
- **Invented product specifications.** Feature bullets were chosen by
  substring-matching the product *name* (`google` → *"30GB Secure Cloud Storage"*,
  `titan` → *"Read Receipts & Templates"*), and a badge was invented the same way
  (`"Official Google"`, `"Premium App"`). Any product a reseller later named
  `…google…` inherited those claims. Presenting made-up specifications to a buyer
  is mis-selling, so the cards now show only the product's **own description** and
  its **real price**.

## Behaviour changes

| Before | After | Why |
| --- | --- | --- |
| Hero CTA linked to `/client/services` | Primary CTA scrolls to the plans; secondary links to `/client/login` | `/client/services` needs a session, so a first-time visitor clicking the storefront's main button hit a login wall instead of the plans |
| A group with no plans rendered a heading plus "no plans" notice | Such a group is **skipped entirely**; the store-level empty state shows when nothing is renderable | A customer-facing catalogue should not advertise an empty category. It also keeps the hero's quick links honest, because the same list drives both |
| One media query for 449 lines of layout | Breakpoints at 900 / 640 / 400 / 1400 px, plus `prefers-reduced-motion` and a dark-scheme tweak | It was not genuinely responsive |
| CSS inline in the view (≈16 KB per response) | `public/assets/css/store.css`, cacheable | Avoids repeating the stylesheet in every response body |

The `store.no_products_in_group` string is no longer used by this page (kept in the
catalogue; harmless). No new translation keys were introduced, because
`Translation::get()` returns **the key itself** when a string is missing — a typo
would print `store.headline` on the storefront.

## Tests

`tests/Unit/StorefrontLayoutTest.php` — no database, so it cannot collide with the
shared `codevault_test` database. It renders the view for each shape (a group with
plans, a product with no description, no catalogue at all, a group with no plans)
and reads the stylesheet as text.

Two of its checks are worth knowing about:

- **`test_the_stylesheet_only_uses_brand_steps_a_tenant_can_override`** encodes rule
  1. It has been seen to fail — deliberately adding one real
  `var(--cv-color-brand-100)` turns it red with the reason.
- **`test_the_storefront_shows_no_platform_or_upstream_vendor_branding`** asserts on
  the **rendered HTML**, not the view's source. The source legitimately names the
  removed vendor in a comment explaining why it is gone; a check that greps the file
  flags its own explanation. For the same reason, the CSS scans strip comments
  first, and the colour scan uses a negative lookbehind so the numeric HTML entities
  `&#8595;` / `&#128230;` are not mistaken for hex colours.

## Follow-ups

- The hero headline and subtitle are still fixed English, so a reseller cannot
  change their own front-page copy. Making them editable needs a store settings
  field (and a migration), which is the natural next step.
- Per-store theming covers brand name, logo, favicon and accent colour. A store
  cannot yet choose a different hero image or layout.
