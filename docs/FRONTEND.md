# ETHR Frontend

Next.js 16 (App Router) · React 19 · TypeScript strict · Tailwind CSS 4 ·
shadcn/ui · TanStack Query v5 · TanStack Table v8 · React Hook Form + Zod.

> Source lives at `src/src` (note the nested path). Tooling notes: Prettier **is**
> enforced (`./scripts/gates.sh frontend` runs `prettier --check`, and the gate
> fails on a formatting diff — this file used to say the opposite); type-check
> with `node node_modules/typescript/bin/tsc`.

## Structure

```
src/src
  /app                    App Router — several ROOT layouts, see "Public site routing"
    /(root)               "/" and the six pre-locale URLs; redirect into a language
    /(marketing)/[locale] the public site, prerendered once per available locale
    /(auth) /(dashboard)  /kiosk  /offline
  /components  /ui  /shared  /layouts  /patterns   (QueryBoundary, DataTable, FormPatterns)
  /features/{feature}     components / hooks / api.ts / types.ts
  /api                    client.ts (axios), /types (generated OpenAPI)
  /lib                    i18n, calendar, offline, hooks, utils
  /styles                 semantic tokens + global CSS
```

## Public site routing

**There is no `app/layout.tsx`.** Removing it is what makes `<html lang>` correct,
and it is the one structural fact about this tree worth knowing before editing it.

`lang` can only be set by a root layout, and a file at `app/layout.tsx` sits above
every dynamic segment — so it can never read the locale the URL asked for. With
`DEFAULT_LOCALE` being `am`, the prerendered `/pricing` therefore served Amharic
text under `lang="en"` to everyone, including crawlers and screen readers. Next's
supported answer is multiple root layouts: delete the single root, and let the
topmost layout of each top-level tree render its own `<html>`/`<body>`.

| tree | root layout | `lang` |
|---|---|---|
| `(marketing)/[locale]` | its own `layout.tsx` | the locale in the URL |
| `(root)` | its own `layout.tsx` | `DEFAULT_LOCALE` (no prose on these pages) |
| `(auth)`, `(dashboard)`, `kiosk`, `offline` | each existing layout | `DEFAULT_LOCALE`, corrected after hydration from the stored preference |

They all render `app/root-shell.tsx`, which holds the `<html>`/`<body>` shell, the
font and `baseMetadata`. **No URL changed** — route groups are erased from the
path — but a new top-level segment now needs a root layout of its own or the
build fails.

### What the locale prefix means for a component

- The public pages are `(marketing)/[locale]/**`, generated for the locales that
  are `available` in `lib/i18n/config.ts` and no others (`dynamicParams = false`).
- Inside that tree the URL is authoritative: `useT()` reads the route locale from
  context in preference to localStorage, so `/en/pricing` is English even for a
  reader whose stored preference is `am`.
- Link to another public page through `useLocaleHref()`, never a bare `/pricing` —
  an unprefixed link drops the reader on the negotiating redirector and can
  silently change their language. `/login` and `/register` stay unprefixed.
- `generateMetadata` gets its title and description from the dictionary via
  `translateStatic`, and its `hreflang` set from `alternatesFor`.

### How `/en/*` gets its English

Only `am.json` is imported eagerly; `en.json` is a dynamic import. **The server
has it** by the time a page renders — Next's rendering yields between components,
so the import resolves during the build — which is why the emitted
`.next/server/app/en/faq.html` carries real sentences even though its FAQ entries
pass no fallback at all.

**The browser does not**, at the moment it hydrates. Left alone, its first render
would produce raw keys for those seventeen fallback-less call sites, React would
discard the server's HTML, and the page would show `marketing.faq_page.what_is_q`
until the chunk landed. So `(marketing)/[locale]/layout.tsx` sends a **projection**
of the dictionary — the key families in `lib/i18n/public-keys.ts`, 7.5 KB gzipped
against 45 KB for the whole file — and `DictionaryRegistrar` registers it *during
render*, before anything below it renders. Server and client then produce the same
output and hydration has nothing to reconcile.

`scripts/i18n-check.js` enforces both halves: every fallback-less key on a public
page must be inside those prefixes, and every fallback that *is* written must equal
`en.json`'s value. Both are scoped to the computed import closure of the public
routes, not to a directory allow-list.

### Negotiation

`middleware.ts` redirects the seven unprefixed URLs to a language, reading the
`locale` cookie that `setLocale()` writes and then `Accept-Language`. It is an
optimisation only: `(root)/locale-redirect.tsx` runs the same negotiation in the
browser, because middleware does not run under `output: "export"` and cannot read
localStorage. Locale logic must stay **above** the `NEXT_PUBLIC_ROOT_DOMAIN` early
return in `middleware.ts`, which is taken on every development machine.

## Non-negotiables (see CLAUDE.md)

- Semantic color tokens only (`--color-*`), never raw Tailwind colors.
- Every data-fetching view handles all four states via `<QueryBoundary>`.
- All user-facing text via i18n keys; ship `en` + `am` (Amharic line-height 1.6–1.8).
- Responsive at 375 / 768 / 1280; light + dark verified.
- Optimistic UI only per the policy table in CLAUDE.md.

## Onboarding

