# Cloudflare Add-on for WHMP — Plan & status

Status: **Phases 1, 2 and 3 BUILT** (see "Implementation status" below). The decisions
in §12 are settled as follows, and they override any remaining historical notes in this plan:

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
  → default settings. Automatic setup refuses an existing zone. The admin can explicitly
  import a matching Free zone only for an active service with a recorded checkout opt-in;
  it reads zone details and DNS records but changes neither Cloudflare nor registrar data.
* Existing-zone import: `/admin/cloudflare/import` lists zones in the selected account,
  blocks paid-plan zones and existing WHMP ownership, and only offers matching opted-in
  services. Imported zones keep `ns_switched_by_us = 0`, so WHMP never restores nameservers
  that it did not change.
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

**Phase 3 — Email Routing — implemented**

* Optional Free-plan Email Routing on an active, opted-in zone. Enabling and disabling are separate, explicit client actions; neither changes nameservers.
* Before enabling, WHMP fails closed unless Cloudflare returns the complete required checklist: all three Email Routing MX targets at the apex, an apex SPF record including `_spf.mx.cloudflare.net`, and a non-empty DKIM TXT key. It also reads the zone's apex MX, SPF and domain DKIM records, and blocks on any existing root MX, apex SPF or domain DKIM record or other non-clean Cloudflare state. It never deletes, replaces, merges, duplicates or unlocks external mail records. Clients review the required Cloudflare DNS records before they confirm; Cloudflare's supported DNS endpoint then adds/locks only its managed Email Routing DNS records.
* Cloudflare's destination addresses are account-wide, so WHMP keeps a private mapping to the exact client **and** reseller ID. An address already owned by another WHMP tenant cannot be claimed or displayed. A route can target only one of that owner's Cloudflare-verified destinations. Routes are zone-scoped and WHMP changes/deletes only routes whose saved owner and remote rule still match.
* A catch-all is separate and off by default; enabling it requires an explicit destination selection. External/unmapped rules are read-only and their destinations are never shown.
* Disabling requires a second confirmation, stops forwarding and asks Cloudflare to remove its Email Routing-managed DNS. Clients are told to preserve replacement mail and outbound SPF/DKIM first. Nameservers and unrelated DNS are never changed.
* Cloudflare Free limits are enforced (200 destination addresses per account; 200 routing rules per zone). No charge, markup, paid-plan upgrade or reseller monetization is involved.
* API methods live in `CloudflareApi`; owner-scoped logic and audit events in `CloudflareEmailRouting`; tables are created by migration `0218_cloudflare_email_routing.php`. Tests use the stateful fake in `tests/Unit/CloudflareAddonTest.php`.
* Required scopes: Zone Settings Read and Write; DNS Read (mandatory fail-closed preflight); account-level `Email Routing Addresses` Read and Write (destination addresses are account-wide); and zone-level `Email Routing Rules` Read and Write (forwarding rules belong to a zone). The existing Cloudflare add-on permissions are still required for their own features.

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
| Creating a Cloudflare account per customer now requires the **Tenant API**, which requires a signed partner agreement (MSP/Agency). ([source](https://developers.cloudflare.com/tenant/get-started/)) | Partner/Tenant mode is out of scope; WHMP uses the owner's normal Cloudflare account only. |
| A normal account with a **scoped API token** can add client domains (zones) to the owner's account. | This is the only supported connection mode. Every service is separately mapped to its WHMP client and reseller owner. |
| An account cannot hold unlimited **pending** (not yet activated) zones. The limit grows as zones activate. ([source](https://community.cloudflare.com/t/limit-of-pending-zones-increaseability/258507)) | WHMP tracks pending zones and warns the admin. Nameservers change only after the client explicitly clicks; abandoned-zone cleanup follows the 7-day grace period. |
| **Page Rules are deprecated.** Creating new ones stopped for all accounts in Jan 2025, and they are being migrated to Redirect, Cache, Configuration and Origin Rules. ([source](https://ppc.land/cloudflare-retires-page-rules/), [source](https://community.cloudflare.com/t/important-page-rules-migration/656021)) | WHMP builds on the **modern Rules** (rulesets API). Existing Page Rules are not created by WHMP. |
| **Cloudflare for SaaS** can incur per-hostname or Enterprise charges. ([source](https://developers.cloudflare.com/cloudflare-for-platforms/cloudflare-for-saas/plans/)) | It is out of scope unless a complete Free-only implementation is available; WHMP never enables a paid feature or upgrades a zone. |
| Paid plans (Pro/Business) for a zone in *your* account are billed to **your** Cloudflare card. | Paid plans are not offered or upgraded by WHMP. Every zone remains Cloudflare Free. |

---

## 2. Connection and token permissions

WHMP uses one mode only: the platform owner's **normal Cloudflare account** with a
scoped API token. The add-on is Free-plan only. Partner/Tenant connections and
client-owned Cloudflare accounts are not supported. One zone is mapped to the exact
WHMP client and reseller owner; the Cloudflare zone ID is never accepted from the
browser.

The token must cover the connected account and the zones WHMP manages. The admin
screen lists the exact custom-token scopes:

* Zone: Read; Zone: Edit; Zone Settings: Read and Write; DNS: Read and Edit;
  Cache Purge: Purge; Firewall Services: Edit; Single Redirect: Edit; Cache Rules:
  Edit; Zone WAF: Edit; Analytics: Read; SSL and Certificates: Edit.
* Account: Account Settings Read, Account Rulesets Edit and Account Filter Lists
  Edit (the last two are required by Cloudflare's Cache Rules API).
* For Email Routing: Zone Settings Read and Write; **DNS Read is mandatory** for
  the non-destructive MX/SPF/DKIM preflight; account-level **Email Routing Addresses**
  Read and Write (destinations are account-wide); zone-level **Email Routing Rules**
  Read and Write (forwarding rules belong to a zone).

If a scoped token lacks a permission, the affected page/action fails closed and
shows a permission error; no automatic fallback or DNS mutation is attempted.

---

## 3. How it is sold

1. Cloudflare is an optional free add-on to eligible hosting products. Admins select
   the products and attach the configurable opt-in (No / Yes); it is never silently
   included. If the admin allows it, the client may also explicitly enable it later
   from an eligible service page.
2. The domain comes from the hosting service; the client is not asked to substitute
   another domain or choose a Cloudflare connection.
3. Reseller stores may offer the same add-on **free only**, without setup fees,
   markup or tier-2 monetization. It is fully white-labelled: no main-host name in
   UI or email, and every zone/address/rule is scoped by the exact client and
   `reseller_id`. Store customers are managed only through the reseller's manage page
   or their reseller account.

---

## 4. Service lifecycle (provisioning module `cloudflare`)

| Event | What WHMP does |
|---|---|
| **Create** | Find or create the zone (`POST /zones`, `type: full`). Store zone id, assigned nameservers and the domain's **original nameservers** (for rollback). Optionally **import existing DNS** first (§9.2) so the site keeps working when nameservers switch. Apply product defaults (SSL mode, Always HTTPS, security level…). |
| **Nameservers** | Never switch automatically. If the domain is registered through WHMP, offer the client an explicit one-click switch through the registrar; otherwise show clear instructions. Retain the prior nameservers for a client-requested rollback. Email Routing setup never changes nameservers. |
| **Activation** | A cron runs `activation_check` for pending zones every 15 min (backing off over time). The client is emailed when the zone becomes **Active**, with a reminder at day 3 and day 7 if still pending. |
| **Suspend** | Pause the zone (`paused: true`). DNS keeps resolving, but Cloudflare proxying and features stop. The client dashboard becomes read-only. |
| **Unsuspend** | Unpause and restore editing. |
| **Plan** | WHMP accepts only Cloudflare Free zones. It never upgrades, sells or monetizes Cloudflare plans or paid features. |
| **Terminate** (safe by default) | Export a BIND backup, notify the client, and schedule zone deletion after the 7-day grace period. Do not change or restore nameservers automatically. The client chooses any nameserver change themselves; the grace period is undoable until deletion. |
| **Sync** (daily) | Refresh status, plan, nameservers and paused state. Detect **nameserver drift** (domain moved away from Cloudflare) and notify the admin and client. Detect zones deleted directly in Cloudflare. |

---

## 5. Client dashboard (client area → service → "Cloudflare")

Built in the client area's existing visual style (cards, tabs), mobile-first. The
add-on is Free-plan only; paid-plan upsells and upgrades are absent. Every request
is checked against the signed-in client, the exact reseller owner and the zone ID
stored in WHMP. A zone ID is never taken from the browser.

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
| **Email Routing** (optional) | Explicit Free-plan opt-in. Before activation, show Cloudflare's required DNS records and inspect apex MX/SPF plus domain DKIM records. Fail closed on any existing mail records; never overwrite/merge mail DNS automatically. The client explicitly enables or disables Cloudflare-managed records. Account-wide destinations and zone rules are strictly mapped to the exact client + reseller owner; only verified destinations can receive one-to-one forwarding rules. Catch-all is separate and off by default. No nameserver changes, mailboxes, outbound sending, fees or reseller markup. |
| **Activity** | Who changed what and when (client, admin or reseller staff), for this zone. |

---

## 6. Admin area

* **Add-on page** (`/admin/addons/cloudflare`): the normal-account API token and
  account picker, a **Verify token** checklist, default Free-plan settings, product
  opt-in checklist, 7-day safe-termination grace period, and email template links.
* **Zones list**: every zone with client, service, plan, status and last sync. Filters:
  pending, paused, moved away, **orphans** (zones in Cloudflare that are linked to no
  service), and pending deletion. Bulk sync.
* **Import existing zones** (`/admin/cloudflare/import`): list zones in the selected
  Cloudflare account and link a Free-plan zone only to its exact active, checkout-opted-in
  service. WHMP reads the zone and DNS records; it does not alter DNS, settings, DNSSEC,
  or registrar nameservers during import.
* **Per-zone view**: the same dashboard as the client, plus admin-only lifecycle
  actions (pause, inspect, delete according to the grace policy, restore a BIND backup).
  No paid-plan changes are available.
* **Health card**: token valid, permissions OK, pending-zone count against the limit,
  last cron run, and API errors in the last 24 h.
* No Cloudflare cost report or monetization: all supported zones and this add-on stay Free.
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

* `cloudflare_zones`: service_id, exact client/reseller owner, Cloudflare zone ID,
  name/status, nameservers, pause/deletion state, BIND backup, DNSSEC and certificate
  metadata. The single normal-account connection is stored in encrypted add-on settings.
* `cloudflare_activity`: zone_id, actor_type, actor_id, action, summary, created_at.
* `cloudflare_email_destinations`: unique account-level Cloudflare destination ID and
  normalised email, privately mapped to one client and reseller ID, with verification
  state. A unique email claim prevents cross-tenant reuse of Cloudflare's global list.
* `cloudflare_email_routes`: zone/client/reseller owner, Cloudflare rule ID, local alias,
  destination mapping and enabled state; composite uniqueness prevents duplicate aliases
  or remote IDs in one zone.

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

1. **Client-controlled nameservers**: WHMP offers a clear one-click switch only after the client submits it; nameservers are never changed automatically.
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
8. **One normal-account connection mode**, never a partner or client-owned account.
9. **Strict reseller white-label and isolation**; Cloudflare remains a free value-add with no resale markup.
10. **Pending-zone limit protection**: warnings, reminders and abandoned-zone cleanup.
11. **Free-plan guardrails**: no paid-plan changes, upgrade billing or monetization.
12. **Nameserver drift detection** with alerts, plus a full activity/audit trail.
13. **Email Routing** with verified forwarding destinations, explicit catch-all opt-in and a fail-closed MX/SPF/DKIM safety check.
14. **Plain-English guidance** on risky settings (Flexible SSL redirect loops, HSTS lock-in).
15. **No licence server** and no phoning home. It's part of WHMP.

---

## 10. Phases

| Phase | Scope | Rough size |
|---|---|---|
| **1 — Core (MVP)** | Normal-account connection and token verify; opt-in provisioning lifecycle; client-click nameserver switch only; activation cron and emails; client Overview, DNS, SSL, Caching, Security and Activity; admin zones and import; strict reseller white-label | Built |
| **2 — Power features** | Rules (Redirect/Cache/Firewall); Analytics; DNSSEC plus DS safety; Origin certificate and cPanel install; Free-plan Speed settings | Built |
| **3 — Email Routing** | Optional Free-plan forwarding addresses, verified destinations, zone rules, catch-all off by default, ownership-scoped persistence, safe DNS preflight, explicit setup/disable and tests | Built |

Each phase ships with unit tests (recorded API responses), screenshots and a docs page.

---

## 11. Out of scope or limited

* Creating Cloudflare **accounts** for clients without a partner agreement (Cloudflare
  no longer allows it).
* Creating new **Page Rules** (Cloudflare no longer allows it); shown read-only.
* Argo, Load Balancing, Workers, Zero Trust: possible later, not planned.
* Apex domains through Cloudflare for SaaS (Enterprise only).

---

## 12. Settled decisions

1. Use a normal Cloudflare account and API token; no Partner/Tenant or client-owned mode.
2. Free plan only; the add-on is free, with no monetization or paid-plan upgrade.
3. The client opts in through the configurable product option; never auto-include.
4. Nameservers change only when the client explicitly clicks. Email Routing never changes them.
5. On service termination, delete the zone after the 7-day grace period; do not switch or restore nameservers automatically.
6. Resellers may offer Cloudflare free, with strict per-client and reseller-ID ownership isolation.
7. Email Routing is optional, explicit and fail-closed around existing email DNS. Pre-existing MX, root SPF or domain DKIM records block automatic setup until manually reviewed; WHMP never overwrites them.
