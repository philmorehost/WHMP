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
4. **Add the domain to cPanel.** cPanel → **Domains** → **Create A New Domain**:
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
   - *Shows the platform's own shop at our prices* → that is a **suspended** store, by
     design (503 would be shown for a suspended one; if you see our shop, the domain
     is probably not the one that verified — check it matches exactly, no `www.`).
   - *Shows cPanel's default page* → step 4 missing or wrong document root.
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
6. **Tell support you have done both**, and include the domain name. The last two
   things are not something your portal can do — support has to add the domain on the
   server and issue its security certificate.
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
  to the old name. You will have to repeat step 3 for the new one.
- Removing the domain does not delete your sales, orders or customers.

---

## 5. Why the application cannot do this part

Worth stating plainly, because the checklist says *manual* rather than pretending:

- It **cannot** add a domain to cPanel — that is a hosting-panel action, outside the
  application, and cPanel has no supported API for it that we can call safely from a
  request.
- It **cannot** issue a TLS certificate — that is the web server's/edge's job.
- It **can** prove control of a domain (that is DNS lookups, which PHP can do), and
  it **does** serve the right store once a verified hostname reaches it. That is the
  whole of its responsibility, and it is tested.

If you ever want the panel step automated, the honest route is an edge proxy
(Cloudflare for SaaS / a wildcard reverse proxy) that terminates TLS for reseller
domains and forwards with the original Host — then no per-domain cPanel change is
needed, at the cost of running that proxy. That is a deliberate infrastructure
decision, not something this application can quietly assume.
