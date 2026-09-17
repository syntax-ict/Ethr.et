# ETHR — Public Marketing Site: Production-Readiness Upgrade Plan

**Date:** 2026-09-17
**Tree audited:** `/home/user/Ethr.et` @ `748dcb9`, branch `claude/public-landing-page-upgrade-u6m2aq`
**Mode:** Read-only inspection. No application code changed by this pass.
**Scope:** `/`, `/features`, `/pricing`, `/faq`, `/contact`, the shared marketing header and footer, and the legal pages that do not yet exist.
**Next phase:** Phase MP-A. **Not started.**

---

## How to read this document

The tagging convention is [`BASELINE.md`](BASELINE.md)'s, for the same reason: a plan
that mixes what was seen with what was assumed is a plan that gets acted on wrongly.

| Tag | Meaning |
|---|---|
| **[verified]** | Observed in this tree during this pass, with a `path:line` or a command and its output |
| **[documented-planned]** | A repo document describes an intention, not a state |
| **NOT VERIFIED** | Not measurable from this tree — typically requires the hosting account or the owner |
| **NOT MEASURED** | Measurable in principle, but the prerequisite was unavailable this pass |

Every finding in §2 carries a `path:line`. Nothing here was inferred from a phase
document's checkbox — [`docs/README.md`](../README.md) warns those are unmaintained, and
§2.11 below is what that warning looks like in practice.

The phase shape follows [`DOMAIN_MULTITENANT_UPGRADE_PLAN.md`](../phases/DOMAIN_MULTITENANT_UPGRADE_PLAN.md).

---

## 1. Why this document exists

The public site renders well. It is responsive, it uses the semantic token system, it is
fully keyed for `en` and `am`, and someone clearly cared about it. That is what makes the
problem easy to miss.

**It also says things that are not true.** It reports 500 customers and 50,000 managed
employees for a product that has never been deployed to production. It quotes a named HR
director at a named company. It advertises a Starter plan holding 50 employees when the
plan the product enforces holds 10. It links to a Privacy policy that does not exist,
one paragraph below a promise of "full compliance with Ethiopian data protection law."

None of that is a rendering defect, and no gate in this repository can catch any of it.
`gates.sh frontend` checks that every translation key exists in both locales — it has no
opinion on whether the translated sentence is true.

There is a second problem underneath, which is structural rather than editorial: the
default locale is Amharic, and **no URL addresses the Amharic site**. It is rendered
client-side, under `<html lang="en">`, at Latin line-height, in a font that is not
shipped. For a product whose entire positioning is "built for Ethiopian organizations",
the Amharic half of the marketing site is invisible to every search engine.

> This document does not claim the site is badly built. It claims it is not yet a site
> that can be pointed at the public, and it separates the two reasons: claims that must
> be made true or removed, and machinery that was never finished.

---

## 2. Findings

### Tier 1 — blocks a public launch

#### 2.1. The landing page asserts figures with nothing behind them **[verified]**

`src/src/app/landing-content.tsx:57-62`:

```ts
const socialProofMetrics = [
  { key: "organizations", value: "500+", icon: Building2 },
  { key: "employees_managed", value: "50,000+", icon: CheckCircle2 },
  { key: "payrolls_processed", value: "1M+", icon: Wallet },
  { key: "uptime", value: "99.9%", icon: Zap },
];
```

Repeated in the hero badge at `landing-content.tsx:88` — "Now serving 500+ Ethiopian
organizations" — beside a pinging green status dot.

Against that:

- [`deployment/GATE-0-RESULT.md:3`](../deployment/GATE-0-RESULT.md) — "**Status: NOT RUN against the account.**" Nothing in this repository has ever touched the Ethio Telecom account.
- [`BASELINE.md`](BASELINE.md) §13e (line 726) — "No performance baseline exists." The 99.9% uptime figure has no measurement behind it, and could not have.

Both strings are fully translated into Amharic (`marketing.hero.badge`,
`marketing.social_proof.*`), so the claim is made in two languages.

#### 2.2. A fabricated testimonial, attributed to a named person at a named company **[verified]**

`landing-content.tsx:324-354` — five filled stars, then:

> "ETHR replaced three separate systems for us. Attendance, payroll, and leave — all in
> one place, all working offline at our factory floor."
> — **Abebe Kebede**, HR Director, Addis Manufacturing PLC

Also translated (`marketing.testimonial.quote|author|role`). [`PHASE_01.md`](../phases/PHASE_01.md)
S06 asked for a "*placeholder* for testimonials/logos"; what shipped is not a placeholder,
it reads as a real reference customer.

