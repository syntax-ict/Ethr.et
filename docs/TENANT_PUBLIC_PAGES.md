# Tenant Public Landing Pages

A public, unauthenticated page for each tenant at `{subdomain}.ethr.et`, so an
organisation can point the public at something that describes it and offers its
employees a way in — without exposing any HR data.

Opt-in. Shipping this published nothing: every tenant starts with no profile
row, `is_published` defaults to `false`, and a tenant that has not opted in
answers exactly as it did before.

---

## What it is built on

Almost nothing here is new machinery. Hostname tenancy was already implemented
and hardened before this feature existed, and it is reused as-is:

| Reused | Where |
| --- | --- |
| Host → tenant resolution | `api/app/Http/Middleware/ResolveTenant.php` |
| Apex/tenant discrimination (`APP_DOMAIN`) | `api/app/Support/TenancyDomain.php` |
| Reserved hostnames | `Tenant::RESERVED_SUBDOMAINS` |
| Fail-closed tenant scope | `api/app/Traits/BelongsToTenant.php` |
| Tenant-prefixed storage, EXIF stripping, MIME allow-list | `api/app/Services/FileStorageService.php` |
| Settings authorization | `Gate::authorize('settings.manage')` |

**No new tenant selector was introduced.** The public page has no header, query
parameter or body field that chooses a tenant — only the hostname does, exactly
as for every other surface.

---

## Request flow

```
GET https://abcschool.ethr.et/
  │
  ├─ web server: `location = /` on the tenant vhost → Laravel, not Next.js
  │
  ├─ PublicSecurityHeaders   HTML-appropriate CSP (see "Security headers")
  ├─ RenderPublicErrorPage   any failure → one branded page, one wording
  ├─ ResolveTenant           hostname → Tenant, or 404/403 (unchanged)
  ├─ SetPublicLocale         ?lang → tenant default_locale → Accept-Language
  └─ throttle:public-page    60/min per host+IP
       │
       └─ TenantLandingController
            ├─ not a tenant host → `welcome` view, 200 (as before this feature)
            ├─ no published profile → 404
            └─ published → PublicTenantPage → Blade
```

`routes/public.php` is a separate route file with its own middleware group. It
carries **no** `auth:sanctum`, `AuthenticateFromCookie`, `statefulApi()` or
`EnsureUserBelongsToTenant`, so there is no route by which an anonymous request
reaches authenticated tenant logic.

---

## Rendered by Laravel, not Next.js

The landing page is Blade. That is a deliberate departure from the rest of the
UI, for one reason: the frontend is `output: "standalone"` and needs a resident
Node process, and whether Ethio Telecom's Plesk account provides one is gate
**B5/G0-G**, still unverified — see
[`deployment/GATE-0-RESULT.md`](deployment/GATE-0-RESULT.md). The static-export
fallback is already documented to cost the marketing pages their server-side
rendering, and with it their SEO.

A page whose entire purpose is to be found and indexed must not be hostage to an
unanswered hosting question. Blade renders identically in both branches, needs
no build step, and gives real per-tenant `<title>`, meta description, canonical,
Open Graph and JSON-LD.

The cost, stated plainly: the page does not reuse the React component library,
so a small amount of presentation exists twice. It is bounded to one page and
one stylesheet (`api/public/assets/ethr-public.css`), which restates the design
tokens rather than the components. Do not grow it into a second design system —
if the public surface ever needs components, that is the signal to revisit the
rendering decision.

---

## What the public page can see

`App\Support\PublicTenantPage` is an allow-list. The Blade view receives one of
those and never a `Tenant` or a `TenantPublicProfile`, so a template cannot
render a field that was not deliberately exposed.

**Readable:** tenant `name`, `subdomain`, `type`, `theme` (three hex colours),
and every column of `tenant_public_profiles`.

**Not readable, ever:** `tenants.settings` (it carries `login_identifiers`),
any relation on `Tenant`, and every other model. Numeric primary keys are never
emitted (convention 4).

