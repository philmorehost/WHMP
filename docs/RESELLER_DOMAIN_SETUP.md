# Connecting a reseller's own domain to the storefront

How to make `reseller.pmhserver.name.ng` actually open a reseller's store when the
platform itself runs at `client.philmorehost.com`.

---

## 1. The thing that trips everybody up

There are **two completely separate jobs**, and doing the first one feels finished
when it is not.

| | What it does | Who does it |
|---|---|---|
| **Proof of control** (DNS verification) | Convinces *this application* the reseller owns the domain, so their store is allowed to answer on it | The reseller, in the reseller portal (or an admin, on the store page) |
| **Routing and TLS** (serving) | Makes the **web server** answer for that hostname, and gives it a certificate | The **admin, on the server** — cPanel/DNS/TLS. The application cannot do any of it |

Verifying the domain makes the store *eligible* to be served at that address. It
does **not** make the address work. Until the web server knows the hostname, a
request for it never reaches this application at all — the server answers with its
default site (or nothing), which is why the domain "refuses to load".

**A DNS record alone is not enough.** A CNAME or A record only makes the *name
resolve* to the server's IP. The server still has to be told "you are also
responsible for this hostname". On cPanel that is an extra step: the domain has to
be **added to the account**.

---

## 2. Recovering from exactly the setup you describe

Your case:

- platform: `client.philmorehost.com`
- platform subdomain for the store: `reseller.client.philmorehost.com` (works, because
  of the `*.client.philmorehost.com` wildcard)
- reseller's own domain: `reseller.pmhserver.name.ng` (claimed and DNS-verified in the portal)
- attempted: CNAME `reseller.pmhserver.name.ng` → `reseller.client.philmorehost.com`

Where that stands:

- ✅ The DNS resolves, because the CNAME target itself resolves.
- ✅ The application will serve the store **if** a request arrives with
  `Host: reseller.pmhserver.name.ng`, because the domain is verified.
- ❌ **Nothing makes a request arrive with that Host.** cPanel has no domain entry
  for `reseller.pmhserver.name.ng`, so Apache falls back to its default vhost and
  serves the wrong site (or an error). The CNAME points at the right *machine*, but
  the machine is not listening *for that name*.
- ❌ No certificate exists for the name, so `https://` can never complete.

So: **right instinct, one missing step.** Add the domain to cPanel as described
below, and it will work. You do **not** need the CNAME pointing at
`reseller.client.philmorehost.com` — pointing it straight at the server is simpler
and removes a dependency:

- **Preferred:** `A` record, name `reseller`, value = **the server's IP**.
- Or: `CNAME`, name `reseller`, value = `client.philmorehost.com`.

Both work. What matters is the cPanel step.

> One detail worth knowing: the wildcard `*.client.philmorehost.com` you added only
> ever helps **platform subdomains** (`reseller.client.philmorehost.com`). It does
> nothing for `reseller.pmhserver.name.ng`, which lives in a different DNS zone
> (`pmhserver.name.ng`) and needs its own record.

---

## 3. Admin guide — step by step

Work through this once per reseller domain. Roughly five minutes each.

### Automation: what the server side does by itself

Turn this on once per install at **`/admin/resellers/domains` → Automatic
provisioning**, and fill in the three settings below it. After that:

| When | What happens to the hosting panel |
|---|---|
| A reseller submits a domain | nothing — it is only a request |
| You **approve** it | it is added as an addon domain, pointed at the platform's document root |
| You **refuse** it | anything that was on the panel for that store comes off |
| A reseller **replaces** their domain | the OLD name comes off **first**, then the new one goes on once you approve it |
| A reseller **clears** their domain | it comes off |
| The reseller's **account is deleted** | it comes off — the removal runs *before* the account row goes, because that row is the only record of the hostname |

Three things about that table are worth understanding, because they are where the
danger is.

**Removal is verified; addition is not.** After asking cPanel to remove a domain, the
application re-reads the account's addon domains and refuses to report success while
the name is still listed. If the panel's answer cannot be read, the removal is
reported as **unconfirmed**, not as done. The asymmetry is deliberate: a domain that
was never added simply does not serve, and somebody notices within minutes. A domain
that was never *removed* keeps answering for a hostname no store claims — which shows
**this** platform's shop at **this** platform's prices — and can stay that way
indefinitely.