#### 2.3. The pricing page advertises limits the product does not enforce **[verified]**

`src/src/app/(marketing)/pricing/pricing-content.tsx` hardcodes three tiers.
`api/database/seeders/PlanSeeder.php:14-45` is what the product actually applies.

| | Marketing page | `PlanSeeder` |
|---|---|---|
| Starter — employees | Up to 50 (`pricing-content.tsx:10`) | **10** (`PlanSeeder.php:19`) |
| Starter — branches / devices | 5 / 10 (`:11-12`) | **1 / 2** (`:20-21`) |
| Starter — payroll | "Basic payroll" listed (`:14`) | **absent** from `features` (`:22`) |
| Professional — price | `priceText: "2,500"` ETB/month (`:70`) | `99900` cents = **999.00 ETB** (`:28`) |
| Professional — employees | Up to 200 (`:20`) | **100** (`:29`) |
| Professional — branches / devices | 15 / 50 (`:21-22`) | **5 / 20** (`:30-31`) |
| Professional — API access | listed (`:27`) | `api_access` is **Enterprise-only** (`:32`, `:42`) |
| Enterprise — price | "Custom" | `299900` cents = **2,999.00 ETB** (`:38`) |

Every row is wrong in the direction that favours the sale. A visitor who buys Starter on
the strength of this page gets one fifth of the employee headcount and no payroll.

The mechanism is that the page never asks. A public, unauthenticated endpoint returning
exactly these fields already exists and no frontend code calls it —
`api/routes/api.php:122`:

```php
Route::get('/plans', [PlanController::class, 'index']);
```

`PlanController::index` selects `price_cents`, `max_employees`, `max_branches`,
`max_devices`, `features`. [`PHASE_01.md`](../phases/PHASE_01.md) S06 specified this
endpoint *for this page*. The wiring was never done, so the two halves drifted with
nothing to notice.

> `priceText: "2,500"` is a bare string literal in JSX, not a `t()` call, so
> `scripts/i18n-check.js` never sees it. The one number on the page a customer will act
> on is the one number outside the translation system.

#### 2.4. There is no privacy policy and no terms of service **[verified]**

`src/src/components/layouts/marketing-footer.tsx:24-34` — five links, all `href="#"`:
About, Blog, Careers, **Privacy**, **Terms**. No such routes exist anywhere in
`src/src/app/`.

One section above the footer, `landing-content.tsx:290` asserts:

> "ETHR runs on your infrastructure in Ethiopia. No data leaves the country. Full
> compliance with Ethiopian data protection law."

A compliance claim with a dead Privacy link under it is worse than no claim. It is also
the one finding here with a legal rather than an engineering cost.

---

### Tier 2 — the site does not function as a bilingual public site

#### 2.5. Amharic is the default locale and no URL addresses it **[verified]**

The chain, in order:

| | |
|---|---|
| `src/src/lib/i18n/translations.ts:49` | `export const DEFAULT_LOCALE = "am";` |
| `src/src/lib/i18n/translations.ts:1` | `am.json` is imported **eagerly** at module top — 3,273 keys in the initial bundle |
| `src/src/lib/i18n/useT.ts` | `useSyncExternalStore(..., getLocale, () => DEFAULT_LOCALE)` — the server snapshot is `am` |
| `src/src/app/layout.tsx:36` | `<html lang="en" suppressHydrationWarning>` — hardcoded |
| `src/src/app/providers.tsx` | `HtmlLangSync` patches `documentElement.lang` in a `useEffect`, after mount |
| `src/src/styles/globals.css:565-569` | Ethiopic `line-height: 1.7` keys off `[lang="am"]` |

So the server renders **Amharic text inside `lang="en"` at Latin line-height**, and it
reflows after hydration. Three consequences, in increasing order of severity:

1. A layout shift on every first paint, against a documented CLS budget of `< 0.1` ([`docs/CLAUDE.md`](../CLAUDE.md), Performance Targets). **NOT MEASURED** — no Lighthouse run exists for `/`.
2. A screen reader is told the Amharic content is English.
3. **There is no `[locale]` segment, no `hreflang`, no `alternates`.** The locale lives in `localStorage`. The Amharic site cannot be crawled, cannot be linked to, and cannot be shared.

The translations themselves are not the problem — `en.json` and `am.json` each hold 3,273
keys, 235 of them `marketing.*`, and **zero** of the Amharic marketing values are copies
of the English. Someone did that work properly. The delivery layer throws it away.

#### 2.6. The Amharic webfont is not shipped **[verified]**

`globals.css:19` names `"Noto Sans Ethiopic"` in the `--font-sans` stack. Nothing loads
it. `src/src/app/layout.tsx:6` loads exactly one font:

