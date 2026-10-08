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
>
> **AMENDED BY THE OWNER, 2026-10-08: one canonical address per organisation, in three tiers.**
> Standard is the subdomain, Enterprise a *verified* custom domain sold as a paid add-on, and
> `ethr.et/{slug}` is a fallback that only redirects. See
> [*Amendment: one canonical address*](#amendment-2026-10-08-one-canonical-address-in-three-tiers)
> below; where it and the 2026-10-06 text differ, the amendment wins.

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
  as for an active one; the sign-in page explains the state. *(Since 2026-10-08 it redirects
  to the organisation's canonical address first, and to `/login?org=` only when it has none —
  see the amendment.)*
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
  *(Since 2026-10-08 it also needs the `custom_domain` plan feature, and stores the domain
  pending until it is verified — see the amendment.)*

### Links ETHR hands out

`FrontendUrl::forTenant()` builds, in order of preference:

1. the custom domain, when one is assigned *(verified, since 2026-10-08: a pending domain
   builds no link)*;
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
| ~~Assignment alone made a domain resolve, with nothing to show the organisation held it or that it pointed here~~ | **Closed 2026-10-08**: TXT-token and CNAME verification, see the amendment |
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

## Amendment 2026-10-08: one canonical address, in three tiers

### The instruction, as given

> Owner decision: one canonical address per organisation in three tiers. Standard is the
> subdomain `acme.ethr.et`. Enterprise is a verified custom domain (paid add-on). Fallback is
> `ethr.et/acme`, which only redirects.

It came with three things to build: the canonical redirect, custom-domain verification, and
a `custom_domain` plan feature gating assignment. It also named one thing this does **not**
do. Switching subdomains on as the default (`TENANCY_SUBDOMAINS=true` plus
`NEXT_PUBLIC_ROOT_DOMAIN`) still waits on Ethio Telecom serving `*.ethr.et` (M3), and is a
configuration change after that host work.

### The canonical address

`FrontendUrl::canonicalOrigin()` is the one place that decides it:

| Tier | Address | When |
|---|---|---|
| Enterprise | `https://hr.acme.com` | A custom domain is assigned **and verified** |
| Standard | `https://acme.ethr.et` (the frontend's scheme and port) | `TENANCY_SUBDOMAINS=true` and `APP_DOMAIN` set |
| Fallback | none: the shared host | Neither of the above |

Three things now send an organisation there:

- **The entry URL.** `ethr.et/{slug}` redirects to `{canonical}/login`, and to
  `/login?org={slug}` only when the organisation has no canonical address. It is still a
  302 or a 404, with no session written.
- **A sign-in on a host this deployment owns that is not the canonical one.** That means the
  apex, `www`, or the organisation's own subdomain while a verified custom domain sits above
  it. There `POST /auth/login` answers **409 `canonical-address`** with `canonical_url`,
  *before the password is checked*, and the sign-in page follows it. The session cookie is
  host-only, so a session started on the apex would not exist at the address every e-mailed
  link points to. A host the deployment does not own (localhost behind a dev proxy, an IP)
  never hands off: it cannot be the canonical address, so handing off from it would loop.
- **Every link ETHR e-mails**, through `FrontendUrl::forTenant()`, as before.

**What it does not do:** it does not refuse `X-Tenant` on the apex for an organisation that
has a canonical address. Someone already signed in on `ethr.et` when the organisation's domain
is verified keeps working until they sign out, and their next sign-in goes to the canonical
address. Refusing the header would sign everyone out at the moment of verification, and would
add nothing `EnsureUserBelongsToTenant` does not already enforce.

### Verifying a custom domain

- **Assignment stores the domain pending**, with a fresh 32-hex token in
  `tenants.custom_domain_token`, which is hidden from serialisation. Tenant's `saving` hook
  issues it, so *any* change of domain (console, tinker, a seeder) starts verification over,
  and no domain can inherit another's. A save that sets `custom_domain_verified_at` in the
  same write is left alone.
- **A pending domain is no address.** `ResolveTenant` resolves only rows with
  `custom_domain_verified_at` set, and `canonicalOrigin()` ignores a pending domain. No link,
  redirect or sign-in hand-off uses it.
- **Two records, both required.** `CustomDomainVerifier` checks them through a `DnsResolver`
  contract; `SystemDnsResolver` wraps `dns_get_record()`.
  - `TXT _ethr-verification.{domain}` = `ethr-verification={token}` proves the organisation
    controls the zone. A record left from an earlier assignment carries an old token and
    fails.
  - `CNAME {domain}` → `TENANCY_CUSTOM_DOMAIN_TARGET`, or `APP_DOMAIN` when that is unset,
    proves the domain reaches this platform. A CNAME cannot sit on a zone apex, so an
    organisation brings a subdomain such as `hr.acme.com`, not `acme.com`. A DNS provider
    that flattens CNAMEs, such as a proxied record, will not pass.
- **The console's Verify button** calls `POST /admin/tenants/{publicId}/domain/verify`, which
  needs MFA like every console write and checks both records. On success it sets
  `custom_domain_verified_at` and audits `admin.tenant.domain_verified`. The domain resolves
  on the very next request, because saving the tenant forgets the resolver's cache. On
  failure it answers 422 with one message per missing record, under `errors.txt` and
  `errors.cname`, and the console lists them under the records. While the domain is pending,
  the tenant page shows the two records to send the organisation.
- **Verify never revokes.** On a domain that is already verified it returns the current state
  without looking anything up. A DNS hiccup at the moment someone presses a button must not
  take an organisation's address away. Changing or clearing the domain is what ends a
  verification.
- **A domain assigned before this change is not grandfathered.** The migration gives it a
  token and leaves it pending. Production had no organisations when this shipped.

### The `custom_domain` plan feature

- `PlanFeature::CustomDomain` is a **paid add-on**. `PlanFeatureService::allows()` grants it
  only when the plan's feature list names it. Every other feature fails open (a trial gets
  everything, and no plan or a plan with no list means unlimited) so that a tenant never
  loses what it already uses. An add-on is granted, never already in use, so none of that
  applies: a trial, a tenant with no subscription and a plan with no list all read *no*.
- `PlanSeeder` puts it on **Enterprise only**. The seeder uses `firstOrCreate`, so a
  deployment whose plans were already seeded would never gain it. The migration
  `2026_10_08_000002_add_custom_domain_to_enterprise_plan` closes that gap. It adds the
  feature to the plan with slug `enterprise` only, and only when that plan has a feature list:
  a NULL list means *unlimited* to every other feature, and writing one entry into it would
  take those away. It is idempotent, and `down()` removes it again. Any other plan gets the
  add-on through the console's plan editor, which now lists it. *(Delegated to the agent by
  the owner, "decide for me", 2026-10-08.)*
