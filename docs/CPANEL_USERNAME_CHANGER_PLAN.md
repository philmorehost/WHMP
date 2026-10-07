# cPanel Username Changer — implementation plan

Status: **implemented** · WHMP add-on module `cpanel-username-changer` · Migration `0214`

> **Revision 2** (after review): the precheck must feel instant (new §15), and the super admin can switch a
> **fee ON/OFF**. When it is on, resellers can resell the change above the admin's fee and keep the margin
> (new §16). Everything else in this plan stands.

Inspired by *cPanel Username Changer for WHMCS* (TIVRO, WHMCS Marketplace #9086), re-designed for WHMP:

- **free by default**: no licence check, and no fee unless the super admin switches payment on (§16);
- **for everyone** once the super admin activates it, including **customers of reseller stores**;
- **white-label** on stores;
- built on WHMP's own provisioning, mail, cron, hooks and reseller isolation, so it adds **no new
  server-communication stack**.

---

## 1. What we are building (feature map)

Each WHMCS feature is listed with what WHMP does instead. "**+**" marks an enhancement that the original
does not have.

| Area | WHMCS add-on | WHMP version |
|---|---|---|
| Request flow | Client picks a new username on the service page, confirms the impact and confirms by email; can track and cancel the request | Same. **+** the client can confirm with their **Security PIN** instead of an email link (instant). Email remains available and is the default |
| Live check | Availability is checked as the client types; alternatives are suggested | Same, built to feel **instant** (§15): rules judged in the browser on every keystroke, a 120 ms debounced, cancellable, cached server check that never calls WHM, and suggestions pre-checked and pre-cached |
| Validation | Length limits; cPanel format; reserved words; checked across WHMCS and against WHM; checked again before running | Same, plus every cPanel rule from the WHM docs (see §5). **+** the "first 8 characters must be unique" rule is applied automatically on MySQL servers (it is not needed on MariaDB) |
| Policy | Off by default; global settings with per-product and per-client overrides; number of changes allowed; unlimited option; cooldown; which statuses may request; cPanel only | Same. **+** a **per-store policy**: a reseller can *tighten*, never loosen, what the super admin allows |
| Approval | Optional admin approval; decline with a reason; admin changes skip every limit | Same. **+** **reseller approval**: a store owner can approve or decline their own customers' requests from the reseller panel |
| Fees | Invoices, taxes, release on payment | **An ON/OFF switch** (off by default). When on: one admin fee (with per-product overrides), invoiced only after confirmation and any approval, and the rename runs once the invoice is paid. **+** resellers set their own price at or above their cost and the margin is credited to their balance; sub-resellers too, with the upline earning its share. Refunds reverse the credits (§16) |
| Execution | WHM API 1; immediate or queued via cron; locking; runtime checks; multi-server; detects external changes; retries | Same, using `CpanelProvisioningModule::call()`. **+** if the connection drops, the result is verified (as `createacct` already does). **+** optional **"also rename databases"** (`rename_database_objects`), only if the admin allows it and the client opts in |
| Sync | Updates the stored username; fallback; mismatch detection; admin alert; completion email | Same. **+** a one-click **"Sync from server"** button for a mismatch |
| Admin | Dashboard, queue, filters, inline actions, request detail, manual tool, product policies, client overrides, audit log, pending badge, service-page shortcut | Same, using WHMP's modern admin cards. **+** a **preflight / dry run** on the manual tool |
| Emails | Confirmation, submitted, approved, declined, completed (with what the client must update), failed, staff alerts; editable templates | Same, as rows in `email_templates`, editable in Admin → Email Templates. On stores they are sent **as the store** (`EmailDispatcher::onBehalfOfStore`) |
| Security | CSRF, rate limits, ownership check, event trail, actor tracking, IP logging, single-use links | Same. **+** reseller isolation: a store can only see its own customers' requests |
| Developers | `TivroUsernameChanged` hook | `HookPoints::AFTER_MODULE_CHANGE_USERNAME` (same pattern as `AfterModuleChangeDomain`). **+** Reseller-API endpoints (phase 6, optional) |
| Ops | Automatic migrations, cron, audit retention, branding | Automatic migration on the first page load (as for every WHMP migration), a `CronJob`, a retention setting. Branding follows the site's theme and the store's colours automatically |

---

## 2. Where it lives in WHMP

WHMP already has a close sibling: **`DomainChangerAddon`**. It uses an `AddonModule` as the on/off switch, a
client action on My Services, and WHM `modifyacct` through `ProvisioningService`. The Username Changer
follows the same shape but has its own request lifecycle.

```
core/UsernameChanger/                     (new namespace CodeVault\UsernameChanger)
├── UsernameChangerAddon.php              AddonModule: metadata, activate/deactivate, admin summary
├── UsernamePolicy.php                    pure rules: format, length, reserved words, suggestions
├── PolicyResolver.php                    effective policy = global ⊕ product ⊕ store ⊕ client
├── UsernameAvailability.php              local checks + live WHM verify_new_username + on-demand account sync
├── UsernameChangeRepository.php          requests + events tables
├── UsernameChangeService.php             the state machine (request, confirm, approve, decline, cancel, run)
├── UsernameChangeExecutor.php            claim lock → preflight → WHM rename → verify → sync
├── UsernameChangeNotifier.php            emails (platform or store branding) + staff alerts
├── UsernameChangeJob.php                 Queue Job: runs one approved request
├── UsernameChangeCronJob.php             CronJob: queued runs, expiry, retries, retention
├── ClientUsernameController.php          client endpoints (service page, confirm page)
├── AdminUsernameController.php           admin dashboard, queue, detail, policies, overrides, manual tool
└── ResellerUsernameController.php        reseller panel: their customers' requests + store policy
```

Changes to existing code (small and additive):

| File | Change |
|---|---|
| `core/Provisioning/CpanelProvisioningModule.php` | Add `verifyNewUsername()` (`verify_new_username`), `changeUsername()` (`modifyacct user=…&newuser=…[&rename_database_objects=1]`) and `accountExists()` (`accountsummary`). `call()` and `decode()` are reused |
| `core/Provisioning/ProvisioningService.php` | Add `changeUsername(int $serviceId, string $new, bool $renameDb)`. As in `changeDomain()`, the stored `services.username` is updated **only after** WHM confirms. Fires the new hook |
| `core/Hooks/HookPoints.php` | `AFTER_MODULE_CHANGE_USERNAME = 'AfterModuleChangeUsername'` |
| `core/Kernel.php` | Register the add-on (`domain-changer` is registered the same way) and the controllers' dependencies |
| `bin/cron.php` | `$scheduler->register(… UsernameChangeCronJob::class)` |
| `routes/username-changer.php` (new) and the route list | Client, admin and reseller routes (§7) |
| `resources/views/billing/client-service-show.php` | A compact banner ("cPanel username: **acme1** · Change") that opens the modal. Rendered only when the add-on is active **and** the policy allows it for this service |
| `resources/views/…/admin service page` | "Username: acme1 · History · Change" shortcut |
| `partials/reseller-nav.php` | A "Username requests" item with a pending badge, shown only when the add-on is active |
| `core/Provisioning/ProvisioningService::generateUsername()` | Use `UsernamePolicy` so new accounts follow the same rules (for example, never starting with `test`) |

---

## 3. Data model (migration `0214_cpanel_username_changer`)

Closure migrations using the `INFORMATION_SCHEMA` check (the same style as 0207/0213), with `database/schema.php`
regenerated (`php bin/build-schema.php`). Wide text goes in `TEXT` columns to stay clear of MySQL's row-size limit.

**`username_change_requests`**
```
id                    INT UNSIGNED PK
service_id            INT UNSIGNED NOT NULL         FK services ON DELETE CASCADE
client_id             INT UNSIGNED NOT NULL         owner at request time
reseller_id           INT UNSIGNED NULL             the store the client belongs to (NULL = platform)
server_id             INT UNSIGNED NULL             server at request time
old_username          VARCHAR(16) NOT NULL
new_username          VARCHAR(16) NOT NULL
reason                VARCHAR(500) NULL
rename_db_objects     TINYINT(1) NOT NULL DEFAULT 0
status                VARCHAR(24) NOT NULL          see §4
confirm_method        VARCHAR(8) NULL               'email' | 'pin' | 'admin'
confirm_token_hash    CHAR(64) NULL                 sha256 of the email token (never the token)
confirm_expires_at    DATETIME NULL
confirm_sent_count    TINYINT UNSIGNED NOT NULL DEFAULT 0
confirmed_at          DATETIME NULL
decided_by_type       VARCHAR(10) NULL              'admin' | 'reseller' | 'system'
decided_by_id         INT UNSIGNED NULL
decided_at            DATETIME NULL
decline_reason        VARCHAR(500) NULL
fee_amount            DECIMAL(12,2) NULL            what was invoiced, in the client's currency (§16)
fee_currency_id       INT UNSIGNED NULL
fee_retail            DECIMAL(12,2) NULL            price charged, catalog currency
fee_cost              DECIMAL(12,2) NULL            the store's cost (NULL for platform customers)
fee_upline_cost       DECIMAL(12,2) NULL            the upline's cost, for a sub-reseller's customer
invoice_id            INT UNSIGNED NULL
paid_at / fee_credited_at / fee_reversed_at  DATETIME NULL   (the last two are idempotency claims)
attempts              TINYINT UNSIGNED NOT NULL DEFAULT 0
next_attempt_at       DATETIME NULL
lock_token            CHAR(32) NULL                 execution claim
locked_at             DATETIME NULL
last_error            TEXT NULL
server_response       TEXT NULL                     trimmed WHM reply, for support
sync_state            VARCHAR(12) NULL              'ok' | 'mismatch'
requested_by_type     VARCHAR(10) NOT NULL          'client' | 'admin' | 'reseller'
requested_by_id       INT UNSIGNED NULL
ip                    VARCHAR(45) NULL
created_at / updated_at / completed_at  DATETIME
INDEX (status, next_attempt_at), INDEX (service_id), INDEX (client_id), INDEX (reseller_id, status),
INDEX (new_username, status), INDEX (invoice_id)
```

**`username_change_events`**: the full trail. Columns: id, request_id (FK, cascade), event (e.g.
`requested`, `confirm_sent`, `confirmed`, `approved`, `declined`, `cancelled`, `claimed`, `preflight_failed`,
`renamed`, `synced`, `mismatch`, `failed`, `retried`, `expired`), actor_type, actor_id, ip, detail TEXT,
created_at.

**`username_change_policies`**: overrides only, where NULL means "inherit". Columns: id, scope ENUM('product',
'store','client'), scope_id, enabled TINYINT NULL, max_changes SMALLINT NULL (0 = unlimited), cooldown_days
SMALLINT NULL, approval VARCHAR(10) NULL ('none'|'admin'|'reseller'), allow_db_rename TINYINT NULL,
client_mode VARCHAR(8) NULL ('waive'|'block', client scope only), extra_changes SMALLINT NULL, note
VARCHAR(255) NULL, **fee DECIMAL(12,2) NULL** (product scope: the admin fee for that product; store scope: the
store's resale price), updated_at. UNIQUE (scope, scope_id).

**`username_change_throttle`**: a small rate-limit table: key VARCHAR(120) PK, hits, window_start. It is
needed because the cache may be the per-request `ArrayCache`.

**`username_change_server_accounts`** (server_id, username, prefix8, domain) and **`username_change_servers`**
(server_id, db_engine, db_engine_override, account_count, accounts_synced_at, last_error): a cron-refreshed
copy of every WHM account per server. The instant precheck reads it instead of calling WHM (§15).
Migration 0214 also adds an index on `services.username`.

**Global settings** (`settings` table, prefix `username_changer.`): `enabled_default` (0), `min_length` (5),
`max_length` (16), `allow_leading_digit` (0, which cPanel forbids anyway), `reserved_extra` (list),
`unique_scope` ('platform'|'server'), `max_changes` (1), `cooldown_days` (30), `statuses` ('active'),
`product_types` ('shared,reseller'), `approval` ('none'), `confirm_methods` ('email,pin'),
`confirm_ttl_hours` (48, allowed range 1–720), `execution` ('immediate'|'queued'), `max_attempts` (3),
`allow_db_rename` (0), `require_reason` (0), `retention_days` (365), `stores_allowed` (1),
`store_approval_allowed` (1), `staff_alert_email` (blank = the admin email in the company settings),
`first8_rule` ('auto'|'on'|'off'), `heading`, `accent`, and the payment settings `fee_enabled` (0), `fee` (0.00, catalog
currency) and `store_pricing` (1 = resellers may resell).

The emails are seeded into `email_templates` as editable rows:
`username_change.confirm`, `.submitted`, `.approved`, `.declined`, `.completed`, `.failed`,
`.payment_due`, `.store_approval`, `.staff_new`, `.staff_failed`, `.staff_mismatch`. The cron job re-creates any that an admin deleted.

---

## 4. Request lifecycle (state machine)

```
                 ┌────────────── cancel (client) ───────────────┐
request ──► awaiting_confirmation ──confirm──► pending_approval ──approve──► queued ──claim──► processing
   │            │  (email link / PIN)              │  (admin or store owner)    ▲              │
   │            └─ expire (cron) ─► expired        └─ decline(reason) ─► declined│              ├─► completed (+ synced)
   │                                                                            │              ├─► failed ──retry──┘
   └── admin/manual: skip confirm, approval, limits ─────────────────────────────┘              └─► completed + mismatch
```

- When no approval is required, `pending_approval` is skipped.
- When payment is **on** and the client owes a fee, an approved/confirmed request goes to **`awaiting_payment`**
  with an invoice; paying it moves the request to `queued`. Cancelling the request cancels an unpaid invoice;
  cancelling the invoice cancels the request (cron reconciliation).
- When execution is set to **immediate**, the request is dispatched as a Queue `Job` the moment it reaches
  `queued`, so the browser never waits on WHM. The cron job picks up anything left over.
- **One open request per service.** A new request is refused while another is in a non-final state.
- A pending request **reserves** its `new_username`, so two clients cannot race for the same name.
- **Final states:** completed, declined, cancelled, expired, failed. A failed request goes back to `queued`
  on retry until `max_attempts` is reached.

---

## 5. Username rules (`UsernamePolicy`, pure and fully unit-tested)

The rules come from WHM's `modifyacct` / `newuser` documentation:

1. `^[a-z][a-z0-9]*$`: lowercase letters and digits only, starting with a letter. The modal lower-cases as
   the client types and rejects anything else live.
2. Length between `min_length` and `max_length`, and **16 at most** (cPanel's limit when database prefixes
   are on).
3. It must not start with `test` (a cPanel rule).
4. It must not be a reserved word. The built-in list covers system and service users, for example `root`,
   `admin`, `administrator`, `cpanel`, `whm`, `webmail`, `mysql`, `postgres`, `nobody`, `mail`, `ftp`,
   `www`, `apache`, `nginx`, `named`, `dovecot`, `exim`, `virtfs`, `cpses`, `all`, `support`, `billing`, and
   so on. Admins can add their own words.
5. It must differ from the current username.
6. **WHMP-wide uniqueness:** no other service (on any server, or on the same server, depending on
   `unique_scope`) may have the name, and no open request may have reserved it.
7. **First-8 rule:** on a MySQL server (not MariaDB), the first 8 characters must be unique among that
   server's accounts. The database type is detected once through WHM's MySQL version function and cached
   against the server. An admin can also force this per server (Auto / MySQL / MariaDB), because detection
   can fail on a locked-down token.
8. **WHM check:** `verify_new_username user=<new>` must return `metadata.result = 1`. Its `reason` is shown
   to the client in plain words.
9. **Pre-execution re-check:** all of the above run again just before the rename. In addition,
   `accountsummary user=<old>` must still exist.
   - If the account is missing, we search `listaccts` by the service's domain to find the account's real
     current username.
   - If it was renamed outside WHMP, the stored username is synced, the event is recorded and the request
     is re-validated against the real name.

**Suggestions** (shown when a name is taken) are generated, checked against rules 1–7, and the first 5
are checked against WHM:
- from the domain label (`acmehosting.ng` → `acmehost`, `acmehst`);
- from the client's first and last name;
- by adding 2–3 digits to the requested name.

---

## 6. Policy resolution (`PolicyResolver`)

```
effective = global settings
          ⊕ product override         (super admin, per product)
          ⊕ store override           (reseller, can only make it STRICTER — see below)
          ⊕ client override          (super admin: waive | block | +N extra changes)
```

- **Store tightening.** A store's `max_changes` and `cooldown_days` can only be lower or higher
  respectively; its `enabled` can only switch the feature off; and its `approval` can only add approval
  (`reseller`), never remove an admin approval. This is enforced in code, not only in the form.
- **Client `waive`** lifts limits and cooldown, but not the safety rules (§5).
- **Client `block`** hides the feature for that client.
- **Eligibility** also requires: the service is on a server whose `module_slug = 'cpanel'`; the product
  type is in `product_types`; the service status is in `statuses`; and the service has a username.
- **Counting.** `max_changes` counts **completed** requests on the service. The cooldown runs from the
  last completed change.

---

## 7. Screens and endpoints

**Client** (the service page on the main site *and* on stores; ownership is checked as in
`ClientServiceController::ownedService()`):

| Route | Purpose |
|---|---|
| `GET  /client/services/{id}/username` | Modal data (JSON): current name, rules, remaining changes, cooldown, history |
| `GET  /client/services/{id}/username/check?u=` | Live availability + suggestions (90/min per session; local DB only, see §15) |
| `POST /client/services/{id}/username` | Create the request (CSRF; impact tick-boxes; reason if required; confirmation method `email` or `pin` — a PIN confirms in the same call, 5 tries/hour then email only) |
| `POST /client/services/{id}/username/{rid}/cancel` | Cancel own pending request |
| `POST /client/services/{id}/username/{rid}/resend` | Resend the confirmation email (max 3, at least 5 minutes apart) |
| `GET  /username-change/confirm/{token}` | Standalone confirmation page. Works signed-out; single-use; served on the **store's own host** for store customers |

**The modal UI** uses the premium `sf-*` styles on the storefront and on the premium main site, and `cv-*`
in the classic design. It becomes a **bottom sheet on mobile**. It has three steps:
1. **New name**: live tick or cross, then suggestion chips.
2. **What changes**: the impact list, each item with a tick-box:
   - the cPanel login changes;
   - the home folder moves from `/home/old` to `/home/new`;
   - FTP/SSH logins and cron paths change;
   - database names keep the old prefix unless "rename databases" is chosen, in which case
     `wp-config.php` and similar files must be updated.
3. **Confirm**: email or PIN.

Below the steps it shows the history and status of previous requests.

**Admin** (*Addons → cPanel Username Changer*, using a dedicated controller and gated by
`AddonModuleRepository::isActive()`):

- **Dashboard:** counts per status, today and 7 days, failures and mismatches, recent activity.
- **Queue:** search (client, service, username, domain), status filter, and inline approve / decline (with
  a reason) / resend / retry / cancel.
- **Request detail:** client, store, service, product, server, IP, every timestamp, reason, the WHM error
  and its trimmed response, the event trail, and a "Sync from server" button for a mismatch.
- **Manual change tool:** service ID or search, a new name, live check, **Preflight** (runs every check and
  calls nothing destructive) and **Rename now**. It skips confirmation, approval and limits, but never the
  safety rules.
- **Settings:** the global settings in §3, product policies (a table of cPanel products with inherit/override
  cells), client overrides, the reserved words, retention, and the store controls (allow stores; allow store
  approval).
- **Audit log:** a searchable `username_change_events` list.
- **Pending badge** in the add-on nav, plus the shortcut on the admin service page.

**Reseller panel** (`/client/reseller/username-requests`; shown only when the add-on is active and
`stores_allowed` is on):
- Their **own customers'** requests only. Requests are scoped by `reseller_id`, using the same scoping rule
  as `ResellerClientDirectory`.
- Approve or decline when the effective approval is `reseller`.
- A **store policy** card, limited to making the rules stricter.
- A **Your price** card when payment is on and resale is allowed: cost, price (never below cost) and the live margin.
- Store owners **cannot** run renames directly. That stays with the customer flow or the super admin, to
  keep server-side power with the platform.

---

## 8. Execution (`UsernameChangeExecutor`)

1. **Claim:** `UPDATE … SET status='processing', lock_token=?, locked_at=NOW() WHERE id=? AND status='queued'`.
   It continues only if exactly one row changed. A stale lock older than 30 minutes is released by the cron job.
2. **Preflight:** rules §5 1–9 against the service's **current** server and username.
3. **Rename:** `modifyacct user=<old> newuser=<new>` (plus `rename_database_objects=1` when allowed and
   chosen), with the WHM HTTP timeout set to 180 seconds.
4. **Verify:** whatever the HTTP outcome (including a dropped connection), `accountsummary user=<new>`
   decides whether the rename happened. A bounded poll is used, as `createacct` already does.
5. **Sync:** `ServiceRepository::updateDetails($id, ['username' => $new])` sets `sync_state=ok`. If that
   write fails, a direct `UPDATE` is tried as a fallback. If that also fails, the request is recorded as
   `completed + mismatch` and a staff alert is sent.
6. **After the rename:**
   - fire `AfterModuleChangeUsername` with `{serviceId, oldUsername, newUsername, requestId}`;
   - write `activity_log` `service.username_changed`;
   - send the completion email (which includes what the client must update) and staff notices.
7. **Failure:** record the error and the trimmed response, then set `failed`. If `attempts < max_attempts`
   and the error is transient (could not reach the server, or a timeout), set `next_attempt_at` with
   backoff (5 minutes, then 30 minutes, then 2 hours). The client gets the failure email only when the
   request has finally failed.

**Never logged or stored:** the WHM token, and the service password.

---

## 9. Reseller and white-label rules (non-negotiable)

These follow the standing isolation rules:

- A store customer's request carries `reseller_id`. Every reseller query filters by it, and a store can
  never see another store's requests or any platform customer's requests.
- **Emails** to store customers use `EmailDispatcher::onBehalfOfStore($store)`, so no platform name, From
  address or hostname appears. **Links** use `ResellerStoreLocator::baseUrlFor($store)`.
- The **confirmation page** on a store host renders with the store's branding. A token issued on one site
  does not work on another site: the store is checked against the request's `reseller_id`.
- **Staff alerts** go to the super admin, plus the store owner for store requests that need their approval.
  Store owners see their customers' requests, never the WHM server details.
- **Sub-resellers (tier 2):** their customers are handled the same way, scoped by the sub-reseller's own
  `reseller_id`.

---

## 10. Cron (`UsernameChangeCronJob`, runs every 5 minutes)

- Run `queued` requests that are due, including retries whose `next_attempt_at` has passed. At most N
  requests per run (default 10), one per server at a time.
- Expire unconfirmed requests whose `confirm_expires_at` has passed, and record the `expired` event.
- Release stale `processing` locks; their requests are re-verified with `accountsummary` before being retried.
- Prune events and final requests older than `retention_days`. Requests in mismatch or failed are kept until
  someone resolves them.
- Re-create any missing email templates.

---

## 11. Security checklist

- CSRF on every POST.
- Ownership is checked on every client route, and the reseller scope on every reseller route.
- Rate limits on: checking a name, creating a request, resending the email, entering the PIN, and
  confirming.
- Confirmation tokens are 32 random bytes, stored as SHA-256, compared with `hash_equals`, single-use and
  expiring. The confirmation page does nothing on GET beyond showing a **Confirm** button. The POST does the
  work, so link pre-fetchers and mail scanners cannot confirm a request by accident.
- Every action records the actor type, actor ID and IP in the event trail.
- The WHM error text is shown to admins in full. Clients get a friendly message, which never includes the
  server hostname.
- The add-on is **off** until the super admin activates it, and inactive means no UI and every endpoint
  returns 404.

---

## 12. Delivery phases

| Phase | Scope | Done when |
|---|---|---|
| **1. Core** | Migration 0214 + schema snapshot; `UsernamePolicy`; `PolicyResolver`; repository; cPanel module methods; `ProvisioningService::changeUsername`; the hook; add-on registration and activation | Unit tests cover the rules, policy merging and the WHM request shapes (`FakeHttpClient`) |
| **2. Client flow** | Service-page banner and modal (desktop and mobile); live check and suggestions; request, cancel, resend, PIN; confirmation page; throttle | The client can request and confirm; the request lands in `queued` or `pending_approval` |
| **3. Execution** | Executor (claim, preflight, rename, verify, sync); Queue job; cron job; retries; external-change detection; mismatch handling | Tests cover success, timeout-then-verify, external rename, sync failure and lock contention |
| **4. Admin** | Dashboard, queue, detail, manual tool with preflight, settings, product and client overrides, audit log, badge, service-page shortcut | Full admin control; manual renames work |
| **5. Emails and resellers** | Templates seeded and repairable; platform and store branding; reseller panel page, store policy, reseller approval | A store customer's flow carries no platform branding; a reseller sees only its own customers |
| **6. Optional extras** | Reseller-API endpoints (`GET/POST /api/reseller/services/{id}/username`); a CSV export of the audit log | Only if wanted |

Every phase ships with:
- tests in the existing style;
- lint, plus `bin/build-schema.php --check`;
- a regression run of the existing tests;
- a README section.

---

## 13. Test plan (highlights)

- **`UsernamePolicyTest`:**
  - each rule, including `test…`, a leading digit, 17 characters, upper case being normalised, reserved
    words, and same-as-current;
  - suggestions are always valid and never include a taken name.
- **`PolicyResolverTest`:**
  - the inheritance order;
  - a store can only tighten;
  - client waive and block;
  - unlimited (`0`);
  - the cooldown.
- **`CpanelUsernameApiTest`:**
  - the exact `modifyacct`, `verify_new_username` and `accountsummary` requests;
  - both WHM reply shapes.
- **`UsernameChangeServiceTest`:**
  - every valid and invalid status transition;
  - one open request per service;
  - name reservation;
  - expiry;
  - a token is single-use and only works on its own site.
- **`UsernameChangeExecutorTest`:**
  - only one of two workers can claim a request;
  - a timeout is followed by verification;
  - an external rename is synced;
  - a sync failure leads to a mismatch and an alert;
  - backoff on retry;
  - the hook fires once.
- **`UsernameChangerIsolationTest`:**
  - store emails use store branding;
  - store links use the store's host;
  - a reseller cannot read or act on another store's requests or platform requests;
  - endpoints return 404 when the add-on is inactive.
- **View tests:**
  - the banner shows only when the customer is eligible;
  - the modal never shows the server hostname;
  - the confirmation page on a store host is branded as the store.

---

## 14. Decisions taken by default (can be changed)

1. **"Reseller users"** means customers of WHMP reseller stores (both tiers), and the resellers' own
   services. Renaming accounts that live *inside* a customer's own WHM reseller account is out of scope,
   because WHMP does not hold those servers' credentials.
2. **Product types:** both `shared` and `reseller` cPanel products are eligible by default. Renaming a WHM
   reseller account is supported by `modifyacct`, and cPanel keeps ownership of its sub-accounts.
3. **Database renaming** is off by default, because it breaks site configuration files unless they are
   updated. The admin can allow it, and the client must opt in with a clear warning.
4. **Approval** is set to *none* by default (which still needs the client's email or PIN confirmation). The
   super admin can require admin approval, or let stores require their own.
5. **One completed change per service** by default, with a 30-day cooldown.
6. **Payment is OFF by default.** When the super admin switches it on, the default lets resellers resell
   (`store_pricing` = 1); a store that sets no price charges exactly its cost.

---

## 15. Instant — and true — precheck

The client should never feel a wait between typing and seeing whether a name works, and **"available" must
mean the hosting server agrees**.

> **Revision (live WHM check).** The first build answered the live check from our own records only (no WHM
> call). The copy of WHM's account list behind that is filled by cron, so when cron had not run (or the
> refresh failed) the copy was empty. The modal then showed **"available" for names that already existed
> on the server**, and WHM rejected them at submit. The precheck now confirms every locally-free name with
> WHM itself, as described below.

**In the browser** (`public/assets/js/username-changer.js`):
- The rules (length, `^[a-z][a-z0-9]*$`, no leading digit, no `test…`, the reserved list) are embedded in the
  page and checked **on every keystroke with no network**. Most typos get their answer in under a frame.
- Only "is it free?" goes to the server. It is **debounced 120 ms**, the previous request is **aborted**
  (`AbortController`) when the client keeps typing, and each settled answer is **cached** for the life of the
  modal. Re-typing a name, or going back to one, is instant.
- "Checking with the server…" only appears if the answer takes longer than **160 ms**.
- **Suggestions are never assumed free.** When the modal opens, the chips are verified **one at a time in the
  background** (never more than one request in flight). Taken chips disappear and free ones get a ✓ and a
  cached answer, so clicking a chip is an instant, true answer.
- **"Couldn't reach the hosting server"** (`unverified`) is a warning, not a verdict. It is never cached and
  keeps *Next* disabled. The check retries once by itself after 2.5 s, and typing again re-checks too.
- **Warm-up:** hovering or focusing the *Change* button calls `GET /client/services/{id}/username/warm`. That
  opens the connection and re-syncs the server's account copy if it is stale (see below), before the first
  keystroke.

**On the server** (`UsernameAvailability`):
- `fast()`: rules plus at most four **indexed lookups**:
  1. `services.username`;
  2. open requests' reserved names;
  3. the local copy of the server's accounts (`username_change_server_accounts`);
  4. the first-8 rule on MySQL servers.

  Invalid and known-taken names are settled here in about a millisecond, **with no WHM call**.
- `live()`: `fast()` and then, **only for a name that passed**, WHM's `verify_new_username`. The call uses a
  **short-timeout HTTP client** (6 s; the module's main client keeps the 300 s that `createacct` needs).
  - WHM says taken → `server`, with a plain-words reason. A name WHM reports as an existing account is
    **added to the local copy at once**, so the next check of it, by anyone, is local.
  - WHM unreachable → `unverified`. It is **never** reported as available, and it is sent with
    `Cache-Control: no-store`.
  - Settled answers carry `private, max-age=20`.
- `live()` backs the client check, the admin manual check, submit, and the final check before the rename.
  `deep()` is now an alias of it.
- **The session lock is released** (`SessionManager::release()`) before the WHM call. PHP file sessions
  serialise requests, so without this a slow WHM answer would queue the next keystroke's request.
- `ensureFresh(serverId)`, called by the warm endpoint, re-syncs a server's account copy when it is older
  than 15 minutes or was never synced. That means a fresh install works without cron.
  - A single conditional `UPDATE … WHERE updated_at < now − 2 min` claims the refresh, so a burst of modal
    opens triggers one `listaccts`, not one each.
  - The endpoint releases the session and ignores client aborts.
  - Cron still refreshes every 15 minutes and right after every rename. Admins can refresh from
    Settings → cPanel servers.
- Rate limit: 90 checks a minute per session (a session counter, no database write).

**Typical timings:** about a millisecond for local verdicts. For a free name, one WHM round-trip, usually well
under the 160 ms spinner threshold on a nearby server.

**Verified:**
- `tests/Unit/UsernameLiveCheckTest.php` runs the real `ProvisioningService` and cPanel module against a
  scripted WHM. It covers the reported bug (empty local copy, WHM has the account → taken), WHM-free →
  available, unreachable → `unverified`, known-taken names never reaching WHM, the on-demand refresh and
  its claim, and the short-timeout client wiring.
- A DOM test of the real script checks that:
  - the warm endpoint is called on hover;
  - chips are verified (a taken one is dropped, a free one is ticked);
  - a server-taken name is rejected;
  - `unverified` is retried and never cached;
  - rule violations make zero requests;
  - 7 keystrokes make exactly 1 request.

---

## 16. Payment (ON/OFF) and reseller resale

**Switch.** Admin → cPanel Username Changer → Settings & pricing → 💳 *Charge a fee for each username change*.
- **Off (default):** every change is free, and nothing below applies.
- **On:** the admin sets the **fee** in the catalog (pricing) currency. A product override can set a
  different fee per product.

**Who pays what** (all in catalog currency; `PolicyResolver::pricing()`):

| Customer | Pays | Who earns |
|---|---|---|
| Platform customer | the admin fee **F** | platform |
| Customer of a store | the store's price **P₁**, never below F (blank = F) | store earns **P₁ − F** |
| Customer of a sub-reseller | the sub-reseller's price **P₂**, never below its cost **C₂ = max(P₁, F)** | sub-reseller earns **P₂ − C₂**; the upline store earns **C₂ − F** |

This is the same cost chain as product pricing: a sub-reseller buys at its upline's price.

**Resale settings:**
- A store sets its price on *Reseller Area → Username requests → Your price*. The page shows its cost and
  the live margin, and the server refuses a price below cost.
- The admin can switch resale off (`store_pricing` = 0). Stores then charge exactly the admin fee and earn
  nothing.

**When the client is charged:**
1. Only after the client confirms (email/PIN) and after any approval. Nobody pays for a request that is
   declined.
2. An invoice is raised in the client's own currency (denominated, as all WHMP invoices are). The request
   waits in `awaiting_payment`. The banner shows *Pay invoice #…*, and the client gets a store-branded
   *payment due* email.
3. On `INVOICE_PAID`, the request is queued and the rename runs. The cron job also reconciles invoices paid
   by routes that fire no hook, and cancels requests whose invoice was cancelled.

**Who never pays:** admin renames, and clients with a *waive* override.

**Crediting resellers:**
- On payment, `ResellerLedgerService::creditFeeShare` posts the store's margin (`adjustment`). It also posts
  the upline's share (`upline_margin`) for a sub-reseller's customer, scaled to the invoiced amount and
  converted to base like every store receipt. Holding days apply as for other earnings.
- A claim column (`fee_credited_at`) makes the credit **idempotent**, so a repeated hook never pays twice.
- On `INVOICE_REFUNDED`, the shares are **reversed** once (`fee_reversed_at`). A refund never un-renames
  the account.

**Isolation:**
- The store sees only its own customers' requests and its own cost and price, never the server.
- Invoices and emails carry only the store's brand.

Covered by an end-to-end test: request → confirm on the store's host → invoice for P₂ → paid → both
resellers credited → repeated hook ignored → refund → both reversed exactly once.