`tests/Feature/Public/TenantPublicBoundaryTest.php` seeds a tenant with
employees, salaries and national IDs and asserts none of it appears in the
rendered HTML. It tests the output rather than the view-model on purpose: a test
of `PublicTenantPage` would prove that class is careful; only a test of the
output proves the template did not reach around it.

---

## Security headers

`SecurityHeaders` sends `default-src 'none'`, which is right for a JSON API and
blanks an HTML page. It was **not** widened. The public group has its own
`PublicSecurityHeaders` with `img-src`/`style-src`/`font-src 'self'`, no
`script-src` (the page ships no JavaScript), and one named allowance —
`style-src-attr 'unsafe-inline'` — for the tenant brand colours on `<body>`.

`PublicSecurityHeadersTest` asserts `/api/v1/*` still carries the original
policy. If that test fails, someone traded the security of every API response
for the styling of one page.

---

## Failure states

Every failure looks identical from outside: same page, same wording, and
**404 rather than 403** for a tenant that exists but has not published.

403 would be the honest status, and that honesty is the problem — a 403 on
`acme.ethr.et` beside a 404 on `notacustomer.ethr.et` lets an anonymous visitor
enumerate which organisations use ETHR, one hostname at a time.

A pre-existing caveat worth recording rather than discovering later: tenant
*existence* is already enumerable through `ResolveTenant`, which answers 404 for
an unknown subdomain and 403 for an inactive one on every surface. This feature
does not worsen that; it keeps *publication status* private.

---

## Images

`GET /media/logo` and `GET /media/hero` on the tenant host.

The route takes a **kind** from a two-item list and never a path, an id or a
filename — that is the whole traversal and IDOR defence, since there is no
attacker-controlled component to sanitise. The path comes from the database,
and `App\Support\TenantPublicAsset` requires it to sit under that tenant's own
`tenants/{public_id}/` prefix.

No `public` disk and no `storage` symlink: `symlink()` availability on the
target host is unverified, and a world-readable directory next to private
employee documents is the wrong default for this product. Files are streamed
through PHP with a `Content-Type` from a fixed map (never sniffed), an ETag over
path + mtime, and `Cache-Control: public`.

**Legacy logos.** `PUT /settings/branding` has always accepted `logo_url` as a
bare `string|max:500`, so `tenants.logo_path` may hold an arbitrary external
URL. Rendering one publicly would make every anonymous visitor issue a request
to a third-party host. Such a value is treated as *no logo*; the settings screen
says so and asks for an upload. `POST /settings/branding/logo` is the new,
real upload path.

---

## Caching

The HTML is `private, no-store`. The same path renders a different document on
every tenant hostname, and a shared cache that keys on path alone would serve
one tenant's page to another's visitors — a cross-tenant leak arriving through
infrastructure rather than code. The page is one cheap query; the risk is not
worth the milliseconds.

`/media/*` is `public, max-age=604800`, which is safe because an image URL
carries the tenant hostname.

---

## Administration

Settings → **Public Page** (`/settings/public-page`), behind the existing
`settings.manage` gate.

The publish switch is the only control with a consequence outside the
application, so it is separated from the rest: its own card, wording that says
what turning it on means, and it saves on its own. A content save never sends
`is_published`, so fixing a typo cannot publish an organisation to the internet.

Publication and unpublication are recorded as their own audit events
(`settings.public_page_published` / `_unpublished`) rather than folded into a
generic update, so "when did this become public, and who decided that" is
answerable without diffing.

---

## Deployment

**TLS is a prerequisite of publishing, not a follow-up.** Wildcard issuance is
blocked (it needs DNS-01 and the zone is on `ns2.telecom.net.et`, not Plesk);
per-hostname HTTP-01 is proven on the account. Because `SecurityHeaders` already
sends HSTS with `includeSubDomains; preload`, any browser that has seen
`ethr.et` will refuse plain HTTP on a tenant host — so a published tenant
without a certificate is *unreachable*, not merely insecure.