- **It gates assignment and nothing else.** `PUT …/domain` with a domain needs it, and
  answers 422 under `custom_domain` otherwise. Clearing never does. Resolution is not gated:
  an organisation moved to a plan without the add-on keeps a domain it already has until an
  admin clears it.
- It is left out of `/auth/me`'s `plan_features`, which lists only what the tenant's own
  screens gate on, so a trial still reads `null` there. The console's tenant detail reports
  `custom_domain_allowed`; when it is false the dialog says so and disables Save.
- The console reads the plan scope-free through one helper,
  `AdminTenantController::latestSubscription()`, which `show()` already used. On the platform
  host no tenant is resolved, so `$tenant->subscription` answers null through the fail-closed
  scope, and every plan would read as absent. The tenant-scope bypass inventory is unchanged.

### Not advertised yet

`/pricing` does not mention the custom domain. Enterprise's written marketing lines are
unchanged, and they are what the page shows. This was delegated to the agent by the owner
("decide for me", 2026-10-08), and the call is to wait. Two things a sold custom domain
depends on have never been seen working on this host: adding an organisation's domain to the
Plesk site with a certificate, and `dns_get_record()` reaching DNS from PHP. Advertising a
paid feature that might not work on the first try is worse than advertising it a release
late. **Add it to the Enterprise lines once the first real domain verifies and serves in
production.** The label `marketing.pricing_page.feature_custom_domain` already exists for
when it does.

### Still open

