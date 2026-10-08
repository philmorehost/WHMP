# Cloudflare Add-on for WHMP — Plan & status

Status: **Phases 1 and 2 BUILT** (see "Implementation status" below). The decisions in
§12 were answered as follows, and they override anything else in this plan:

| Decision | Answer |
|---|---|
| Plans | **Free plan only** for everyone — no paid plans, no upgrades. |
| Connection | The owner's **normal Cloudflare account** + API token (Provider mode). Partner/Tenant and client-owned accounts are dropped. |
| Selling | Clients **opt in** through a free configurable option on chosen products (or later from the service page). |
| Nameservers | Switched **only when the client clicks**, and only for domains registered with us. |
| Termination | Zone deleted **automatically after a 7-day grace period** (configurable), undoable until then. |
| Resellers | Stores may offer it **free**; no monetisation anywhere. |

## Implementation status

**Phase 1 — done**

* Add-on `cloudflare` (Addons → Cloudflare), off until activated; admin screens at
  `/admin/cloudflare` (zones, zone detail, settings) behind `addons.manage`.
* Settings: API token (SecretBox-encrypted, verified with `/user/tokens/verify`),
  account picker, default SSL / Always HTTPS / security level, grace days, "allow
  enabling after order", product checklist (attaches the free option group).
* Configurable option group "Cloudflare CDN & Security (Free)" — No thanks / Yes,
  priced 0 on all seven cycles. Choosing Yes enrols the service when it goes active
  (`SERVICE_STATUS_CHANGED`), once per service.
* Zone creation: `POST /zones` → DNS scan → apex A + www CNAME to the hosting IP if
  missing → mail/ftp/cpanel/webmail/whm/autodiscover and MX targets forced to DNS-only
  → default settings. An existing zone for the domain is refused, never adopted.
* Client page `/client/services/{id}/cloudflare` (main site and stores, white-label):
  Overview (status, nameservers, one-click switch for our registrations, registrar
  steps otherwise, Check now, Turn off / Keep), DNS (A/AAAA/CNAME/MX/TXT/NS CRUD with
  proxy toggle; other types delete-only), SSL/TLS, Caching (purge all/URLs, dev mode,
  cache level, browser TTL), Security (level incl. "I'm under attack", browser check,
  IP access rules), Activity. Card on the service page.
* Lifecycle: suspend → pause (only zones WHMP paused are resumed); terminate/cancel or
  client turn-off → restore nameservers WHMP switched once DNSSEC DS safety waits have
  cleared, then delete after the grace period with a BIND backup kept; re-activation
  inside the grace keeps the zone.
* Cron `cloudflare` (15 min): due deletions, activation checks (≤1/h per zone) with
  reminders on day 3 and 7, daily reconcile with Cloudflare and the service status.
* Emails: `cloudflare_zone_pending`, `_active`, `_reminder`, `_removal_scheduled`
  (store-branded for store customers).
* Tables `cloudflare_zones`, `cloudflare_activity` (migration 0216).
* Tests: `tests/Unit/CloudflareAddonTest.php` (SQLite + a stateful fake Cloudflare).

**Phase 2 — implemented**

* **Rules:** modern Rulesets API (not deprecated Page Rules): Single Redirects,
  Cache Rules and WAF custom rules; Free-plan quotas (10 / 10 / 5); validated
  form conditions, loop checks, safe presets and rule deletion. Config/Origin
  Rules are not included in this phase.
* **Analytics:** Cloudflare GraphQL daily traffic, cache ratio, bandwidth,
  threats, page views and top countries (7 or 30 days).
* **DNSSEC:** turn signing on at Cloudflare and publish the DS record automatically
  through the ResellerClub registrar API where supported; otherwise show the DS
  values to copy. On disable/removal, remove only records WHMP added, wait 48 hours
  for resolver caches before nameserver changes, and block deletion while an
  unmanaged registrar DS record may still be present.
* **Origin certificate:** generate a private key/CSR, issue a 15-year Cloudflare
  Origin CA certificate and install it on supported cPanel services, with an
  optional one-click Full (strict) mode. The private key is sent to cPanel in a
  POST body (never a URL) and is not stored in WHMP. The certificate is revoked
  during zone deletion.
* **Speed & network:** Free-plan settings surfaced when available: Early Hints,
  HTTP/3, 0-RTT, Rocket Loader, Always Online, IPv6, WebSockets, opportunistic
  encryption, TLS 1.3, email obfuscation and hotlink protection.