**The old name comes off before the new one goes on.** Removing first cannot lose
anything (the old name is already dead once the claim moves away from it). Adding
first would leave the panel holding two hostnames whenever the second step failed, and
the stale one is the dangerous one.

**Saving the same domain again changes nothing.** Re-submitting an identical name is
not a new request, so it does not drop the store back to *pending*, clear its DNS
proof, or take the domain off the server. Only a genuinely different name does that.

**If provisioning is off**, none of this happens and the application never touches the
panel — including on account deletion. One switch covers both directions on purpose: a
switch that added domains but would not take them away leaves leftovers nobody can
see. Instead, everything that had to be skipped, or that the panel refused, is listed
at `/admin/resellers/domains` under **"Still on the server, but no store should use
it"**, with a **Remove from server** button on each row. Check that list after
turning provisioning off or after any panel outage.

**When the panel refuses, test it rather than guessing.** `/admin/resellers/domains` has a
**Test the hosting panel** button. It asks the panel what it can do and prints the panel's own
words, so run it first whenever an approval reports a problem. It checks: provisioning on/off, the
settings, WHM reachability (the API 1 `version` call), and whether the account's addon domains can
be listed at all — which is the same call an approval makes, so a red row there is exactly why the
approval failed.

Two failures it names directly:

- **`Failed to load module "AddonDomain": Can't locate Cpanel/API/AddonDomain.pm`** — the panel
  does understand the request and simply does not have the modern UAPI AddonDomain module
  installed. The application retries the same operation over the older API 2 automatically. If the
  diagnostic shows *both* versions failing, the domain has to be added in cPanel by hand.
- **Anything that is not a UAPI envelope** — the panel answered with something unexpected. The
  message now quotes what it actually sent, because the wording is the diagnosis; it used to say
  only "unrecognized response shape", which told you nothing.
- **`Document root is where this app runs`** — the check on your own setting, and the one to look at
  *first*. It compares `reseller.cpanel_docroot` with the folder that actually serves this platform
  (the request's own `DOCUMENT_ROOT`), because those two being different is a silent disaster:
  **cPanel creates the document root you hand it**, so a wrong value does not report an error — it
  quietly builds an empty folder, the addon domain is created successfully, and the address then
  answers with cPanel's **Internal Server Error** page instead of the store.

  In practice the right value is usually just **`public_html`**. Read the real one from cPanel →
  Domains → the **Document Root** column for the platform's own domain, relative to the account's
  home (so `/public_html` means the setting is `public_html`).

  Outside the web server (a cron run) the row says it cannot tell rather than guessing.
- **`Domains serve this application`** — a check on *where each addon domain points*. The failure it
  catches looks exactly like success: the domain is created, and the panel parks it on its own
  folder (`public_html/domain.example.com`) instead of the folder holding the application — so the
  address answers with cPanel's own **Internal Server Error** page rather than the store. The check
  compares against your configured document root by **path suffix**, deliberately: that parked path
  *contains* `public_html`, so a substring test would wave it through.

  This is the row to look at when a domain is created and then returns **500 Internal Server
  Error**. If it is red, correct the domain's document root in cPanel → Domains (or delete the
  addon domain, fix `reseller.cpanel_docroot`, and press **Create on server** again) so it points at
  the same folder as the platform.

  A third verdict is possible here, and it is not a pass: if the panel did not report a document
  root, the row says so and names the domains. That means compare them by hand.

**If provisioning fails, the approval is not undone.** The decision stands and the failure is
recorded next to it, and a **Create on server** button appears on that store's row so you can retry
after fixing whatever was wrong. Nothing has to be re-approved, and the retry is the same
reconciliation as an approval — so a previous domain still on the panel comes off first.

**Order matters when DNS is not in place yet.** cPanel generally wants the domain to resolve to the
server before it will accept it as an addon domain, so point the `A` record at the server *before*
approving — or approve, then fix the DNS, then press **Create on server** to retry.

The last two go-live steps (domain pointed here, certificate issued) are still yours; this page only
ever manages the panel side.

**Two names that must not be confused.** `custom_domain` is what a store is *allowed*
to be served on, and it is only actually served there once `domain_verified_at` is
set. What is on the *panel* is tracked separately, which is what makes a replaced
name findable and removable after the claim has moved on.

### Before anything: two settings to confirm once per install

