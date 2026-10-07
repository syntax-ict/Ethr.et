# Tenancy without wildcard subdomains: `ethr.et/{slug}` and custom domains

> **OWNER DECIDED, 2026-10-06. The design was delegated.** An organisation must be reachable
> **without a subdomain**, at `ethr.et/{its name}`, and an organisation that **already has a
> domain of its own** must be able to use it. The owner left the design to the agent ("decide
> for me"); everything under *What was decided* is that delegated design, implemented in
> [syntax-ict/Ethr.et#166](https://github.com/syntax-ict/Ethr.et/pull/166).
>
> Subdomains are **not withdrawn**. `acme.ethr.et` still resolves its tenant wherever the host
> serves it, and one switch (`TENANCY_SUBDOMAINS=true`) makes ETHR hand those addresses out
> again once **M3** passes.

## The instruction, as given

Recorded verbatim, typos included, from the session of 2026-10-06 18:57 UTC:

> the system must handle tent isolation with out subdomain like ethr.et/tent name and to those
> has domain already professionally, decide for me for better results.

"tent" is *tenant*. There was no other instruction on the subject in that session. This
document was written on 2026-10-07, after the code: PR #166's test cited it before it existed,
and the session transcript is the source for the quote.

## What forced it

**M3 is not met, and nothing on the account will meet it soon.**
[`CUTOVER-CHECKLIST.md`](../deployment/CUTOVER-CHECKLIST.md) records DNS verified and a
`*.ethr.et` certificate in existence, but **no vhost**: every tenant host answers with a
redirect to the Plesk login. Every flow that assumed `{tenant}.ethr.et` had nowhere to go.

**It was also a live defect, not only a missing feature.** `src/.env.production` sets no
`NEXT_PUBLIC_ROOT_DOMAIN`, so the deployed frontend runs in single-host mode and names its
tenant in `X-Tenant`. `ResolveTenant` refused that header outside `local`/`testing`. Sign-in
worked, because login carries the organisation in its form body. Every request after it
resolved no tenant, the fail-closed scope returned no rows, and **every organisation saw empty
pages**. Activation and "find my organisation" e-mails linked to subdomains that did not
answer.

## What was decided

### Three selectors, one order

| Order | Selector | Honoured on | Example |
|---|---|---|---|
| 0 | **Custom domain** the platform assigned to one tenant | That host only | `hr.acme.com` |
| 1 | **Subdomain** | `{tenant}.<APP_DOMAIN>` | `acme.ethr.et` |
| 2 | **`X-Tenant` header** | The apex, its `www` alias, and single-host installs with no `APP_DOMAIN` | `ethr.et` + `X-Tenant: acme` |
| 3 | Organisation in the form body | Login, register and password endpoints only | Unchanged |

The host always answers first. A custom domain or a subdomain wins over a header naming
someone else. The platform host (`admin.ethr.et`) resolves no tenant at all, header or not.
**A host the deployment does not own gets no selector**, so someone pointing their own DNS at
the account cannot choose a tenant with the header.

### The entry URL: `ethr.et/{slug}`

- `OrganisationEntryController` redirects a real organisation to `/login?org={slug}`. Anything
  else gets `404.html` with a real 404. The answer is the same for an inactive organisation
  as for an active one; the sign-in page explains the state.
- The login form prefills the organisation from `?org=` and **stores nothing until sign-in**.
  Then a crafted link cannot change the `X-Tenant` of a browser that is already signed in.
- `.htaccess` group 2b sends one lower-case segment, with no dot and no file, directory or
  exported page behind it, to `index.php`. Without that rule the path never reached Laravel.
- The route runs **without the `web` middleware group**. Every unknown single-segment path now
  reaches it, scanners included, and with `SESSION_DRIVER=database` each hit would otherwise
  write a session row against the hosting quota.
- **Every top-level frontend route and locale is a reserved slug.** The entry URL shares one
  namespace with the frontend's own pages, so a tenant called `dashboard` would shadow the
  dashboard. `PathAndCustomDomainTenancyTest` reads `src/src/app`, so a new page cannot be
  added without reserving its name.

### Custom domains

- The value is normalised on write: lower case, with scheme, port, path and trailing dot
  removed. A domain typed as `https://HR.acme.com/` would otherwise silently never match.
- Lookups are cached for five minutes, and saving a tenant forgets both the old and the new
  domain, so a domain that is moved or removed stops resolving at once.
- A resolved custom domain is added to Sanctum's first-party list **for that request**.
  `SANCTUM_STATEFUL_DOMAINS` is static configuration and cannot name domains assigned at
  runtime; without this, sign-in on `hr.acme.com` answers 200 and the next request 401.
- Session cookies stay host-only (`SESSION_DOMAIN` is empty), which is what lets a custom
  domain hold its own session at all.
- **A platform admin assigns it** from the tenant's page in the console, through
  `PUT /api/v1/admin/tenants/{publicId}/domain` (added 2026-10-07). `null` clears it, and the
  field is required, so an empty body cannot remove a domain by accident. The request is
  normalised before it is validated, so uniqueness is checked against the stored spelling.
  It refuses a domain another organisation holds, anything that is not a hostname, and any
  host under `APP_DOMAIN`: those never reach the custom-domain resolver, but `FrontendUrl`
  would still put them in every link. Each change is audited as `admin.tenant.domain_changed`
  with the old and new value. Like every console write, it needs MFA on the admin's account.

### Links ETHR hands out

`FrontendUrl::forTenant()` builds, in order of preference:

1. the custom domain, when one is assigned;
2. the subdomain, when `TENANCY_SUBDOMAINS=true`;
3. otherwise the shared host, where the sign-in link is the entry URL, `ethr.et/{slug}`.

`TENANCY_SUBDOMAINS` defaults to **false** (`config/tenancy.php`). It decides only which URL
ETHR hands out; resolution accepts a subdomain either way.

## Why it is safe

**The header was refused for a reason, and that reason did not survive scrutiny.** It was
refused because the tenant should be chosen by the host and not by the client. But the host is
client-chosen too: anyone can type `acme.ethr.et`, so the subdomain was never a secret. What
stops a leaked or borrowed token from reading another tenant is `EnsureUserBelongsToTenant`,
which compares the resolved tenant with the authenticated user's own, and it applies to the
header exactly as it applies to the host. A member naming another organisation on the apex
gets **403 `tenant-mismatch`**, which is pinned in `PathAndCustomDomainTenancyTest`.

Everything else is unchanged: the fail-closed `BelongsToTenant` scope, and the tenant-scope
bypass inventory. No `withoutGlobalScope` was added, and `Tenant` is on the Global Model List.

## What it costs

- **One organisation per browser on the apex.** Every organisation on `ethr.et` shares one
  host, so they share one session cookie and one `localStorage`. Signing into a second
  organisation in the same browser replaces the first. Subdomains and custom domains do not
  have this limit, which is the strongest reason to finish M3.
- **The entry URL reveals which organisations exist.** A real slug answers 302 and anything
  else 404. Subdomain resolution already revealed the same, so nothing new is exposed.
- **Unknown paths now boot Laravel.** Every lower-case single-segment path with nothing behind
  it costs one PHP request and one indexed query, where it used to be a static 404. No session
  is written.
- **62 names are reserved** and can no longer be taken by an organisation (`dashboard`,
  `login`, `en`, `am`, …). Production has no tenants yet, so none was displaced.

## Not done yet

| Gap | What it needs |
|---|---|
| ~~No way to assign a custom domain except the database~~ | **Closed 2026-10-07**: the console field and endpoint described under *Custom domains* |
| **A custom domain needs host work** that has not been measured on this account: DNS at the organisation's registrar, the domain added in Plesk to the same site, and a certificate for it | Per organisation: host action, owner's |
| ~~`TENANCY_SUBDOMAINS` appears in neither env example~~ | **Closed 2026-10-07**: `false` in both, documented, and in `deployment/shared-hosting/ENVIRONMENT.md`. `HostingRequirementsConsistencyTest` pins it in the shared-hosting template's key set |
| The local production rehearsal (`scripts/local-production/up.sh`) has not been run over this change | Rehearse before deploying; `--no-build` reuses an old release tree and cannot test it |

## What would reverse it

**Nothing needs reversing when M3 passes.** Set `TENANCY_SUBDOMAINS=true` once a tenant
subdomain actually answers with the application. From then on, e-mails link to
`{tenant}.ethr.et`, and the entry URL and the apex header keep working for anyone holding an
old link. Withdrawing path tenancy entirely would mean deleting the entry route, the
`.htaccess` group 2b and the apex branch of `resolveFromHeader()`. Do that only once no link
built on `ethr.et/{slug}` is still in anyone's inbox.

## Verification

- `PathAndCustomDomainTenancyTest`, 20 cases: header on the apex and `www`, ignored on a
  subdomain, a foreign host and the platform host, 403 on another organisation, custom-domain
  resolution with precedence and normalisation, Sanctum first-party, the entry URL with
  redirect, 404 and reserved names, no session on the entry URL, mail links in all three
  modes, and the reserved-slug sweep.
- `SharedHostingHtaccessTest` pins group 2b: its three guards, its position after the exported
  pages and the `/admin` boundary, and its pattern equal to the route's own constraint.
- `login-org-entry.test.tsx`, 4 cases: the prefill, precedence over the stored organisation,
  nothing stored on load, and a non-slug ignored.
- **Real Apache 2.4.58** serving the rendered `.htaccess` over the real export, 15/15:
  `/acme`, `/acme/` and unknown slugs reach Laravel. `/login`, `/dashboard`, `/en` and `/`
  are served from the export. `/Acme`, `/wp-login.php`, `/a/b` and `/_next` get 404, `/admin`
  on a tenant host gets 403, and `/api/*` is unchanged.
- Gates on 2026-10-07: `gates.sh quick` all green; `gates.sh backend` with PHPStan clean and
  Pest 2565 passed, 232/232 classes collected.
- The console field, added the same day:
  - `AdminTenantDomainTest`, 19 cases: normalised storage and the audit row; resolution on
    the very next request past a cached miss; clearing with `null`; the required field;
    another organisation's domain in any spelling; hosts under `APP_DOMAIN`; non-hostnames;
    the field on the detail and list responses; and a tenant admin refused.
  - `admin-tenant-domain.test.tsx`, 5 cases: the empty state, save, the server's 422 shown
    under the field, removal sending `null`, and no Remove button when nothing is assigned.
  - Driven in a browser against the local stack. The PUT reached the endpoint and, for a dev
    super admin without MFA, came back 403 with the server's reason shown in the dialog.
