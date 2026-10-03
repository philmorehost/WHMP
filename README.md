# CodeVault

A full WHMCS-parity billing/hosting-automation platform built from scratch in vanilla PHP — no framework. See [`CodeVault_WHMCS_Parity_Build_Blueprint.md`](CodeVault_WHMCS_Parity_Build_Blueprint.md) for the original design/roadmap this was built against.

## Stack

- PHP 8.2/8.3, PDO (MySQL/MariaDB), no framework — custom Container/Router/Kernel
- MariaDB, Redis (sessions/queue/cache — gracefully falls back to file/sync/in-memory when unavailable)
- Plain-PHP views (`resources/views/*.php`), hand-rolled design system (`public/assets/css`)
- PSR-4 autoloading, one class per file, PHPUnit for tests

## Quick start

```bash
composer install
cp .env.example .env          # then fill in DB_* and (optionally) REDIS_*/DEEPSEEK_API_KEY
php bin/migrate.php           # runs every migration in database/migrations, in order
php -S 127.0.0.1:8000 -t public public/index.php
```

Then open `http://127.0.0.1:8000` — you'll land on the 4-stage web installer (`/install`) until `.installed.lock` exists, which creates the first admin account and marks the install complete.

**Important — `php -S` gotcha:** you must pass `public/index.php` as the explicit router script (as above), not just `-t public`. Without it, `/sitemap.xml`, `/robots.txt`, and other dynamic-but-extension-having routes 404 before reaching the app. With it, `public/index.php` has a `PHP_SAPI === 'cli-server'` guard that lets real static files (CSS/JS) pass through — this is a no-op under a real web server (Apache/nginx/PHP-FPM).

## Running tests

```bash
vendor/bin/phpunit --no-coverage
```

Tests run against a real MariaDB database (`codevault_test` by default — see `tests/Support/DatabaseTestCase.php`), not mocks; each test runs the full migration set against a throwaway schema. **Don't run the suite from two terminals at once** — both processes share the same test database and will race on the migrations table.

## Background jobs

- `php bin/cron.php` — the single system cron entry point. Point one real OS cron job at this, e.g. `* * * * * php /path/to/bin/cron.php >> storage/cron.log 2>&1`. It runs every registered job (recurring billing, dunning, domain renewal/sync, ticket escalation/auto-close, mail piping, daily backup, renewal reminders, system integrity check, weekly AI system-health report) — each on its own `frequencyMinutes()` schedule, skipped if not yet due.
- **Weekly AI system-health report** (`AiSystemHealthJob`) — every 7 days it collects cron-job failures + the PHP error-log tail (`storage/cache/php-error.log`) from the last week, has the AI (DeepSeek — `DEEPSEEK_API_KEY`) analyse them and propose an implementation plan, and emails the admin (`ai_system_report` template). Fails open: the raw error log is emailed even when the AI key is missing or the call errors.
- `php bin/queue-worker.php` — processes the async queue when `QUEUE_DRIVER=redis`. **Run one supervised process per queue you use** (a worker polls a single queue):
  - `php bin/queue-worker.php default` — drains the order-acceptance queue (`AcceptOrderJob`). **This is what registers domains at the registrar and provisions services when an admin accepts an order.** If it isn't running, accepted orders sit in Redis and domains never reach the registrar.
  - `php bin/queue-worker.php email` — sends outbound email.
  
  Not needed when running on the `sync` fallback (jobs run inline). If you rely on the cron instead of a worker, set `QUEUE_CRON_DRAIN=1` in `.env` to drain up to 25 `default`-queue jobs per cron tick — do **not** enable it when a dedicated worker is also running (a job could be processed twice).

## Environment variables (`.env`)