1. **`APP_URL` must be the real platform address.** In `.env`, it should be
   `https://client.philmorehost.com`. This value defines:
   - which host is "the platform" (`www.` and the bare host are never a store);
   - that `reseller.client.philmorehost.com` is a platform subdomain belonging to
     the store whose **slug** is `reseller`;
   - **the CNAME verification target** — a reseller proving control by CNAME must
     point at `client.philmorehost.com` *exactly*, not at their own store subdomain.
2. **Know the document root** that serves `client.philmorehost.com`. In cPanel →
   **Domains**, read the Document Root column for that domain. Every reseller store
   domain must be given **the same** document root. Write it down; you need it below.

### Per reseller

3. **The reseller claims the domain and verifies it** (their step — see §4). You can
   also do both from `/admin/resellers/{clientId}/store`.
4. **Add the domain to cPanel** — *skipped entirely if you turned on automatic
   provisioning above; approving the request does this for you.* Otherwise:
   cPanel → **Domains** → **Create A New Domain**:
   - Domain: `reseller.pmhserver.name.ng`
   - Untick *Share document root* and set **Document Root** to the path from step 2
     (the one serving `client.philmorehost.com`). If the platform's own document
     root already *is* the app's `public/` folder, sharing it is fine too.
   - Save. cPanel now creates the vhost, so Apache answers for that hostname.
5. **Point the DNS.** In the DNS zone for `pmhserver.name.ng` (Cloudflare in your
   case), create the record the reseller must not forget:
   - `A` | name `reseller` | value = server IP | **Proxy status: DNS only (grey cloud)**
   - (or `CNAME` | `reseller` → `client.philmorehost.com`, also grey-clouded)
   - **Grey cloud during setup.** Cloudflare's proxy answers HTTP-01 certificate
     challenges itself, which makes cPanel's AutoSSL fail. Turn it off, issue the
     certificate, then re-enable the proxy if you want it.
6. **Issue the certificate.** cPanel → **SSL/TLS Status** → select the domain →
   **Run AutoSSL**. It needs the record from step 5 resolving to this server. When it
   finishes you have a valid certificate and `https://` works.
7. **Confirm, don't assume.** From any machine:
   ```bash
   dig +short reseller.pmhserver.name.ng
   # expect the server IP

   curl -I -H "Host: reseller.pmhserver.name.ng" http://<server-ip>/
   # expect the WHMP response (200 / 302 to a store path)

   curl -I https://reseller.pmhserver.name.ng/
   # expect 200 and a valid certificate, no -k needed
   ```
   The middle command is the decisive one: if it returns the WHMP app, routing is
   done and only TLS can be outstanding. If it returns cPanel's default page or a
   404, step 4 is not finished.
8. **Nothing further is needed in the application.** The store page's go-live
   checklist tracks the first three steps; the last two are yours and stay marked
   *Ask support* / *manual* on purpose, because the app genuinely cannot see or do
   them. Once the server answers, the store is live — that switch is automatic.
9. **Troubleshooting, if it still does not work:**
   - *Domain shows a certificate warning* → step 6 not done, or done while
     Cloudflare was proxied.
   - *Shows the platform's own shop at our prices* → almost certainly the wrong host
     matched: check the domain on the store page is character-for-character the one
     being requested (`www.` and `https://` excluded), and that it is verified on the
     store you expect — a domain can only be claimed once.
   - **The cPanel "SORRY!" page (`/cgi-sys/defaultwebpage.cgi`) → the request reached
     the server, but no vhost matches that hostname, so Apache served its default
     site. This is the signature of "resolves here, not configured here."** In order
     of likelihood:
     1. **The domain was never added in cPanel.** Adding DNS records alone produces
        exactly this page. Step 4 is the fix. Confirm under cPanel → *Domains* that
        the hostname is listed.
     2. **It was added to the wrong cPanel account.** It must be the account that owns
        the platform's own domain, or it gets its own (empty) vhost.
     3. **It was added as an addon domain with the default document root**
        (`public_html/<domain>`), so it serves an empty folder rather than the app.
        Set its document root to the platform's.
     4. **It resolves to a different IP than this account's.** Compare cPanel →
        *Server Information → Shared IP Address* with `dig +short <domain>`. A host
        can run several accounts with different IPs, each with its own default vhost.
     5. **It was only just added.** Apache may not have picked the vhost up, and your
        browser or resolver may still hold the old answer. Test what the server
        actually does regardless of DNS cache:
        `curl -I -H "Host: <domain>" http://<server-ip>/`.
   - *Nothing resolves* → step 5, or the record is proxied at a different level.
   - *Wrong store appears* → the name is verified on a different store; a domain can
     only be claimed once.