```ts
const inter = Inter({ subsets: ["latin"], variable: "--font-inter" });
```

`src/public/` contains no font files and there is no `@font-face`. So Ethiopic rendering
depends entirely on the visitor's operating system having the font — on a product whose
**default locale is Amharic**. [`docs/CLAUDE.md`](../CLAUDE.md) Visual Identity requires
Noto Sans Ethiopic at 400/500/600/700. JetBrains Mono has the same problem: named in
`--font-mono`, never loaded.

#### 2.7. The language switcher silently does nothing for four of six languages **[verified]**

`marketing-header.tsx:93` and `:167` map over **all** `supportedLocales` with no filter.
`translations.ts:72-85` guards `setLocale` on `status === "available"` and returns early
otherwise. Selecting Afaan Oromoo, Tigrinya, Somali or Sidama closes the dropdown and
changes nothing — no error, no disabled state, no explanation.

`translations.ts:88-95` documents the intended behaviour: those locales "stay listed so
the roadmap is visible, but **render disabled with an explanation**." The guard was
written; the UI half was not.

---

### Tier 3 — the SEO and asset baseline is absent, not weak

#### 2.8. Nothing that makes a page findable exists **[verified]**

Searched repo-wide, all absent: `sitemap.ts`, `robots.ts`, any `application/ld+json`
(so `/faq` carries no `FAQPage` schema), `opengraph-image`, `metadataBase`, canonical
`alternates`, twitter card, `openGraph.images`.

What does exist is `src/src/app/page.tsx:4-13` — `title`, `description` and a bare
`openGraph` block with no image. Shared on any social platform, the site has no preview
card.

#### 2.9. The declared favicon does not exist **[verified]**

`src/src/app/layout.tsx:19` declares `icon: "/favicon.ico"`.

```
$ ls src/public/favicon.ico
ls: cannot access 'src/public/favicon.ico': No such file or directory
```

`src/public/` holds `sw.js`, `manifest.json`, and `icons/icon-{72,96,128,144,152,192,384,512}.png`.
A 404 on every page of the application, including the authenticated ones.

#### 2.10. Two manifests disagree, and the dead one is the off-brand one **[verified]**

| | `src/src/app/manifest.ts` | `src/public/manifest.json` |
|---|---|---|
| `theme_color` | `#2563eb` (`:12`) — a raw Tailwind blue | `#0F4C75` (`:8`) — the brand primary |
| `lang` | `en` (`:41`) | `am` (`:10`) |
| Served at | `/manifest.webmanifest` | `/manifest.json` |

`layout.tsx:22` points at `/manifest.json`, so `manifest.ts` is dead code. It is also the
copy that violates convention #12. Two sources of truth, one of them unreachable and
wrong, is the shape of a defect waiting for someone to "fix" the live one by editing the
dead one.

#### 2.11. There are no images, and no logo **[verified]**

Zero `<img>`, zero `next/image` on the marketing site — in fact `next/image` is used
nowhere in the codebase (the one deliberate exception is documented at
`src/src/components/ui/avatar.tsx:23`). Every visual is a `lucide-react` SVG or a
Tailwind gradient blur.

The ETHR logo is a rounded box containing the letter **E** — `marketing-header.tsx:57-59`.
There is no logo asset in the repository.

---

### Tier 4 — specified in `PHASE_01.md` S06, checked off, never built **[verified]**

[`PHASE_01.md`](../phases/PHASE_01.md) S06 (lines 48-92) marks every line `[x]`.
Four of them are not in the tree:

| S06 line | State |
|---|---|
| Hero "product screenshot/mockup" | Absent |
| "Ethiopian tibeb pattern as section dividers (design identity)" | **One** 1px `repeating-linear-gradient` strip, `marketing-footer.tsx:42`. No divider anywhere else, no SVG asset |
| Pricing "Feature comparison table (uses DataTable...)" | Absent. `src/src/components/ui/table.tsx` exists and is unused by marketing |
| Footer "social media" links | Absent |

The tibeb gap is the one that matters beyond a checklist. [`docs/CLAUDE.md`](../CLAUDE.md)
Visual Identity: *"Ethiopian geometric textile motif (tilf/tibeb pattern) ... as section
dividers on the marketing site. **This single cultural element makes the product
unmistakably Ethiopian.**"* [`DESIGN_SYSTEM.md:191`](../DESIGN_SYSTEM.md) specifies the
form: 16px height, medium opacity, repeating diamond/cross SVG in the accent colour, "a
cultural whisper, not a shout." The product's one declared piece of visual identity is,
today, a hairline in the footer.