The current onboarding wizard is `features/onboarding/components/setup-wizard.tsx`
— a streamlined 6-step flow. Its template step now reports the **real** number of
records provisioned (Slices 0–1).

### Onboarding v2 — backend contract (frontend slice pending)

The backend for the industry-aware onboarding v2 is complete and contract-stable;
the interactive UI for the steps below is a dedicated frontend slice. Endpoints:

| step | endpoints |
|---|---|
| Smart configuration | `GET /onboarding/industries`, `POST /onboarding/configuration/preview`, `POST /onboarding/configuration/apply` |
| Workforce migration | `POST /onboarding/migration/devices/{device}`, `POST /onboarding/migration/rows`, `GET /onboarding/migration/batches/{batch}`, `PATCH /onboarding/migration/rows/{row}`, `POST /onboarding/migration/batches/{batch}/commit` |
| Device discovery | `GET /devices/{device}/enrollments` |
| Access & identity | `GET`/`PUT /onboarding/access` |
| Readiness & go-live | `GET /onboarding/readiness`, `POST /onboarding/go-live` |

Response shapes:
- **Configuration preview** returns `{ industry, signals, overall_confidence,
  sections{ [name]: { source, confidence, items } }, plan }`. Render confidence
  as a per-section chip; let the user edit `plan`, then POST it to `apply`.
- **Migration batch** returns `{ public_id, status, summary, rows[] }`; each row
  has `match_outcome` (matched/probable/ambiguous/new), `candidates`, and
  `action`. Build the review queue around per-row action controls; PATCH the
  action, then commit.
- **Readiness** returns `{ overall_score, level, categories, gaps[] }`; each gap
  carries a `remediation` path — link it directly.

Reference: [INDUSTRY_TEMPLATES.md](INDUSTRY_TEMPLATES.md),
[MIGRATION.md](MIGRATION.md), [DEVICE_INTEGRATION.md](DEVICE_INTEGRATION.md),
[IDENTITY_RESOLUTION.md](IDENTITY_RESOLUTION.md).

## Login & authentication UX

The `(auth)` route group is a split-screen: a left brand panel (`hidden lg:flex`,
so mobile shows the form only) and the auth form on the right. Pages: `login`,
`login/otp`, `login/mfa`, `login/forgot`, `login/reset`, `register`.

**Hostname-aware context.** `lib/auth/use-auth-host-context.ts` is the single
hook every auth page uses to know which application it is on. It builds on
`lib/auth/host-context.ts` (`hostContext`/`tenantFromHost`, the same classifier
`middleware.ts` uses), driven by `NEXT_PUBLIC_ROOT_DOMAIN`:

| Host | Context | Login heading | Tenant field |
|---|---|---|---|
| `admin.ethr.et` | `platform` | "Sign in to ETHR Platform" | hidden; SSO/OTP/register hidden |
| `{tenant}.ethr.et` | `tenant` | "Sign in to {Tenant Name}" | replaced by read-only `TenantHostIndicator` |
| `ethr.et` (apex) | `public` | "Sign in to ETHR" | shown; submit routes to `{tenant}.ethr.et/login` |
| other reserved label (`www`, `api`, …) | `public` | "Sign in to ETHR" | shown — the API resolves no tenant there either |
| `localhost` (no ROOT_DOMAIN) | `unknown` | "Sign in to ETHR" | shown; tenant travels via `X-Tenant` |

`host-context.ts` mirrors `Tenant::RESERVED_SUBDOMAINS`, and must keep doing so:
a label the browser calls a tenant while the API does not renders a login form
with the organisation field hidden and no way to supply one. It is the **only**
host classifier on the client — `middleware.ts`, `api/client.ts`, `tenant-host.ts`
and every auth page go through it, so the browser and `ResolveTenant` cannot
drift apart independently.

The tenant display name comes from `GET /auth/tenant-context` (public, name/logo
only — never credentials-adjacent; resolved server-side by `ResolveTenant` from
the hostname). Tenant identity is **never** trusted from a client parameter; the
apex form only forwards the slug the user typed to that tenant's own host, where
the session cookie will live. A `tenant-not-found` / `tenant-inactive` host shows
a distinct `TenantLookupErrorState`, not an auth failure.

**Auth methods.** Password (email / phone / employee-ID per tenant setting),
TOTP MFA, SAML SSO, and SMS OTP are all backed by real endpoints. There is **no
Google OAuth backend** — a "Continue with Google" button was intentionally not
added, since a live button with no provider would be fake auth.

**Localization.** One mechanism: `lib/i18n` + the reusable
`components/shared/language-switcher.tsx` (compact dropdown reading
`supportedLocales`). Every auth string is a key in `en` + `am`.

**Product story.** `components/shared/product-story-animation.tsx` renders the
Employee → Attendance → Leave → Payroll → Intelligence progression on the left
panel using existing `.stagger-children` / `.animate-*` utilities — no animation
dependency, and the site-wide `prefers-reduced-motion: reduce` rule renders it
static automatically.

## Testing

Vitest + React Testing Library; MSW with generated OpenAPI types for contract
tests. Test user-visible behavior and all four QueryBoundary states; include
Amharic text in snapshots to catch truncation.