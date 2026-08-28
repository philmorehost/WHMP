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

- `php bin/cron.php` — the single system cron entry point. Point one real OS cron job at this, e.g. `* * * * * php /path/to/bin/cron.php >> storage/cron.log 2>&1`. It runs every registered job (recurring billing, dunning, domain renewal/sync, ticket escalation/auto-close, mail piping, daily backup, renewal reminders, system integrity check) — each on its own `frequencyMinutes()` schedule, skipped if not yet due.
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
| `DEEPSEEK_API_KEY` | Powers the AI features (ticket reply suggestions, fraud triage, Ask AI, AI-assisted KB search). All of them fail open — a missing key or API error never blocks the underlying flow, it just skips the AI step. |

## Directory map

- `core/` — all application code, namespaced `CodeVault\*`, one subdirectory per subsystem (`Billing`, `Clients`, `Support`, `Domains`, `Notifications`, `Marketing`, `Theme`, `Backup`, `Localization`, ...).
- `routes/*.php` — one file per feature area, registered in `public/index.php`'s `loadRoutes()` call.
- `resources/views/` — plain-PHP templates, mirroring the `core/` structure.
- `resources/lang/{code}.php` — localization string catalogs (storefront + shared chrome only — see docs/ADMIN_GUIDE.md).
- `database/migrations/` — plain PHP arrays (`return ['up' => [...]]`), run in filename order by `bin/migrate.php`.
- `docs/ADMIN_GUIDE.md` — day-to-day operations guide for whoever runs the admin panel.

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