| Gap | What it needs |
|---|---|
| **Subdomains as the default** | M3: Ethio Telecom serving `*.ethr.et` with a vhost. Then `TENANCY_SUBDOMAINS=true` and `NEXT_PUBLIC_ROOT_DOMAIN=ethr.et`. Host action, then configuration |
| **Per-domain host work** for every Enterprise organisation: the domain added in Plesk to the same site, and a certificate for it. Verification proves DNS, not that Plesk answers for the name | Host action, owner's, per organisation |
| ~~No periodic re-check. A verified domain stays verified if the organisation later removes the records or repoints the name~~ | **Closed 2026-10-08, report-only** (delegated to the agent, "decide for me"). `tenancy:recheck-custom-domains` runs Mondays at 03:00 UTC. For each verified domain that no longer checks out it writes `platform.tenant.domain_check_failed` to the platform audit log, with the failed checks, and a log warning. It **never revokes**: one DNS hiccup must not take an organisation's address away, so a person decides. `CustomDomainRecheckTest`, 4 cases, all failing before the command existed. Like every scheduled task it runs only when the GitHub Actions caller hits `/cron/schedule` |
| **`dns_get_record()` on the production host is unmeasured.** If the host blocks outbound DNS or the function, every Verify fails with both records reported missing | Press Verify once on a known-good domain after deploying |
| The local production rehearsal has not been run over this change | `scripts/local-production/up.sh` without `--no-build` |

### Verification of the amendment

Each new case below was run against the code before this change, and **failed there**:

- `CanonicalTenantAddressTest`, 13 cases. The entry URL goes to a verified custom domain and
  to the subdomain, and to `/login?org=` only with neither. An apex sign-in is handed to the
  custom domain and to the subdomain, with nothing authenticated, and the subdomain is handed
  to the custom domain above it. Sign-in proceeds at the canonical host itself, on the apex
  with no canonical address, and on a host the deployment does not own. A pending domain
  resolves nothing and builds no link. The token resets on a change of domain and survives
  any other save. With the new schema but the old application code, the cases fail on
  assertions, not on missing columns.
- `AdminTenantDomainTest`, 34 cases (was 19): pending storage with the DNS records; a dedicated
  CNAME target; no resolution before Verify; the plan gate for a plan without the add-on, for
  a trial and for no plan; clearing on any plan; Verify succeeding and resolving past a cached
  miss, failing per record, refusing a stale token, leaving a verified domain alone, and with
  nothing assigned; the new detail and list fields; the token never serialised; and a tenant
  admin refused on both endpoints.
- `EnterpriseCustomDomainMigrationTest`, 5 cases, every one failing before the migration
  existed: the add-on reaches an already-seeded Enterprise plan; a second run changes
  nothing; no other plan is touched; a NULL list stays NULL; and `down()` removes it.
- `PlanFeatureGateTest`, 5 new cases: the add-on allowed only when named, and refused to a
  trial, to no subscription and to a null list; Enterprise the only seeded plan with it; and
  `/auth/me` still `null` for a trial.
- `PathAndCustomDomainTenancyTest`: its custom-domain fixtures are now verified domains.
  Nothing else changed, and all 20 pass.
- `admin-tenant-domain.test.tsx`, 6 new cases: the shared address printed rather than a pending
  domain; the pending badge with both records; Verify posting; each failed record listed; no
  Verify once verified; and the plan note with Save disabled.
- `login-canonical-address.test.tsx`, 2 cases: the form follows `canonical_url`, and never one
  that is not http(s).
- Gates on 2026-10-08: `gates.sh quick` all green; `gates.sh backend` with PHPStan clean and
  Pest 2683 passed, 236/236 classes collected; Vitest 975 passed in 175 files; the API
  contract in sync. The one failure the first full run found was real:
  `PricingSnapshotFreshnessTest`, because Enterprise gained `custom_domain` in `PlanSeeder`
  and the committed `/pricing` snapshot had not. The snapshot is updated. Enterprise's written
  marketing lines are unchanged, so `/pricing` does not advertise the add-on: that copy is the
  owner's call.
- Driven in a browser against the local stack, on a tenant given a pending domain. The header
  printed `ethr.et/demo`, not the pending domain, beside a *Pending verification* badge, and
  the card listed the TXT record with the live token. The CNAME target read "—", because the
  dev install sets no `APP_DOMAIN`, where custom domains never resolve anyway. Verify reached
  the endpoint and, for a dev super admin without MFA, showed the server's 403 reason.