> [`docs/README.md`](../README.md) already warns that phase-document checkboxes are not
> maintained. This table is the evidence. Do not read S06 as a record of what exists.

---

### Tier 5 — structure, performance, accessibility

| # | Finding | Evidence |
|---|---|---|
| 2.12 | The landing page is `"use client"` for a tree whose only client dependency is `useT`. It ships as client JS, plus the eagerly-imported 3,273-key `am.json` | `landing-content.tsx:1` |
| 2.13 | `/` sits **outside** the `(marketing)` route group, so it does not inherit `(marketing)/layout.tsx`; the shell is re-implemented by hand | `landing-content.tsx:68-70,410` vs `src/src/app/(marketing)/layout.tsx` |
| 2.14 | The axe-core WCAG 2.1 AA suite visits **no marketing route** — only `/login` and authenticated pages | `src/e2e/accessibility.spec.ts` |
| 2.15 | No skip-navigation link on any marketing route. One **does** exist, in the dashboard — so the pattern is already built and keyed in both locales, it was simply never applied to the public shell | present: `src/src/app/(dashboard)/layout.tsx:33-38`; absent: `(marketing)/layout.tsx`, `app/layout.tsx`, `landing-content.tsx`. Required by `DESIGN_SYSTEM.md:316` |
| 2.16 | `government` and `banking` render the same `Landmark` icon | `landing-content.tsx:47-48` |
| 2.17 | Hero CTAs override `size="lg"` with `className="h-12 px-8 text-base"` — there is no marketing scale in the Button variants | `landing-content.tsx:105-124`, `src/src/components/ui/button.tsx` |
| 2.18 | No accordion primitive; `/faq` and `/pricing` each hand-roll one | `src/src/components/ui/` |
| 2.19 | Contact details are hardcoded in one component: `info@ethr.et`, `+251 11 123 4567` | `(marketing)/contact/contact-content.tsx` |
| 2.20 | The contact form has no honeypot. Server-side `throttle:auth` is the only defence | `api/routes/api.php:125` |

---

## 3. Progress

| Phase | Title | Blocker? | Status |
|---|---|---|---|
| MP-A | Truth — claims, pricing, social proof | **Launch blocker** | Not started |
| MP-B | Legal and contact surface | **Launch blocker** | Not started |
| MP-C | Locale-routed static rendering | Yes, for the Amharic half | Not started |
| MP-D | SEO and asset baseline | No | Not started |
| MP-E | Design identity and performance | No | Not started |
| MP-F | Verification and gates | No | Not started |

---

## 4. Phase MP-A — Truth **[launch blocker]**

Nothing else in this document matters if the page lies. MP-A is independent of every
other phase and can land on its own.

**A1 — Remove the invented social-proof metrics.**
`landing-content.tsx:57-62,136-158`. Replace the four-up metric band with claims the
product can actually support today: offline-first attendance, Ethiopian labour-law
payroll, dual calendar, tenant-isolated data residency. These are verifiable from the
code, which is the standard the rest of this repository holds itself to.
*Acceptance:* no numeric claim remains on any marketing route that is not derived from a
cited measurement.

**A2 — Remove the fabricated testimonial.**
`landing-content.tsx:323-354`, plus `marketing.testimonial.*` from both locale files.
Delete the section rather than emptying it — an empty testimonial slot invites the next
person to fill it with another invention. Reinstate it when a real, consented quote
exists.

**A3 — Rewrite the hero badge.**
`landing-content.tsx:86-90`. The pinging green dot reads as a live-system indicator; it
should not sit beside a customer count under any circumstances. Replace with a capability
or availability statement.

**A4 — Reconcile the pricing page with the enforced plan catalog.**
`(marketing)/pricing/pricing-content.tsx`, every row in §2.3.

Recommended shape, and the reasoning: a runtime `fetch` of `GET /api/v1/plans` would be
correct but leaves the pricing content invisible to crawlers and is fragile under the
static-export fallback (§6). Instead, render from one checked-in source of truth derived
from the catalog, and **pin it with a contract test** so it cannot drift again:

- A `src/src/lib/marketing/plans.ts` fixture carrying the tier data, formatted through the existing `formatETB(cents)` — `src/src/lib/utils/currency.ts:1` — so convention #3 is honoured and `2,500` stops being a bare literal.
- A Pest test asserting the fixture matches `Plan::where('is_active', true)` on price, `max_employees`, `max_branches`, `max_devices` and `features`. It fails the moment either side moves.
- Optionally, a client-side refresh through `QueryBoundary` (`src/src/components/patterns/QueryBoundary.tsx`) over the same endpoint, satisfying convention #13 while the static render stays crawlable.