| Key | Purpose |
|---|---|
| `APP_ENV` | `local` shows PHP errors inline; anything else hides them (logged to `storage/cache/php-error.log` instead) — **always set this to something other than `local` in production.** |
| `APP_URL` | Used for canonical URLs (SEO) and to detect HTTPS for secure cookies — set it to your real public URL. |
| `DB_*` | MariaDB/MySQL connection. |
| `REDIS_*` | Optional — sessions/queue/cache silently fall back to file/sync/in-memory if Redis is unreachable or `ext-redis` isn't loaded. |
| `SESSION_DRIVER`, `QUEUE_DRIVER`, `CACHE_DRIVER` | `redis` or anything else (falls back). |
| `QUEUE_CRON_DRAIN` | `1` = cron also drains the `default` (order-acceptance) queue as a fallback when no dedicated worker runs. Keep unset/`0` if a `queue-worker.php` process is running. |
| `PROVIDER_HTTP_PROXY` | Optional. Sends InterServer and Nocix API calls through a proxy, for when their Cloudflare firewall blocks this server's IP. Examples: `http://user:pass@203.0.113.5:3128`, `socks5h://203.0.113.5:1080`. Other traffic is not affected. |
| `PROVIDER_HTTP_USER_AGENT` | Optional. The User-Agent sent to InterServer and Nocix (default `WHMP-CodeVault/1.0`). Set it if the provider's support asks for a specific value. |
| `PROVIDER_FORCE_IPV4` | Optional. `1` makes InterServer and Nocix calls use IPv4 only (some Cloudflare rules distrust a host's IPv6 range). |
| `DEEPSEEK_API_KEY` | Powers the AI features (ticket reply suggestions, fraud triage, Ask AI, AI-assisted KB search). All of them fail open — a missing key or API error never blocks the underlying flow, it just skips the AI step. |

## Directory map

- `core/` — all application code, namespaced `CodeVault\*`, one subdirectory per subsystem (`Billing`, `Clients`, `Support`, `Domains`, `Notifications`, `Marketing`, `Theme`, `Backup`, `Localization`, ...).
- `routes/*.php` — one file per feature area, registered in `public/index.php`'s `loadRoutes()` call.
- `resources/views/` — plain-PHP templates, mirroring the `core/` structure.
- `resources/lang/{code}.php` — localization string catalogs (storefront + shared chrome only — see docs/ADMIN_GUIDE.md).
- `database/migrations/` — plain PHP arrays (`return ['up' => [...]]`), run in filename order by `bin/migrate.php`.
- `database/schema.php` — **generated** snapshot of the complete schema the migrations build. Every live site checks itself against it and adds whatever it is missing (see *Database schema on live sites* below). Never edit it by hand.
- `docs/ADMIN_GUIDE.md` — day-to-day operations guide for whoever runs the admin panel.

## Database schema on live sites

Upload the new code and you're done — no SSH, no button:

1. On the first request after an upload, every pending migration in `database/migrations/` runs (`Migrator::run`). A migration that fails is logged and retried on the next request; it does not block the ones after it.
2. Then `SchemaReconciler` compares the live database with `database/schema.php` and **adds** anything missing: whole tables (with their foreign keys), columns (in their original position), indexes, and missing ENUM values. This covers what migrations alone cannot — a migration edited after a site had already run it never runs again on that site. It runs once per upload (when `schema.php` changes) and retries hourly if a repair failed.
3. It only ever adds. It never drops, renames or narrows anything. A few things are only *reported*, because changing them could break existing data: a foreign key missing on an existing table, an ENUM a site customised, a missing primary key. They appear on the **System Diagnostics** addon page (the *Database migrations* card), along with the last automatic check and any failure.

`php bin/migrate.php` does the same from a terminal, and `php bin/migrate.php --check` lists what is missing without changing anything.

**When you add or change a migration, regenerate the snapshot and commit it with the migration:**

```bash
php bin/build-schema.php          # rewrites database/schema.php
php bin/build-schema.php --check  # exit 1 if it is out of date (also enforced by SchemaSnapshotTest)
```

The snapshot is built by replaying every migration against an in-memory model of MySQL (`core/Database/Schema/`), so building it needs no database. Never fix a live schema by editing a migration that has already shipped: add a new migration, guarded with an INFORMATION_SCHEMA check, and rebuild the snapshot.

## Reseller customers

A reseller manages its own store's customers from **Customers** in the reseller area (`/client/reseller/clients`):

- **List and search** every customer of the store, with active/suspended services, domains and unpaid invoices per customer.
- **Customer page:** edit contact details (not the login email), send a password-reset email, suspend / unsuspend / terminate services, turn domain auto-renew on or off, lock or unlock domains, and change nameservers. Invoices and tickets are listed; invoices are read-only because payments are collected by the platform.
- **Log in as customer** opens the store's own website (subdomain or verified custom domain) in a new tab, signed in to that account, with a banner and a *Return to your reseller area* link. While signed in this way a reseller cannot change the customer's password, PIN, 2FA, security question, login email or saved cards, request data export/erasure, or open the customer's own reseller area (`ClientImpersonation::restrictedForReseller`).
- **Suspensions:** a store can lift only a suspension it made (`services.suspended_by_reseller_id`). Suspensions made by the platform (overdue invoice, abuse, by an admin) need support. A payment does not lift a store's hold while the customer still belongs to that store.
- Every read and write is scoped to the store inside the SQL (`ResellerClientDirectory`); a suspended store sees its customers but cannot change them. Everything is written to the activity log as `reseller.*`.

### Strict reseller isolation

Each reseller is a separate tenant, identified everywhere by its **Reseller ID** (`resellers.id`); every account has a **User ID** (`clients.id`). Both are shown on the admin's reseller list, every admin reseller page, the reseller area and the customer lists.

- **Registration:** an account created on a reseller's website (subdomain or verified custom domain) belongs to that reseller from the `INSERT` that creates it (`clients.reseller_id`). Accounts created on the main site have no reseller. A pending registration is discarded if it is finished on a different site. Google sign-in is turned off on reseller sites, because its redirect URL is the main site's.
- **Sign-in:** a reseller's customers can sign in only on that reseller's site. Main-site accounts cannot sign in on a reseller's site; the only exception is the reseller themselves on their own store. A store's owner is never claimed as one of their own store's customers.
- **Admin panel:** the general Clients, Services, Domains, Invoices, Orders and Tickets lists, the client counts, pickers, exports and mass mail all cover **main-site customers only**. Any `/admin/clients/{id}` URL for a reseller's customer redirects to that reseller's page (`StoreCustomerAdminRedirect`).
- **Managing a reseller's customers as super admin:** open **Resellers → (store) → Customers** (`/admin/resellers/{owner user ID}/customers`). You get the same customer list and customer pages as the reseller, with the same actions, recorded in the activity log as yours. Unlike the reseller, you can still act while the store is suspended, and you can lift any suspension. Or use **Log in to reseller account** to work in the reseller's own control panel.
- **Orders** placed by a reseller's customers are still fulfilled by the platform. They are listed and accepted from the reseller's Customers page. The main Orders page shows a notice with the number of pending orders for each reseller.
- **Moving a customer** between the main site and a reseller, or between resellers, is done with **Move provider** (`/admin/resellers/migrations`, super admin). Accounts registered on a reseller's site before this fix were recorded as main-site customers. Move them to the right reseller this way; until they are moved, they cannot sign in on the reseller's site.

Login tickets (`client_impersonation_tokens`) are stored only as SHA-256 hashes, can be used once, and only work on the site they name while the customer still belongs to it.

### Sub-resellers (two tiers, never three)

A reseller's customer may open a reseller account of their own and become a **sub-reseller**. The chain stops there (`ResellerEligibility`, `MAX_TIER = 2`):

| Who | Can resell? | Buys at |
|---|---|---|
| Main-site customer | Yes (partner reseller, tier 1) | Our list price less the admin's reseller discount |
| Customer of a partner reseller | Yes (sub-reseller, tier 2) | **The partner's own retail prices**, including any prices the partner set by hand |
| Customer of a sub-reseller | **No.** The Reseller Area link is hidden on their dashboard, and every `/client/reseller…` URL returns 403 (Kernel guard). | n/a |

- **The first reseller keeps its profit.** A sub-reseller's cost for an item is what the upline's customers would pay for it. The sub-reseller's markup and hand-set prices go on top of that, and it cannot set a price below that cost. Setup fees, configurable options and domains all work the same way (`ResellerRetailPricing`).
- **Money.** Each order records `orders.upline_reseller_id` and `upline_cost_total` (migration 0211). When the invoice is paid:
  - the sub-reseller is billed `cost_total` (the upline's prices);
  - the upline is credited `upline_margin = cost_total − upline_cost_total` on its account, held for the usual holding period.

  A refund posts a proportional `upline_margin_reversal`.
- **Where it shows.**
  - The sub-reseller's overview, prices page and `/api/reseller/pricing` show the upline's prices instead of our discount.
  - The admin's reseller list has a **Tier** column, and the store page explains whom a sub-reseller buys from.
  - A store whose upline is itself a sub-reseller (possible only for stores opened before the cap) is flagged **Legacy 3rd tier**.

### Platform address (free store subdomains)

Each reseller store has a short name (its slug). Its free web address is `{slug}.{platform address domain}`. The super admin picks that domain under **Admin → Resellers → Platform address**.

- **Pick a separate domain, ideally a neutral one** (for example `resellerhub.com`). It should not be a subdomain of the client area. If the client area runs on `client.example.com` and stores were built under it, addresses become `acme.client.example.com`. That is long, shows your brand, and a `*.example.com` wildcard certificate does not cover it.
- **Not set means not served.** With no domain configured, a store's slug is just its name. No `slug.anything` address answers, and the store is reachable only on its own custom domain once that domain is verified. The reseller's store page and the admin pages say so.
- **Set means live at once.** `acme.resellerhub.com` serves the store `acme`. The bare domain, its `www`, and deeper names (`x.acme.resellerhub.com`) are never stores. Verified custom domains keep working either way, and become the store's main address.
- **Server set-up for the domain** (listed in the admin card, which also has a **Check DNS** button):
  1. A wildcard `A` record `*.domain` pointing at this server, or a `CNAME` to the platform host.
  2. The domain added to the web server with a wildcard subdomain `*`, both on the platform's document root.
  3. A wildcard SSL certificate for `*.domain`, from AutoSSL or a DNS-01 certificate.
- **No store can claim the domain** or any name under it as its custom domain.
- **Verification is unchanged.** Custom-domain checks still accept a CNAME to the platform host (APP_URL).
- **For developers:** the setting is stored under the `reseller.platform_domain` key and read by `ResellerPlatformAddress`. `ResellerStoreLocator::storeDomain()`, `publicHostFor()` and `platformAddressFor()` are the API. A locator built by hand without the address service keeps the old behaviour (subdomains of the APP_URL host), so hand-built test fixtures need no change.

### Reseller panel design

The reseller control panel (`/client/reseller…`) and the admin's reseller pages (`/admin/resellers…`) share a modern skin in `public/assets/css/reseller.css`.

- **Scoping:** the skin applies only under `.rs-panel` / `.rs-admin`, which the layouts add to `<main>` on those paths. The rest of the app is unchanged.
- **What it covers:** a hero header, stat cards with icons, pill navigation with inline SVG icons, quick-action tiles, and restyled `cv-*` buttons, cards, tables, inputs, alerts and badges. Dark mode is supported.
- **Colours:** everything uses theme tokens, so a tenant's brand colour carries through.
- **Navigation:** the admin's programme-wide reseller pages share one nav (`partials/reseller-admin-nav.php`).

## InterServer VPS: client self-service

Clients control their InterServer VPS from their service page: start, restart and stop;
VNC console; reverse DNS; on-demand snapshots; OS reinstall; and restore from a snapshot.
All of these go through the InterServer API (`https://my.interserver.net/apiv2`,
`X-API-KEY`). If a call can't be made, the client's request becomes a support ticket.

VPSs that are ordered and set up by hand also work. Each one just needs to be tied to its
machine:

1. **Admin → Servers:** add a server with module **InterServer VPS** and your InterServer
   API key. Optionally, save the **InterServer account password** too. InterServer checks
   it again on every OS reinstall and backup restore. It is stored encrypted with
   AES-256-GCM, using a key derived from `APP_KEY`. Without it, those two actions become
   tickets and everything else still works.
2. **Admin → the client's service:** set **Assigned Server** to that server and save.
3. In the **InterServer VPS link** card on the same page, pick the VPS from the
   account's list and click **Link this VPS**. This saves the VPS's InterServer id in
   `services.remote_id` and fills in an empty hostname or IP. A WHMP username is not
   needed.

An unlinked service is matched automatically by its hostname, then its IP, then its
username. Linking is what makes the match permanent.

The VNC console first allows the client's own public IPv4 (InterServer accepts VNC from
one allowed address) and then shows the host and port. If WHMP is behind a proxy, make
sure `Request::ip()` sees the real client address.

**Seeing the provider's real answer:** on a server's edit page, Test Connection shows an
**API response details** panel. It contains the exact request, the HTTP status, the
response headers and body, the IP connected to, and any cURL error. API keys, passwords
and cookies are hidden. **Copy report** and **Download .txt** let you send it to the
provider's support. The servers list links to this panel.

**If Test Connection says Cloudflare blocked the request:** InterServer's API is behind
Cloudflare, which sometimes blocks web-hosting IP addresses. The message includes the IP
Cloudflare saw and a Ray ID. Either ask InterServer support to allow API access from that
IP (quote the Ray ID), or set `PROVIDER_HTTP_PROXY` in `.env` so these calls leave from a
server with a clean IP, such as one of your own VPSs. Try `PROVIDER_FORCE_IPV4=1` first if
your server has IPv6.

**Lifecycle:** once a service is tied to a VPS, suspending it in WHMP (manually or for an
overdue invoice) stops the VPS. Unsuspending starts it again. **Terminating it cancels
the VPS on the InterServer account** (`DELETE /vps/{id}`).

## Nocix dedicated servers: restart and OS reload

Clients can restart a Nocix dedicated server and reload its OS from their service page,
through the Nocix client API (`https://my.nocix.net/api/{call}/`, HTTP Basic
`api_username:api_token`; docs at https://my.nocix.net/apidoc/, BETA). Wholesale
accounts use `my.wholesaleinternet.net`, picked when the server record's hostname names it.
The old `manage.nocix.net` host does not exist, so earlier versions could never reach Nocix.

| Client action | Nocix call |
| --- | --- |
| Restart | `reboot-server?service_id=` |
| OS list for the reload form | `os-list?service_id=` |
| Reload OS (typed REINSTALL confirmation) | `os-reload?service_id=&os=<name from os-list>` |
| Reload progress (Pending / Completed) | `reloadstatus?service=` |
| Show login details, only after a completed reload | `get-server-credentials?service_id=` |

Nocix takes no root password on reload. It sets the login itself and emails it to the
account holder, so once the reload has completed the client can reveal the login Nocix
stores. It is shown once, sent with `Cache-Control: no-store`, and never logged.

**Setup for a server bought by hand:** add a server with module **Nocix Dedicated Server**
and your Nocix API username and token. Set it as the service's **Assigned Server**, then
use the **Nocix server link** card on the service page to pick the Nocix service. Without
a link, WHMP uses a numeric WHMP username as the Nocix service id (the older convention),
then the Nocix service whose IP block contains the service's IP.
`php bin/check-nocix-config.php` tests each Nocix server record.

**Lifecycle:** once a service is tied to a Nocix server, suspending it (including for an
overdue invoice) **disconnects the server from the network**, and unsuspending reconnects
it. Nocix's API cannot cancel a server, so terminate it in the Nocix portal.

## Known environment-dependent gaps

Some features degrade gracefully but aren't fully live-verifiable without infrastructure this dev environment doesn't have:

- **Redis** (`ext-redis`) isn't loaded here, so `RedisCache`/`RedisSessionHandler`/Redis queue are unit-tested but not live-exercised — the app runs correctly on their file/array/sync fallbacks.
- **IMAP** (`ext-imap`) isn't loaded here, so the mail-piping *transport* is spec-correct but unverified live; the ticket-matching logic it feeds is fully tested.

## Deployment

The app's only web-reachable directory is meant to be `public/` — everything else (`core/`, `database/`, `storage/`, `vendor/`, `composer.json`/`.lock`, `.env`) must never be served directly. Two supported setups:

**1. Document root = `public/` (recommended).** Point your vhost/Apache `DocumentRoot` (or nginx `root`) straight at the `public/` folder. `public/.htaccess` (Apache) handles the front-controller rewrite — real files/assets under `public/` are served directly, everything else routes to `public/index.php`. This is the cleanest setup: nothing outside `public/` is even reachable in principle.

**2. Document root = repo root (common on shared/cPanel hosting)**, where the hosting account's document root can't be pointed at a subdirectory. This is transparently handled by the root-level [`.htaccess`](.htaccess) and [`index.php`](index.php):
- Root `.htaccess` sets `Options -Indexes` (no directory listings even if rewriting is somehow inactive) and rewrites every request to the same path under `public/` — so `/storage/...`, `/database/...`, `/vendor/...`, `/composer.json`, etc. all resolve to a nonexistent `public/...` path and 404 naturally, while `public/.htaccess`'s own rewrite then takes over exactly as in setup 1.
- Root `index.php` is a one-line fallback (`require __DIR__ . '/public/index.php';`) that keeps the homepage safe and functional even on the rare host where `mod_rewrite`/`AllowOverride` isn't available at all — Apache's `DirectoryIndex` still finds it for a bare `/` request instead of falling through to a directory listing.

Either way, requests are gated by `Kernel::needsInstallRedirect()`: until `.installed.lock` exists, every request redirects to the 4-stage installer (`/install`); once installed, `/` serves the real landing page.

If your host uses nginx instead of Apache, there is no `.htaccess` equivalent — you must set `root` to `public/` directly in the server block (setup 1); nginx has no directory-root fallback mechanism analogous to `DirectoryIndex`, so setup 2 doesn't apply there.