* Token instructions list the extra feature permissions. The feature tabs fail
  independently when a scoped token lacks one.

**Phase 3 — deferred:** Cloudflare Email Routing (forwarding addresses and DNS).

Benchmarks studied:

* [Cloudflare Manager for WHMCS](https://marketplace.whmcs.com/product/9045-cloudflare-manager-for-whmcs) (Angle Modules, $9.99)
* [Cloudflare WHMCS Module](https://marketplace.whmcs.com/product/2146-cloudflare-whmcs-module) (WHMCS Global Services, $239.20/yr)

WHMP should match **every** feature of both, fix their weak spots, and add
the hosting-specific automation neither of them has (§9).

---

## 1. What Cloudflare allows in 2026

Some of what the older WHMCS module advertises depends on Cloudflare programmes
that no longer exist. The design must be built on what still works.

| Fact | Consequence for WHMP |
|---|---|
| Cloudflare **shut down the Host API and Reseller API on 1 Nov 2022**. These were what "automatically create a Cloudflare account for the customer" used. ([source](https://noise.getoto.net/2022/06/27/new-partner-program-for-smb-agencies-hosting-partners-now-in-closed-beta/), [source](https://www.webhostingtalk.com/showthread.php?t=1864833)) | We cannot create Cloudflare accounts for clients with a normal account. One reviewer of module 2146 was told by Cloudflare that it is "a legacy integration that is no longer supported by their partner program". |
| Creating a Cloudflare account per customer now requires the **Tenant API**, and that needs a signed partner agreement (MSP/Agency). ([source](https://developers.cloudflare.com/tenant/get-started/)) | Offered as an optional **Partner mode** (§2-B), not the default. |
| A normal account with a **scoped API token** can add any number of client domains (zones) to *your* account. | This becomes the default **Provider mode** (§2-A) and works for every WHMP owner on day one. |
| An account cannot hold unlimited **pending** (not yet activated) zones. The limit grows as zones activate. ([source](https://community.cloudflare.com/t/limit-of-pending-zones-increaseability/258507)) | WHMP tracks pending zones and warns the admin. It also chases clients to switch nameservers (emails plus automatic switching when we are the registrar), and offers cleanup of abandoned zones. |
| **Page Rules are deprecated.** Creating new ones stopped for all accounts in Jan 2025, and they are being migrated to Redirect, Cache, Configuration and Origin Rules. ([source](https://ppc.land/cloudflare-retires-page-rules/), [source](https://community.cloudflare.com/t/important-page-rules-migration/656021)) | WHMP builds on the **modern Rules** (rulesets API). Module 9045 still advertises "Page Rules". Existing Page Rules are shown read-only. |
| **Cloudflare for SaaS**: 100 custom hostnames included, then $0.10/hostname/month. Apex domains need Enterprise. ([source](https://developers.cloudflare.com/cloudflare-for-platforms/cloudflare-for-saas/plans/)) | This is an optional later phase: CDN/SSL for a `www.` hostname without changing nameservers. |
| Paid plans (Pro/Business) for a zone in *your* account are billed to **your** Cloudflare card. | WHMP invoices the client first. The zone is only upgraded **after the invoice is paid**, and downgrades happen at the end of the term (§4). |

---

## 2. Connection modes

The admin adds one or more "Cloudflare connections" (stored like a server, token
encrypted with `SecretBox`). Each product picks a connection and a mode.

| Mode | Who owns the zone | Needs | Use for |
|---|---|---|---|
| **A. Provider account** (default) | Your Cloudflare account; one zone per client domain | A scoped API token | Free CDN for hosting clients, and resale of Pro/Business |
| **B. Partner / Tenant** | A Cloudflare account created per client under your tenant; client can optionally be invited to log in to Cloudflare | Cloudflare partner agreement plus Tenant API access | Partners who want full account separation |
| **C. Client's own account** ("Connect Cloudflare") | The client's own Cloudflare account; WHMP is only a control panel | The client pastes their own API token | A free value-add for every client; no provisioning or billing |

The client dashboard (§5) is identical in all three modes. Only the
connection behind it differs.

Required token permissions (the admin screen lists the exact custom-token scopes):
zone-scoped Zone: Edit, Zone Settings: Edit, DNS: Edit, Cache Purge: Purge,
Firewall Services: Edit, Single Redirect: Edit, Cache Rules: Edit, Zone WAF: Edit,
Analytics: Read, and SSL and Certificates: Edit; account-scoped Account Settings:
Read, Account Rulesets: Edit, and Account Filter Lists: Edit (Cloudflare's Cache
Rules API requires the latter two). The token must cover the connected account and
all of its zones. Email Routing permissions remain deferred with Phase 3.

---

## 3. How it is sold

1. **Stand-alone product** (product type "Cloudflare"): the client enters a domain at
   order. Typical tiers: *Free CDN & Security*, *Pro*, *Business*.
2. **Add-on to a hosting plan**, using the existing product add-ons, which are child
   services (`services.parent_id`). It inherits the hosting service's domain, so the client is not asked
   again.
3. **Auto-include Free Cloudflare with hosting** (admin option, default OFF): every new
   hosting order gets a free Cloudflare child service automatically.
4. **Resellers** can sell any Cloudflare product with their own markup, using the existing
   reseller pricing and tier-2 = upline retail. It is fully white-labelled: no main-host
   name in the UI or emails (StoreMailBranding), and zones are tagged with
   `reseller_id`. Store customers are visible only through the reseller's manage page,
   as with every other service.

---

## 4. Service lifecycle (provisioning module `cloudflare`)

| Event | What WHMP does |
|---|---|
| **Create** | Find or create the zone (`POST /zones`, `type: full`). Store zone id, assigned nameservers and the domain's **original nameservers** (for rollback). Optionally **import existing DNS** first (§9.2) so the site keeps working when nameservers switch. Apply product defaults (SSL mode, Always HTTPS, security level…). |
| **Nameservers** | If the domain is registered **through WHMP**, offer one-click (or automatic, admin option) **switch to Cloudflare nameservers** through the registrar module's `saveNameservers()`, keeping the old ones for rollback. Otherwise, email the client clear instructions and show them on the service page with copy buttons. |
| **Activation** | A cron runs `activation_check` for pending zones every 15 min (backing off over time). The client is emailed when the zone becomes **Active**, with a reminder at day 3 and day 7 if still pending. |
| **Suspend** | Pause the zone (`paused: true`). DNS keeps resolving, but Cloudflare proxying and features stop. The client dashboard becomes read-only. |
| **Unsuspend** | Unpause and restore editing. |
| **Upgrade / downgrade** | Uses the existing upgrade flow plus `changePackage()`. Upgrades are applied to the zone **only after payment**; downgrades at the end of the paid term. Before offering paid plans, WHMP lists the plans the connection is actually allowed to buy (`available_plans`). |
| **Terminate** (safe by default) | 1) Export the zone as a BIND file and attach it to the service. 2) If we are the registrar, **restore the original nameservers**. 3) Mark the zone "pending deletion" for N days (default 7). 4) Delete it afterwards; the admin can undo during the grace period. Immediate deletion is only possible if the admin explicitly chooses it per product. |
| **Sync** (daily) | Refresh status, plan, nameservers and paused state. Detect **nameserver drift** (domain moved away from Cloudflare) and notify the admin and client. Detect zones deleted directly in Cloudflare. |

---

## 5. Client dashboard (client area → service → "Cloudflare")

Built in the client area's existing visual style (cards, tabs), mobile-first. Each tab can be
switched off per product by the admin, and features the zone's plan does not include
are hidden or shown as "Available on Pro". Every request is checked against
the signed-in client, the service they own, and the zone id **stored in WHMP**.
A zone id is never taken from the browser.

| Tab | Features |
|---|---|
| **Overview** | Status badge (Pending / Active / Paused / Moved), plan, nameservers with copy buttons, **Check now** button, setup checklist, quick toggles: Under Attack, Development Mode, Always HTTPS, plus a **Purge everything** button. |
| **DNS** | List, search, add, edit and delete records (A, AAAA, CNAME, MX, TXT, SRV, CAA, NS); proxy (orange cloud) toggle with an explanation; TTL. **Import BIND file**, **Export BIND**, and templates such as "Point to my hosting server", "Google Workspace MX" and "Microsoft 365". Warnings before deleting the apex or MX record. |
| **SSL/TLS** | Encryption mode (Off / Flexible / Full / Full strict) with plain-English guidance; Always Use HTTPS; Automatic HTTPS Rewrites; minimum TLS version; TLS 1.3; HSTS (with a strong warning). **Create Origin Certificate**, plus **one-click install on my cPanel hosting** (§9.3). |
| **Speed** | HTTP/3, Early Hints, Rocket Loader, 0-RTT, and image Polish/Mirage on plans that include them. |
| **Caching** | Cache level, browser cache TTL, Always Online, Development Mode (Cloudflare turns it off automatically after 3 h, and a countdown is shown). **Purge**: everything, specific URLs, and by host/prefix/tag where the plan allows. |
| **Security** | Security level, **I'm Under Attack** (with an optional auto-revert timer, §9.6), Bot Fight Mode, Browser Integrity Check, challenge passage. **IP Access Rules**: allow/block/challenge an IP, range, country or ASN. **WAF custom rules** (simple form builder within the plan's rule limit). **Rate-limiting rule** (Free plan includes 1). |
| **Rules** (modern) | **Redirect Rules** (replacing Page Rule forwarding: "redirect www → apex", "old URL → new URL"), **Cache Rules** ("cache everything on /images", "bypass cache on /wp-admin"), **Configuration Rules**. Templates for WordPress, WooCommerce and static sites. Legacy Page Rules are listed read-only. |
| **Analytics** | 24 h / 7 d / 30 d: requests, bandwidth, % cached, unique visitors, threats blocked, top countries, status codes. Uses the GraphQL Analytics API. |
| **DNSSEC** | Enable/disable. **If the domain is registered through WHMP, the DS record is sent to the registrar automatically** (§9.4); otherwise the DS values are shown to copy. |
| **Email Routing** (optional) | Free forwarding addresses (`sales@domain` → Gmail), catch-all, and setup of the required MX/TXT records. |
| **Activity** | Who changed what and when (client, admin or reseller staff), for this zone. |

---

## 6. Admin area

* **Add-on page** (`/admin/addons/cloudflare`): connections, a **Verify token** checklist,
  default settings for new zones, feature toggles, safe-termination grace days,
  auto-include-with-hosting switch, and email template links.
* **Zones list**: every zone with client, service, plan, status and last sync. Filters:
  pending, paused, moved away, **orphans** (zones in Cloudflare that are linked to no
  service), and pending deletion. Bulk sync.
* **Import existing zones**: link zones already in the Cloudflare account to
  clients/services, so a host that already uses Cloudflare can migrate in minutes.
* **Per-zone view**: the same dashboard as the client, plus admin-only actions (force
  pause, change plan without invoice, delete now, restore from BIND backup).
* **Health card**: token valid, permissions OK, pending-zone count against the limit,
  last cron run, and API errors in the last 24 h.
* **Cost report**: paid zones per plan, revenue, and what Cloudflare will charge you.
* **API log**: request/response log with tokens redacted, kept for 30 days (reusing the
  existing provider `HttpExchangeLog`).
* Staff permissions: `cloudflare.view`, `cloudflare.manage`, `cloudflare.delete`.

---

## 7. Notifications (email templates, editable)

`cloudflare_zone_pending` (nameserver instructions), `cloudflare_zone_active`,
`cloudflare_zone_reminder`, `cloudflare_ns_drift`, `cloudflare_plan_changed`,
`cloudflare_zone_suspended`, `cloudflare_zone_terminated` (with the BIND backup attached), and
`cloudflare_admin_errors` (daily digest). All are sent through EmailDispatcher, so they
respect the Invalid Email Blocker, store branding and in-app notification copies.

---

## 8. Data model (new tables)

* `cloudflare_zones`: id, service_id (nullable for imported/BYO), client_id,
  reseller_id, connection_id, mode (provider/tenant/byo), cf_account_id, cf_zone_id,
  name, status, plan, name_servers (JSON), original_name_servers (JSON), paused,
  activated_at, last_synced_at, delete_after, backup_path, timestamps.
* `cloudflare_client_tokens` (mode C): client_id, encrypted token, cf_account_id,
  verified_at.
* `cloudflare_activity`: zone_id, actor_type, actor_id, action, summary, created_at.
* Connections reuse the `servers` table (`module_slug = 'cloudflare'`, token encrypted).
  Add-on settings live in `addon_modules.config`.

Code layout: `core/Cloudflare/` with
* `CloudflareClient` (HTTP, pagination, rate-limit and retry, typed errors)
* `CloudflareProvisioningModule`
* `ZoneService`, `DnsService`, `SettingsService`, `RulesService`, `AnalyticsService`
* `CloudflareController` (client) and `CloudflareAdminController`
* cron jobs: `ZoneActivationJob`, `ZoneSyncJob`, `ZoneDeletionJob`
* `CloudflareAddon` (add-on page)

The HTTP client is injected (the existing `HttpClient` interface), so everything can be
unit-tested with recorded Cloudflare responses and no live calls.

---

## 9. Enhancements over both WHMCS modules

1. **Automatic nameserver switch and rollback** for domains registered through WHMP
   (neither module can do this reliably; 2146 claims it, 9045 doesn't).
2. **DNS import before switching**: copy the records from the client's hosting account
   (cPanel zone through the existing cPanel tools), or a DNS scan of common records, so
   email and subdomains don't break on activation. This is the #1 cause of "Cloudflare
   broke my site" tickets.
3. **Origin Certificate plus one-click install on cPanel**, then switch to *Full (strict)*:
   real end-to-end SSL without the client touching certificates.
4. **DNSSEC with automatic DS push** to the registrar.
5. **Safe termination**: BIND backup, nameserver restore, grace period and undo, instead of
   instant irreversible deletion (9045 warns that termination "can permanently remove a
   zone").
6. **I'm Under Attack with an auto-revert timer** and an attack banner on the client dashboard.
7. **Modern Rules** (Redirect/Cache/Configuration) with ready-made templates, instead of
   the deprecated Page Rules.
8. **Three connection modes**, including free "Connect your own Cloudflare" for every client.
9. **Reseller white-label** with tier pricing, which no WHMCS module offers.
10. **Pending-zone limit protection**: warnings, reminders and abandoned-zone cleanup.
11. **Paid-plan safety**: Cloudflare is charged only after the client has paid, and there is a cost report.
12. **Nameserver drift detection** with alerts, plus a full activity/audit trail.
13. **Email Routing** management (free forwarding mailboxes).
14. **Plain-English guidance** on risky settings (Flexible SSL redirect loops, HSTS lock-in).
15. **No licence server** and no phoning home. It's part of WHMP.

---

## 10. Phases

| Phase | Scope | Rough size |
|---|---|---|
| **1 — Core (MVP)** | Provider mode; connection plus token verify; provisioning lifecycle (create/suspend/unsuspend/safe terminate/sync); auto nameserver switch for our domains; activation cron plus emails; client Overview, DNS, SSL basics, Caching (purge/dev mode), Security level and Under Attack; admin zones list, import and health; reseller white-label | Largest |
| **2 — Power features** | Paid plan upgrade/downgrade with billing; Rules (Redirect/Cache/Config); IP Access and WAF custom rules; rate-limit rule; Analytics; DNSSEC plus DS push; Origin cert plus cPanel install; DNS import from cPanel; Speed tab | Large |
| **3 — Extras** | Client's own account mode; Partner/Tenant mode; Email Routing; Cloudflare for SaaS (CDN on `www` without nameserver change); cost report | Medium |

Each phase ships with unit tests (recorded API responses), screenshots and a docs page.

---

## 11. Out of scope or limited

* Creating Cloudflare **accounts** for clients without a partner agreement (Cloudflare
  no longer allows it).
* Creating new **Page Rules** (Cloudflare no longer allows it); shown read-only.
* Argo, Load Balancing, Workers, Zero Trust: possible later, not planned.
* Apex domains through Cloudflare for SaaS (Enterprise only).

---

## 12. Decisions needed from you

1. **Your Cloudflare account type:** a normal account (Provider mode) or a Cloudflare
   partner with Tenant API access?
2. **Plans to sell:** Free only at first, or Pro/Business too? (Paid plans are charged
   to your Cloudflare card; WHMP bills the client first.)
3. **Auto-include free Cloudflare with every hosting order?** (Default OFF.)
4. **Automatic nameserver switch** for domains registered with you: automatic, or
   only when the client clicks "Switch now"?
5. **Termination:** safe delete after a 7-day grace period (recommended), or keep the zone
   detached and never delete?
6. **Resellers:** allowed to sell Cloudflare products (white-label)? (Recommended: yes.)
7. **Scope of Phase 1:** happy with the split in §10, or move something earlier
   (for example Analytics or paid plans)?