*Acceptance:* the contract test fails when `PlanSeeder` and the marketing page disagree,
and passes on `main`.

**A5 — Substantiate or soften the data-protection claim.**
`landing-content.tsx:287-292`. This one is gated on MP-B: the sentence is defensible once
a privacy policy exists and says the same thing. Until then it is an unbacked compliance
assertion. It is an owner decision (§9.4), not an engineering one.

**Must not break:** `src/src/test/marketing-pages.test.tsx` hard-asserts the literal
`"99.9%"`, `"Organizations"`, and the current hero copy. It is rewritten **in the same
slice** — [`docs/CLAUDE.md`](../CLAUDE.md): *"Never implement a feature without writing
its tests in the same slice."*

**i18n cost:** every string touched needs its `marketing.*` key updated in **both**
`en.json` and `am.json`. `scripts/i18n-check.js` fails on key drift between locales, and
an English string pasted into `am.json` passes the gate while defeating its purpose.

---

## 5. Phase MP-B — Legal and contact surface **[launch blocker]**

**B1 — Build `/privacy` and `/terms`.** New routes under `(marketing)`, following the
established `page.tsx` + `*-content.tsx` split. Wire `marketing-footer.tsx:33-34`.

**B2 — Resolve About / Blog / Careers.** `marketing-footer.tsx:24-26`. Build them or
remove the links. A link to a page that does not exist reads as evidence that it does —
the same argument `scripts/docs-link-check.js` makes about documentation.

**B3 — Move contact details to one source.** `info@ethr.et` and `+251 11 123 4567` are
hardcoded in `contact-content.tsx`. Confirm they are real (**NOT VERIFIED** — owner) and
move them somewhere the footer, the contact page and the JSON-LD in MP-D can all read.

**B4 — Add a honeypot to the contact form.** The server throttles at
`api/routes/api.php:125` (`throttle:auth`), which bounds abuse but does not stop it. A
hidden field costs nothing and needs no new dependency. Surface the 429 as a real message
rather than a generic failure.

*Acceptance:* no `href="#"` remains in `marketing-footer.tsx`; `/privacy` and `/terms`
render in both locales; §2.4's contradiction is gone.

---

## 6. Phase MP-C — Locale-routed static rendering

The largest change here, and the one with a real architectural trade-off. It gates MP-D.

### The constraint that shapes the answer

[`deployment/GATE-0-RESULT.md:168`](../deployment/GATE-0-RESULT.md) leaves Node.js
availability on the Plesk host `NOT VERIFIED`, and line 195 states the consequence:
*"Frontend must ship as static files."* [`B1-B5_GATE_REPORT.md:401`](../B1-B5_GATE_REPORT.md)
names the casualty precisely:

> | **SSR** | Marketing + auth shells only | Marketing pages lose SSR → **SEO impact on `/`, `/features`, `/pricing`, `/faq`, `/contact`** |

The same warning appears in [`SHARED_HOSTING_MIGRATION_PLAN.md`](../SHARED_HOSTING_MIGRATION_PLAN.md)
Option B2. So the fix must be **statically pre-renderable**, not merely server-rendered.
Everything below survives `output: "export"`: `generateStaticParams`, SSG,
`sitemap.ts` and `robots.ts` all emit at build time. Server actions, route handlers and
runtime middleware do not, and are therefore excluded by construction.

### The work

**C1 — Add a `[locale]` segment.** `src/src/app/(marketing)/[locale]/`, with
`generateStaticParams()` returning `['en', 'am']` — the two locales whose
`supportedLocales` status is `available`. Every marketing route is prerendered twice.

**C2 — A server-side `t()`.** A small module that reads the *same* flat JSON dictionaries
in `src/src/lib/i18n/locales/`. One source of translation truth, two rendering paths. It
must keep the `:placeholder` substitution contract so `scripts/i18n-check.js` continues
to apply unchanged.

**C3 — Correct `lang` per route.** The marketing layout sets `lang` from the route
param. This makes `globals.css:565-569`'s Ethiopic line-height apply at first paint, which
is what removes the reflow in §2.5.

**C4 — `metadataBase`, canonical, `alternates.languages`.** Reciprocal `hreflang` between
`/en/...` and `/am/...`, plus `x-default`.

**C5 — The language switcher becomes navigation.** On marketing routes it renders
`<Link>` elements to the sibling locale URL instead of writing `localStorage`. Filter or
disable the four `coming_soon` locales, closing §2.7. The dashboard switcher is untouched.

