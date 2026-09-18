# Tenant Public Landing Pages

A public, unauthenticated page for each tenant at `{subdomain}.ethr.et`, so an
organisation can point the public at something that describes it and offers its
employees a way in — without exposing any HR data.

Opt-in. Shipping this published nothing: every tenant starts with no profile
row, `is_published` defaults to `false`, and a tenant that has not opted in
answers exactly as it did before.

The page is **organisation-aware**: a ministry does not get the same layout,
or the same structured data, as a hotel. It is also **composable** — an
administrator arranges the sections their organisation actually needs. Both are
described below, along with the one rule that governs all of it: every word and
number on the page was typed by an administrator, and nothing is derived from
an HR table.

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

Routing is **five rules** per fronting server, in the `*.ethr.et` block only —
`infrastructure/nginx.conf` (production) and `docker/nginx/default.conf` (local
and E2E). This is the complete list the Plesk cutover has to reproduce:

| Path | Match | Serves |
|---|---|---|
| `/` | exact | the landing page |
| `/media/` | prefix | logo, hero and section images |
| `/robots.txt` | exact | crawler directives |
| `/sitemap.xml` | exact | this tenant's own sitemap |
| `/preview` | **exact** | the signed preview |

`/preview` is an exact match because `URL::temporarySignedRoute` puts the
signature in the query string — the path is precisely `/preview`, and a
`^~ /preview/` prefix never matches it. That mistake was made once in this
feature's own plan.

On a tenant host only these reach Laravel; every other path proxies to Next.js.
Nothing in the test suite can see a missing rule, because it is a web-server
rule and not a route. The Plesk `.htaccess` equivalent is **not written yet** — that
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

## Organisation presets

Eight layouts, one per organisation kind, and **no new taxonomy**: each is one
of the eight `OrganizationTemplate` slugs onboarding already maintains, and
`IndustryCatalog` already aliases its twenty-seven industries onto exactly that
set. `App\Enums\PublicPagePreset` names them; `PresetWiringTest` fails if a
ninth is added without a stylesheet block, a schema.org type and labels.

`App\Services\Public\PresetResolver` chooses one, in descending order of
authority:

1. `tenant_public_profiles.preset` — the administrator's explicit choice
2. `tenants.settings['industry']` → the industry's `base` template
3. `tenants.type`, normalised
4. `general`

Step 3 is a normalisation rather than a cast because the column genuinely means
three things: it is `string|max:50` with **no `in:` rule**, and the registration
form offers `general` and no `private`, the settings card offers `private` and
no `general`, and onboarding writes an `IndustryCatalog` base. All of them have
to land on a real page.

**`tenants.settings` still never reaches the view-model.** The resolver reads
the industry key out of it and returns an enum case; the blob stays behind.
`PublicSectionBoundaryTest` asserts the word `industry` never appears in the
rendered HTML.

### The government preset, and what the lock actually proves

Seven presets are a taste decision. `government` is not: on `*.ethr.et`,
state-official styling tells a visitor something about who they are dealing
with, using ETHR's own domain to say it.

Gating it on the tenant's declared industry would be worthless, because both
signals behind that are written by the tenant about itself — a private company
need only claim to be a ministry. So the derived check is the floor, and the
control is **`tenants.government_verified_at`**: granted by a platform admin
through the `admin.manage` surface, absent from `Tenant::$fillable`, and
unreachable from any `/settings/*` route. A tenant may ask; the platform
answers.

`GovernmentPresetLockTest` asserts that a tenant with `industry = ministry` and
no verification is **still refused** — the exact case a derived-only check
waves through.

---

## The section builder

`tenant_public_sections` holds the ordered blocks, `tenant_public_items` the
repeatable entries inside them. Twelve kinds, listed in
`App\Enums\PublicSectionKind`, each with a Blade partial, headings in both
locales and a place in at least one preset's defaults —
`SectionKindWiringTest` checks all three for every case.

### `news` and `notices` are both here on purpose

The distinction is editorial, not technical, and it is worth stating because
"we already have notices" is the first reasonable objection.

A **notice** is a statement of record — a relocation, a tender, a consultation
period. Dated, text-first, read by someone who came looking for it. It belongs
in a list and a photograph would cheapen it.

**News** is the opposite errand: a graduation, a new wing, a partnership, read
by a visitor who arrived for another reason and stayed. Image-led cards with an
excerpt and a link out to the full story.

Same table, same caps, same escaping; only the partial and the reading differ.
They are separate kinds rather than a `layout` variant of one because an
organisation that publishes both should not have to choose which to call it —
and a government page in particular wants statutory notices *above* news, which
the seeded default does.

Both are admin-typed. Neither reads the `announcements` table, and there is now
a test for each by name: two public kinds whose names echo an internal HR
feature is two chances for a later "obvious improvement" to join them to it.

**The rule that governs the whole feature:** every word and number on the page
was typed by an administrator. Nothing is derived from an HR table — not
`announcements`, not `employees`, not a headcount from `Employee::count()`.
`notices` is the sharpest case and `stats` the most tempting; both have tests.

Templates never receive models. Sections and items reach Blade as
`PublicSection` and `PublicSectionItem`, the same allow-list discipline
`PublicTenantPage` applies, so a partial cannot render a storage path or walk
back to `tenant->settings`.

**Caps: 20 sections per tenant, 24 entries per section, one image per entry.**
Unbounded is a page-weight and storage denial of service the platform pays for,
on shared hosting, for a page anyone can request — and it arrives through
ordinary use rather than an attack.