---

## 4. Reseller guide — step by step

Give this to the reseller. It assumes they own the domain and can edit its DNS.

1. **Open your store page** — *Reseller area → Your store* → the custom-domain box.
2. **Type your domain** (e.g. `reseller.pmhserver.name.ng`) and save. The page then
   shows you a **TXT record** to create. Keep it open; you will copy two values.
3. **In your domain's DNS control panel** (Cloudflare, Namecheap, GoDaddy, wherever
   your nameservers are), create:

   | Type | Name | Value |
   |---|---|---|
   | `TXT` | `_codevault-verify.reseller.pmhserver.name.ng` | `codevault-store-verify=<the token shown>` |

   Most providers want you to enter only `_codevault-verify` in the *Name* field and
   add the domain themselves. If yours wants the full name, use the whole string
   shown on the page. Create the record **exactly** as shown — an extra space or a
   missing character fails verification.
4. **Back on the store page, click "Verify domain now"** (or wait — it is rechecked
   nightly). You want *Control of the domain proved* to say **Done**.
   - *Providers cannot serve TXT records?* Ask support; an admin can verify for you.
5. **Point the domain at the platform.** Add a second record in the same place:

   | Type | Name | Value |
   |---|---|---|
   | `A` | `reseller` | *the server IP support gives you* |

   (A `CNAME` to `client.philmorehost.com` also works. Do **not** CNAME it to your own
   `reseller.client.philmorehost.com` store address.)
6. **Tell support you have done both**, and include the domain name. Support has to
   add the domain on the server and issue its security certificate — unless your
   platform has switched on automatic provisioning, in which case the domain is added
   for you as soon as your request is approved and only the certificate is left.
7. **Wait for support to confirm**, then open `https://reseller.pmhserver.name.ng`.
   Your store is live there.

**Why steps 3 and 5 are both needed, in plain terms:** step 3 proves the domain is
yours (so nobody else's shop can be served at your address). Step 5 tells the
internet where your address lives. Support's step then tells *our server* to answer
for your address. All three are needed — the first one alone will not make the site
load, which is the part that surprises people.

**Good to know:**
- **Your store is already live and selling** at
  `https://reseller.client.philmorehost.com` from the moment it is switched on. The
  custom domain is cosmetic. Do not hold up a launch waiting for it.
- **Do not use your main domain** (`pmhserver.name.ng` on its own) unless you really
  want the whole site to be the store — use a subdomain like `reseller.` so your
  normal website keeps working.
- **Changing your domain later** clears the verification, because the proof applied
  to the old name. You will have to repeat step 3 for the new one. **Saving a
  different name also takes the old one off the server immediately**, and the new one
  is only added once an administrator approves it — so your store stops opening at the
  old address the moment you save. If you are only checking what the box says, save it
  unchanged: an identical name changes nothing.
- Removing the domain does not delete your sales, orders or customers.

---

## 5. What the application can and cannot do

Worth stating plainly, because the go-live checklist marks the last two steps
*manual* rather than pretending:

- It **can** prove control of a domain — that is DNS lookups, which PHP can do — and
  it **does** serve the right store once a verified hostname reaches it.
- It **can** add and remove a domain on the hosting panel, through the same WHM API
  token this platform already uses for provisioning and its cPanel tools. That is
  **off by default**, because it edits a hosting account on somebody else's behalf;
  see §3.
- It **cannot** issue a TLS certificate. That is the web server's job — ask cPanel's
  AutoSSL to run after the domain has been added.
- It **cannot** confirm that a certificate exists or that DNS resolves *from outside*.
  Those are the two steps that stay *Ask support* / *manual* on the checklist on
  purpose: a process running on this server, observing itself, is not evidence about
  the internet.

The alternative to the panel step is an edge proxy (Cloudflare for SaaS, or a wildcard
reverse proxy) that terminates TLS for reseller domains and forwards with the original
Host — then no per-domain cPanel change is needed at all, at the cost of running that
proxy, and TLS stops being a manual step too. That is a deliberate infrastructure
decision, and the automation above is not a substitute for it: taking a domain off the
panel does not take it out of a proxy's configuration.