**C6 — Move `/` into the route group.** Delete the hand-rolled shell at
`landing-content.tsx:68-70,410` and inherit `(marketing)/layout.tsx`. Note this changes
the import path used by `src/src/test/marketing-pages.test.tsx`.

### The trade-off, stated plainly

**Recommendation: marketing-only.** The authenticated dashboard keeps the existing client
`useT` exactly as it is.

What that buys: a crawlable, linkable, shareable Amharic site, correct `lang`, no
hydration reflow, and no `am.json` in the marketing bundle — without touching the i18n
layer that ~76 test files and every dashboard page depend on.

What it costs, and this should not be glossed: **two rendering paths over one dictionary.**
A marketing component that imports `useT` will compile, render, and quietly reintroduce
the client-only behaviour. That needs to be a stated rule and, ideally, a lint rule. The
alternative — migrating the whole app to a server-first i18n layer — is a much larger
change with much larger blast radius, and nothing about the marketing problem requires it.

**Open decision:** whether `/` stays English and Amharic lives at `/am`, or `/` redirects
by `Accept-Language`. A redirect needs a runtime, which the static-export fallback may not
have. Recommendation is the static shape: `/` prerendered as the default, `/am` as its
sibling, `hreflang` connecting them. See §9.6.

*Acceptance:* `curl` of the built `/am` returns Amharic in the HTML body under
`lang="am"`; both locale URLs appear in the sitemap with reciprocal `hreflang`; the build
succeeds with no server-only API on any marketing route.

---

## 7. Phase MP-D — SEO and asset baseline

Sequenced **after MP-C**, because canonical URLs, sitemap entries and `hreflang` all
depend on the final URL shape. Doing it first means doing it twice.

| # | Work | File |
|---|---|---|
| D1 | Add the missing `favicon.ico` | `src/public/favicon.ico` |
| D2 | Resolve the manifest duplication — one source of truth, brand-correct | `src/src/app/manifest.ts`, `src/public/manifest.json`, `layout.tsx:22` |
| D3 | `sitemap.ts` and `robots.ts`, both locales | `src/src/app/` |
| D4 | `opengraph-image.tsx` + twitter card + `metadataBase` | `src/src/app/` |
| D5 | JSON-LD: `Organization`, `SoftwareApplication`, and `FAQPage` on `/faq` | per route |
| D6 | A real logo asset replacing the CSS "E" box | `marketing-header.tsx:57-59`, `marketing-footer.tsx` |

D6 is a design commission, not a code task, and will block on the owner.

*Acceptance:* Lighthouse SEO ≥ 95 on `/` and `/am`; no 404 for `/favicon.ico`; the
Rich Results test validates all three schema types.

---

## 8. Phase MP-E — Design identity and performance

**E1 — The tibeb section dividers.** The one item in this document that is about identity
rather than correctness, and the one [`docs/CLAUDE.md`](../CLAUDE.md) singles out as what
makes the product unmistakably Ethiopian. Build the SVG motif to
[`DESIGN_SYSTEM.md:191`](../DESIGN_SYSTEM.md)'s spec — 16px, medium opacity, accent
colour — as a reusable component, and place it between marketing sections. Replace the
gradient stub at `marketing-footer.tsx:42`. `DESIGN_SYSTEM.md`'s own guardrail applies:
*"Ethiopian identity, not Ethiopian kitsch — no flags, no coffee imagery, no maps."*

**E2 — Load Noto Sans Ethiopic via `next/font`.** Closes §2.6. `next/font` self-hosts, so
the strict `font-src 'self' data:` CSP in `src/next.config.ts` needs no amendment. Subset
to Ethiopic; the marketing pages are the highest-value place for it because the default
locale is `am`.

**E3 — A hero product visual.** Reuse `src/src/components/shared/product-story-animation.tsx`,
which already tells the Employee → Attendance → Leave → Payroll → Intelligence story
using only the CSS utilities in `globals.css`. Its own header comment notes it is built
from `.stagger-children` / `.animate-*` with **no animation dependency**, and is already
neutralised by the site-wide `prefers-reduced-motion` rule. This closes the S06
"screenshot/mockup" gap without shipping a screenshot that will go stale.

**E4 — Drop `"use client"` from the static sections.** Follows from MP-C: once copy comes
from a server `t()`, the hero, features, industries and trust sections are static markup.
This removes the marketing bundle's dependency on the eager 3,273-key `am.json`.

**E5** — a distinct icon for `banking` (§2.16). **E6** — a marketing size in the Button
CVA variants, replacing the `className` overrides (§2.17). **E7** — an accordion
primitive in `components/ui/`, replacing the two hand-rolled ones (§2.18). **E8** — the
pricing feature-comparison table on the existing `components/ui/table.tsx`, fed by the
MP-A4 fixture so it cannot disagree with the tier cards.