**Content is plain text.** No rich-text field exists anywhere, and no public
view contains `@php` or an unescaped echo outside the JSON-LD block;
`PublicSectionXssTest` asserts that across every file under
`resources/views/public/`. Paragraphs are split on blank lines, never `nl2br`
on raw output.

**Amharic falls back to the base language** rather than rendering a blank. A
tenant that has written English and not yet Amharic gets an Amharic page with
English content, not one with holes — and Amharic is the default locale, so
that is the common case.

### Section images

`GET /media/section/{publicId}` takes an entry's ULID, never a path. The ULID
resolves through the tenant-scoped model, so another tenant's identifier is not
found rather than found and refused, and hiding a section hides what is inside
it. Uploads reuse `FileStorageService`, which verifies magic bytes and strips
EXIF — a photograph taken on a phone does not publish its location.

Alternative text is **required** at upload, because an image without it is a
WCAG 1.1.1 failure on a page built to be read by the public.

### Opting in, and what gets seeded

`preset IS NULL` renders the classic template, byte for byte. Every row that
existed when the migration ran is in that state, so **deploying changed no live
page**; `ClassicLayoutUnchangedTest` pins that against a snapshot, with the
clock frozen because the footer renders `now()->year`.

Choosing a layout seeds that preset's default sections. Blocks whose content
already exists (hero, about, contact, cta — they read the profile) are seeded
**visible**; blocks that need new writing are seeded **hidden** with their
heading pre-filled. Independently, a block with no content renders no markup at
all. "Loaded by default" must not mean an empty frame with headings over
nothing.

Switching preset adds what the new layout needs and never duplicates or
discards what is already written.

---

## Preview

`GET /preview` on the tenant host, behind `signed:relative` — a URL minted by
`POST /api/v1/settings/public-page/preview-url`, valid fifteen minutes.

A signature rather than session authentication because the dashboard is a
Next.js application on a different origin using Sanctum cookies, and hanging
`auth` off a Blade route here would drag session state into the one route group
whose design property is that it has none.

`signed:relative`, not `signed`: the URL is for the tenant's own hostname,
which is not `config('app.url')`, so an absolute signature would be computed
over the wrong host. The host is deliberately not part of the signature —
`ResolveTenant` reads it, so a signature carried to another tenant's host
renders *that* tenant's page.

A rejected signature answers **404, not 403**, because `RenderPublicErrorPage`
collapses every 4xx on this host: a 403 would confirm `/preview` exists.

Preview always sends `noindex`, never appears in the sitemap, shows hidden
sections, and says in a coloured bar that it is a preview — an administrator
who cannot tell it from the live page will either publish a draft or believe a
draft is published.

---

## SEO

- **Structured data per organisation kind**: `GovernmentOrganization`,
  `CollegeOrUniversity`, `Hospital`, `NGO`, `BankOrCreditUnion`, `Hotel`, or
  `Organization` for the two with no more specific type. A classic page keeps
  `Organization` whatever its industry, because changing it would alter a live
  page nobody asked to change.
- **`hreflang`** for both locales plus `x-default`. The page has always been
  bilingual; nothing told a crawler the other version existed.
- **`/robots.txt`** driven by `is_indexable` and the environment, referencing
  the sitemap.
- **`/sitemap.xml`** listing this tenant's page and its language variants.

All four anonymous surfaces — page, robots, sitemap, preview — share
`PublishedTenantLocator`, so they 404 in exactly the same cases. That is a
security property, not tidiness: a robots.txt that answers where the page does
not tells a visitor the organisation exists and chose not to publish.

---

## Platform takedown

`tenant_public_profiles.suspended_at`, settable only through the `admin.manage`
surface and absent from `$fillable`. A suspended page answers exactly as an
unpublished one — same status, same body — so suspension is not a way to
discover which tenants have been moderated. `is_published` is left untouched,
so restoring is one column write rather than a guess about what the tenant
wanted.

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
- **A tenant directory.** Each tenant now has its own `sitemap.xml`, listing
  only its own page and language variants. A platform-wide index of which
  organisations use ETHR remains refused: that is unauthorised discovery
  wearing an SEO hat, not a feature.
- **Downloadable documents.** Government bodies genuinely want to publish
  directives and forms, and that is exactly why it is not bolted onto the
  builder: it means anonymous file downloads, a new MIME class and a
  malware-distribution surface. Its own change, with its own decisions
  (PDF-only, magic bytes, `Content-Disposition: attachment`, `nosniff`, a size
  cap, scanning).
- **A contact form.** It would be the first anonymous *write* endpoint on the
  public surface — spam, storage, unsolicited personal data and an
  email-sending path, all reachable without authentication. The contact
  section publishes a phone number, an email address and a postal address,
  which is what a visitor actually needs.
- **Embedded maps.** An iframe or map SDK is a third-party request made by
  every anonymous visitor, and this page's CSP is `default-src 'none'` with no
  `frame-src` and no `script-src` at all. A map would cost the page its best
  security property to save typing an address.
- **Analytics.** Same reason: the page ships zero JavaScript and
  `PublicSecurityHeadersTest` asserts the CSP contains no `script-src`.
  Visitor tracking is a deliberate decision, not a styling detail.
- **A draft/publish workflow for body content.** Edits to a published page are
  live immediately. Preview covers the actual need — seeing a change before it
  is real — without doubling the state in every table and every test.
- **Plan or subscription gating.** A `FeatureFlag` model and `PlanFeature` enum
  exist, so gating the builder behind a paid plan is feasible. Nobody has
  decided that, and guessing at commercial policy inside a code change is the
  wrong place to do it.