Let's Encrypt allows **50 certificates per registered domain per week**. That is
a hard ceiling on the rate at which tenants can be published, and it belongs in
the rollout plan.

Per-tenant publish checklist:

1. Confirm the wildcard vhost exists (gate B1b — accepted by the panel, not yet
   created).
2. Issue the HTTP-01 certificate for `{subdomain}.ethr.et`.
3. Verify `https://{subdomain}.ethr.et/` renders and `/login` still works.
4. Only then turn on the publish switch.

Routing is one rule per fronting server, in the `*.ethr.et` block only:
`infrastructure/nginx.conf` (production) and `docker/nginx/default.conf` (local
and E2E). The Plesk `.htaccess` equivalent is **not written yet** — that
directory stays fenced until gate G0-B answers whether `.htaccess` is honoured
at all. See [`deployment/GATE-0-RESULT.md`](deployment/GATE-0-RESULT.md).

---

## Local development

```bash
docker compose -f docker-compose.yml -f docker-compose.override.yml \
               -f docker-compose.hostnames.yml up -d --build
```

Then `http://demo.localhost:8888/`.

**Port 8888, not 3000.** 8888 is nginx; 3000 is the Next dev server directly and
bypasses nginx entirely, so the landing page does not exist there. The docker
nginx tenant block matches both `*.ethr.test` and `*.localhost` so either local
hostname model works.

---

## Translations

Blade strings live in `api/lang/en/public.php` and `api/lang/am/public.php`.

`scripts/i18n-check.js` — the i18n gate — scans `src/` only and does not read
`api/lang/`, so these two files have no coverage from it. Laravel renders a
missing key literally rather than failing, which on a public page means a
visitor sees `public.contact_heading`. `tests/Feature/Public/PublicLangParityTest.php`
is what closes that gap: key parity, untranslated-value detection, and
placeholder consistency.

---

## Tests

| File | What it pins |
| --- | --- |
| `Feature/Public/TenantLandingPageTest.php` | Which hosts serve a page and which do not; every failure identical |
| `Feature/Public/TenantPublicBoundaryTest.php` | No employee, payroll, settings or PK data in the output |
| `Feature/Public/TenantPublicAssetTest.php` | Traversal, cross-tenant, unpublished branding, legacy URLs, type map |
| `Feature/Public/TenantPublicXssTest.php` | Escaping, URL schemes, social host allow-list, and "no unescaped echo in any public view" |
| `Feature/Public/PublicSecurityHeadersTest.php` | The public CSP, and that the API's was not widened |
| `Feature/Public/PublicPageLocaleTest.php` | `?lang` → tenant default → browser |
| `Feature/Public/PublicLangParityTest.php` | en/am parity for the Blade strings |
| `Feature/Security/ReservedSubdomainParityTest.php` | Backend and frontend reserved lists agree |
| `Feature/Settings/PublicPageSettingsTest.php` | Authorization, validation, audit events, upload rules |
| `src/test/settings-public-page.test.tsx` | A content save cannot publish; the switch can |

Zero `withoutGlobalScope` call sites were added under `app/`, so
`tests/Feature/Security/tenant-scope-bypasses.php` is unchanged.

---

## Not built

Deliberately out of scope for this phase, and none of it foreclosed:

- **Custom domains.** `tenants.custom_domain` exists, is unique, and has zero
  read sites. The extension point is one branch in `ResolveTenant::lookupTenant()`.
  It needs per-domain TLS and a verification flow first.
- **Public announcements.** The `announcements` table has no public visibility
  flag and every existing row is internal. Adding a public path to it is the
  highest-risk way to leak private HR content; a separate, deliberately-public
  content type is the right shape if this is wanted.
- **Configurable sections, multiple templates, a page builder.** One template,
  one profile row. Extensible without rework.
- **Sitemaps and a tenant directory.** A platform-wide index of tenants is an
  unauthorised-discovery surface, not an SEO feature.