*Acceptance:* `src/src/test/semantic-color-tokens.test.ts` stays green (it walks every
`.ts/.tsx` and fails on any raw Tailwind palette class — convention #12 is machine-
enforced); no new runtime dependency is added.

---

## 9. Phase MP-F — Verification

**F1 — Put the marketing routes in the a11y suite.** `src/e2e/accessibility.spec.ts`
already runs `@axe-core/playwright` at `wcag2a, wcag2aa, wcag21a, wcag21aa` and fails on
critical/serious, with `color-contrast` deliberately enabled. Adding `/`, `/features`,
`/pricing`, `/faq`, `/contact` and their `/am` twins is a few lines against existing
scaffolding, and closes §2.14.

**F2 — An Amharic marketing pass**, modelled on `src/e2e/amharic-layout.spec.ts`. Amharic
strings are longer and taller than their English equivalents; the hero and the pricing
cards are where that will break first.

**F3 — A Lighthouse budget.** `@lhci/cli` is already a devDependency and has never been
pointed at `/`. Targets from [`docs/CLAUDE.md`](../CLAUDE.md): FCP < 1.5s, LCP < 2.5s,
CLS < 0.1, Performance > 80, Accessibility > 90. Record the **first** measurement as a
baseline in this document rather than asserting a pass.

**F4 — Rewrite `src/src/test/marketing-pages.test.tsx`** alongside MP-A, not after it.

**F5 — The skip-navigation link on the marketing shell** (§2.15). Lift the existing one
verbatim from `src/src/app/(dashboard)/layout.tsx:33-38` — `sr-only focus:not-sr-only`,
targeting `#main-content`, keyed `a11y.skip_to_content` in both locales already. Nothing
new to design or translate; it just has to be put where the public can reach it.

### Commands

```bash
./scripts/gates.sh frontend   # i18n + Prettier + ESLint + tsc + Vitest
./scripts/gates.sh backend    # for the MP-A4 plan contract test
./scripts/gates.sh docs       # markdown link integrity
cd src && npx playwright test accessibility.spec.ts amharic-layout.spec.ts
```

E2E is **not** in `gates.sh` — Playwright is opt-in and must be run deliberately.

---

## 10. Sequencing

```
MP-A ──┐
       ├── (independent, either order, both are launch blockers)
MP-B ──┘
          │
MP-C ─────┴──> MP-D        (D's URLs depend on C's route shape)
          │
MP-E ─────┘                (independent of C, but E4 follows from it)
          │
MP-F ─────┴──> continuous  (F4 lands with A; F1-F3, F5 after E)
```

**MP-A and MP-B are what stand between this site and a launch.** They touch no
architecture, need no decision from the Plesk gate, and can ship this week. MP-C is the
right fix and a large one; it should not be allowed to delay MP-A.

---

## 11. Constraints an implementer will otherwise rediscover

- **`scripts/i18n-check.js`** — every `t("marketing.…")` key must exist in **both** `en.json` and `am.json` with matching `:placeholders`, or `gates.sh frontend` fails. Both files hold 3,273 keys today, 235 `marketing.*`, in sync. Interpolation uses the third argument (`t("a.b", ":count items", { count: n })`), never a template-literal fallback. **The gate checks key integrity — it does not detect hardcoded JSX strings.** Convention #9 is review-enforced, which is exactly how `priceText: "2,500"` got through.
- **`src/src/test/semantic-color-tokens.test.ts`** walks every `.ts/.tsx` and fails on any raw Tailwind palette class and on redundant `dark:` variants. New hero markup cannot reach for `bg-slate-50`.
- **No animation library is a dependency**, deliberately ([`FRONTEND.md`](../FRONTEND.md)). `globals.css` already provides `fade-in-up`, `fade-in`, `scale-in`, `stagger-children`, `card-lift`, `shimmer`, all zeroed under `prefers-reduced-motion`.
- **CSP is a strict allow-list** — `src/next.config.ts`, `connect-src 'self' ws: wss:` plus the Sentry origin. Any analytics vendor needs that header amended. Fonts via `next/font` self-host and are already covered by `font-src 'self' data:`.
- **Tailwind 4, no config file.** All theming is `@theme { }` in `src/src/styles/globals.css`.
- **Ethiopian Gold is light in every theme.** `globals.css` ~line 112: `bg-brand-accent text-foreground` measures **1.52:1** in dark mode. Pair the fill with `text-brand-accent-foreground`.
- **Themes are classes, not `data-theme`.** `next-themes` with `attribute="class"` and `themes={["light","dark","high-contrast"]}` — this diverges from what `DESIGN_SYSTEM.md` and `docs/CLAUDE.md` say. Trust the code.

### Existing code to reuse

| Need | Reuse |
|---|---|
| Real plan data | `api/routes/api.php:122` → `PlanController::index` |
| ETB formatting (convention #3) | `formatETB(cents)` — `src/src/lib/utils/currency.ts:1` |
| Four-state fetching (convention #13) | `src/src/components/patterns/QueryBoundary.tsx` |
| Comparison table | `src/src/components/ui/table.tsx` |
| Hero visual | `src/src/components/shared/product-story-animation.tsx` |
| Tibeb stub to replace | `src/src/components/layouts/marketing-footer.tsx:42` |
| Marketing shell | `src/src/app/(marketing)/layout.tsx` |
| Skip-navigation link | `src/src/app/(dashboard)/layout.tsx:33-38` — already built and keyed; lift it to the marketing shell |
| E2E scaffolding | `src/e2e/helpers.ts`, `accessibility.spec.ts`, `amharic-layout.spec.ts`, `responsive.spec.ts` |
| Vitest conventions | `src/src/test/setup.ts`, `src/src/test/marketing-pages.test.tsx` |

---

## 12. Open decisions for the owner

These cannot be resolved from the tree. Each one blocks a work item.

1. **Real figures, or none?** (blocks A1) Nothing ships until a measured value has a citation behind it. "We will have customers soon" is not a citation.
2. **A real testimonial, or drop the section?** (blocks A2) A consented quote from a named person at a named organization, or the section stays deleted.
3. **Which pricing is authoritative?** (blocks A4) `PlanSeeder` and the marketing page disagree on every row. One is wrong; only the owner knows which. The contract test pins whichever answer is given — it cannot choose.
4. **Who writes the privacy policy and terms**, and does the "full compliance with Ethiopian data protection law" claim survive their review? (blocks B1, A5)
5. **Analytics at all?** (blocks nothing yet) The CSP would need amending, and adding any tracker newly requires a cookie banner — today there is none, which is currently *correct*, because the site sets only a `locale` key in `localStorage`.
6. **Is `/am` the right Amharic URL shape**, and does `/` stay English or redirect? (blocks C1) Recommendation in §6: the static shape, because a redirect needs a runtime the host may not have.

---

## 13. Explicitly out of scope

- Rewriting the app-wide client i18n layer. MP-C is deliberately marketing-only; §6 states what that costs.
- The authenticated dashboard, in any respect.
- Tenant branding (`features/branding/`) — a different feature that shares the word "brand".
- Anything under `docs/deployment/shared-hosting/`, which [`GATE-0-RESULT.md:225`](../deployment/GATE-0-RESULT.md) fences until the facts exist.
- Running Gate 0. That needs the Plesk account and cannot be automated from this repository.

---

## 14. What was NOT done in this pass

- **No page was rendered.** Every finding is from reading source. No browser, no Lighthouse run, no axe pass. The CLS and bundle-size claims in §2.5 and §2.12 are reasoned from the code and tagged **NOT MEASURED** — MP-F3 is what turns them into numbers.
- **The Amharic translations were not read for quality.** They were checked for existence and for not being English copies (235 of 235 `marketing.*` keys differ from `en.json`). Whether they read well to a native speaker is unmeasured, and matters more once they are the crawlable default.
- **`/features` and `/contact` were inventoried but not audited line by line.** They are in scope for the phases; their findings here are limited to what the shared shell and the i18n layer impose on them.
### One finding was wrong and is recorded rather than quietly fixed

§2.15 first read **"No skip-navigation link exists anywhere."** That was inferred from
the marketing and root layouts, where there is indeed none, and generalised to the
codebase without grepping it. One `grep` found it: `src/src/app/(dashboard)/layout.tsx:33-38`
implements the pattern correctly, with `a11y.skip_to_content` translated in both locales.

The corrected finding is narrower and cheaper to act on — the work is not *design a skip
link*, it is *move one*. The defect in the method is the same one [`BASELINE.md`](BASELINE.md)
§12c records: a conclusion reasoned from two files and asserted about seventy. Every other
absence claim in §2.8-§2.11 and §2.18 was re-checked against a repo-wide grep after this,
and all of them held.

- **No estimate is given.** Every number in this repository that was estimated rather than measured has been wrong, and [`BASELINE.md`](BASELINE.md) §12c records what happens when an attribution is inferred instead of read.
