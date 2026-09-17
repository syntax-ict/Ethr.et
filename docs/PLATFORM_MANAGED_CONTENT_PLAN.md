# Platform-Managed Public Content — what the admin owns, and how

**Prepared:** 2026-09-16 · **Supersedes the content half of** [`LANDING_PAGE_PRODUCTION_PLAN.md`](LANDING_PAGE_PRODUCTION_PLAN.md), whose premise was that the public site's copy should be corrected in place. It should not be corrected in place. It should stop being code.

**Method, same rule as every document in this tree:** everything below was read out of the source, with the file and line given so it can be checked. Nothing was measured against a running build. Nothing is tagged `[verified]`.

---

## The argument, in one paragraph

The public site states facts about the business — prices, limits, a phone number, a logo, a tagline, how many customers there are. Every one of them is currently a literal in a component or a translation key. A fact in a component has no owner, no audit trail, and no way to be corrected by the person who actually knows it. That is why the pricing page advertises numbers the product will refuse to honour, and why a fabricated customer count has survived in two languages. **The fix is not better copy. It is moving the facts to where facts belong.**

This repository already made this exact argument once and acted on it. `2026_08_01_000002_create_platform_settings_table.php` exists because the bank account tenants pay into *"lived as literal JSX in the billing page — a placeholder account number … shipped in the browser bundle, with the bank name stored as a translation key, so the payment destination could differ between the English and Amharic UI."* Every word of that applies to `info@ethr.et` and `+251 11 123 4567` today.

---

## 1. What the platform admin should control

### Already in the database, and already exposed — just never connected

| Fact | Where it lives | Status |
|---|---|---|
| Plan prices | `plans.price_cents` | `GET /api/v1/plans` is **public and unauthenticated**, typed in `src/src/api/generated.ts`, wrapped by `usePlans()` in `features/billing/api.ts` |
| Plan limits | `plans.max_employees` / `max_branches` / `max_devices` | Same endpoint. These are the limits `PlanLimitService` actually enforces |
| Plan capabilities | `plans.features` (JSON, `App\Enums\PlanFeature`) | Same endpoint. Gates real routes via `RequiresPlanFeature` |
| Plan ordering / visibility | `plans.sort_order`, `plans.is_active` | Same endpoint |
| Trial length | `AuthService.php:49` — `now()->addMonths(6)` | Hardcoded, but real |

**The pricing page uses none of it.** `pricing-content.tsx:70` hardcodes `priceText: "2,500"` and lines 9-29 hardcode the limits.

**Every number on that page is wrong:**

| | Page says | Database says |
|---|---|---|
| Starter | up to 50 employees, 5 branches, 10 devices | **10 / 1 / 2**, 0 ETB |
| Professional | **2,500** ETB, up to 200, 15 branches, 50 devices | **999** ETB, **100 / 5 / 20** |
| Enterprise | "Custom" | **2,999** ETB |

Advertising limits five times higher than the ones the product enforces is the kind of thing a customer discovers on day two.

### Missing columns — the facts with nowhere to live

`plans` has no `currency` (ETB is implicit, hardcoded at render), no `billing_interval` (monthly is assumed by `BillingService`), no `description`, and no `is_popular` — so the "Most Popular" badge has no database home. And `features` holds *capability* keys, not sales copy; the marketing bullets (`priority_support`, `sla`, `on_premise`, `training`) exist only in `en.json`.

### Not in the database at all — must be created

| Fact | Where it is hardcoded today |
|---|---|
| Contact email, phone | `contact-content.tsx:14-18` — `"info@ethr.et"`, `"+251 11 123 4567"` |
| Office address | `en.json` translation key |
| Platform logo | An `E` in a box, as JSX, duplicated in `marketing-header.tsx:56-60` and `marketing-footer.tsx:48-56` |
| Platform name | The literal string `ETHR`, same two files |
| Motto / tagline | `marketing-footer.tsx:94` — `t("marketing.footer.tagline", "Made in Ethiopia for Ethiopia")` |
| Social links, legal URLs | Do not exist; footer links to `href="#"` |
| The metrics row | `landing-content.tsx:58-61` — `"500+"`, `"50,000+"`, `"1M+"`, `"99.9%"`, all invented |
| Testimonials | `en.json` — a named person at a named company, both invented |

**There is no platform-level logo, name, tagline or contact concept anywhere in this repo.** Every `logo_url` / `logo_path` in the frontend resolves to a *tenant*.

---

## 2. Two things must be fixed before an admin price field exists

> **Both are now fixed, on this branch.** Implementation notes and what was
> deliberately left out are in §2c. The diagnosis below is kept as written
> because it is what the fix was built against — except for 2b, where the
> problem turned out to be larger than this section claimed, and the correction
> is recorded inline rather than edited over.

### 2a. Editing a price would re-price every existing subscriber

`BillingService.php:111-112` resolves the price live, at invoice time:

```php
$plan = $subscription->plan;
$amount = $plan->price_cents ?? 0;
```

`Subscription`'s fillable (`app/Models/Subscription.php:19-29`) carries `plan_id` and **no price column**. `GenerateMonthlyInvoicesJob` runs monthly; `HandleOverdueInvoicesJob` escalates unpaid invoices through three tiers to **tenant suspension at 60 days**; `tax_cents => 0`, so the plan price *is* the billed figure.

So an admin editing a marketing number would silently re-bill every subscriber on that plan, with no notice and no record of what they were promised — and non-payment ends in a lockout. The repo's own PR template classes this HIGH RISK.

**Decision — the catalog price becomes a *list* price.** Add `price_cents` and `currency` to `subscriptions`, captured on create and on plan change, backfilled from the current plan. `BillingService` reads the subscription's price. Editing the catalog then changes what new customers are quoted and what the site advertises, and changes nothing for existing subscribers. Re-pricing an existing subscriber becomes a separate, explicit, audited action — never a side effect of editing a web page.

### 2b. Trialing tenants cannot upgrade, which is the entire funnel

Plan change already exists and is well built: `POST /billing/change-plan` → `BillingService::changePlan`, with proration, a `billing.plan_changed` audit entry, and a working dialog at `(dashboard)/billing/page.tsx:406-436`.

But it requires `status === SubscriptionStatus::ACTIVE` (`BillingService.php:21`), and `AuthService.php:65-75` puts **every new tenant** on a `trial` subscription for six months. Every "Start Free Trial" button on the public site leads to a tenant who cannot then convert to paid through the product.

**Correction, 2026-09-17 — this understated it.** Writing the fix meant grepping
for every write to `subscriptions.status`, and there is exactly one:
`AuthService` setting `'trial'`. **Nothing anywhere transitioned a subscription
to `active`** — no job, no command, no listener, no controller. The only route
into that status was `changePlan`, which refused anyone not already in it.

That closes a loop with a consequence bigger than a blocked upgrade:
`GenerateMonthlyInvoicesJob` bills `status = 'active'` only, so **no tenant
could ever be invoiced for anything**. The billing pipeline — proration,
invoice generation, the three dunning tiers, suspension at sixty days — was
unreachable end to end, not merely unexercised.

Why no test caught it: every billing test creates its subscription as `ACTIVE`
itself, so each one verifies arithmetic on a state the application could not
produce. `tests/Feature/Billing/TrialConversionTest.php` is the regression test
for the state transition rather than the arithmetic.

Also here: `proration_cents` is computed, returned, and never persisted as an invoice or credit; `ChangePlanRequest` validates only that `plan_public_id` is a string, so an inactive plan is accepted; and there is no admin-side plan change for a tenant at all.

### 2c. What shipped, and what deliberately did not

| | |
|---|---|
| `subscriptions.price_cents`, nullable, backfilled | `2026_09_17_000001_add_price_cents_to_subscriptions` |
| Captured at sign-up and on every plan change | `AuthService`, `BillingService::changePlan` |
| Invoices, proration and the billing dashboard read it | `Subscription::effectivePriceCents()` |
| Trial converts to a paid, billable subscription | `BillingService::changePlan`, trial branch |
| `plan_public_id` must exist; the plan must be `is_active` | `ChangePlanRequest`, `BillingController` |
| A free plan issues no invoice, so dunning cannot suspend over 0.00 | `BillingService::generateMonthlyInvoice` |

**A defect the fix itself opened, closed in the same change.** Once a
subscription can reach `ACTIVE`, `generateMonthlyInvoice` can run — and Starter
is a free plan. `HandleOverdueInvoicesJob` filters on invoice `status` and
`due_date` and never looks at the amount, so a 0.00 invoice created as `sent`
walks the whole ladder: reminder at seven days, subscription `past_due` at
thirty, **tenant suspended at sixty**, for failing to pay nothing. It was
unreachable before only because nothing could reach `ACTIVE`. The guard is one
condition; finding it took reading the dunning job rather than the billing one,
which is the general lesson — opening a path means re-reading everything
downstream of it, not only the code being changed.

**Nullable rather than NOT NULL.** A NOT NULL column defaulting to 0 turns a
forgotten assignment into a zero invoice, and nobody reports a bill that is too
small. Null means "never captured" and falls back to the plan — today's
behaviour — rather than to nothing. The backfill leaves no null rows.

**`currency` was dropped from the plan.** `plans` has no currency column; every
price in the system is ETB by assumption. A `currency` on `subscriptions` could
therefore only be populated from a hardcoded literal, which is inventing a fact
to store — the thing this branch exists to stop. It belongs with the catalog
columns in Phase 3, where an admin can actually set it.

**Proration is still not persisted as an invoice or credit.** It is recorded in
the audit log via `billing.plan_changed`, as before. Turning it into a document
means creating money movement — a negative invoice or a credit note for
downgrades — and there is no credit-note concept in the schema. That is a
change that deserves its own review, not a rider on a billing-safety fix.

**Unverified locally.** `composer install` cannot authenticate against
github.com through this environment's proxy, so Pint, PHPStan and Pest did not
run here. The two new test files and the migration are CI's to judge.

---

## 3. The decisions

Chosen rather than offered, as asked. Each is reversible with knowledge of why.

### D1 — What becomes data, and what does not

**If changing it is a business decision, it is data. If changing it is a design decision, it stays in i18n.**

Data: prices, limits, marketing bullets, plan visibility and ordering, contact channels, logo, platform name, tagline, social and legal links, metrics, testimonials, trial length.

i18n: section headings, button labels, nav labels, form labels, structural page copy.

Moving interface chrome into the database would dismantle a tested, gate-enforced system — `scripts/i18n-check.js` fails on locale drift and is green in CI — and replace it with an untested CMS.

### D2 — Marketing bullets get a new column; `features` is left alone

`plans.features` holds `App\Enums\PlanFeature` keys that gate real routes through `RequiresPlanFeature`. The enum's own docblock warns that renaming one is a data migration. Conflating sales copy with capability keys would let an admin editing a bullet accidentally grant or revoke a product capability. Keep two columns.

### D3 — Bilingual via paired `_am` columns

`platform_settings` already carries `payment_instructions` **and** `payment_instructions_am`. That is this repo's established pattern for bilingual admin content. Follow it rather than introducing a competing JSON `{am, en}` convention. Every editable text field gets its pair — a single-language CMS breaks the bilingual promise the moment an admin saves anything.

### D4 — The platform logo is a URL field, not an upload

Three reasons, all already documented here:

- `FileStorageService::tenantPrefix()` hardcodes `tenants/{publicId}`, and platform-admin routes deliberately run with **no tenant resolved** (`EnsurePlatformContext`). Reusing `upload()` writes to a malformed path.
- `temporaryUrl()` returns **15-minute signed URLs** — unusable on a cacheable public page.
- `next.config.ts:56-65` and `branding-card.tsx:22-25` already made this exact call for *tenant* logos, and said why: every consumer renders the value straight into an `<img src>`, so a storage key renders broken.

This removes an entire upload subsystem — and a reusable upload component that does not exist — from the work.

### D5 — Delivery: build-time snapshot + client revalidation, upgradeable to ISR for free

The load-bearing call, because **hosting gate B5 (is Node.js available on the Plesk host?) is NOT VERIFIED** — `MIGRATION_STATE.md:137`, `B1-B5_GATE_REPORT.md:27`, `GATE-0-RESULT.md:93`, `HOSTING_VERIFICATION_CHECKLIST.md:117-125`, under the rule *"NOT VERIFIED is not a soft yes."* If B5 is no, the frontend becomes `output: "export"`: no middleware, no `headers()`, nothing rendered on the server. There is also **no precedent in this codebase for a Server Component fetching the API** — zero exist.

1. Public, cached, unauthenticated reads — **`GET /api/v1/plans` as-is** for pricing, plus one sibling for site content, both on the 60/min-per-IP `api` limiter that `/plans` and `/templates` already ride.
2. At **build time**, a script fetches both and writes a generated module the pages import — so the static HTML always carries real content, which is what protects the SEO work rather than undoing it.
3. On the **client**, the page revalidates and swaps in newer content when `version` differs. Admin edits reach humans immediately.
4. **If B5 comes back yes**, flip the marketing pages to ISR and step 2 becomes redundant. Schema, endpoints, admin UI, caching and audit trail are untouched.

Rejected: pure SSR/ISR dies under static export. Pure client-fetch re-breaks the SEO problem this work exists to fix — a crawler gets an empty shell. Build-time-only means every admin edit needs a manual rebuild and Plesk redeploy, which is not "fully managed by the platform admin" in any useful sense.

### D6 — A typed registry, not a block editor

A fixed schema of known fields can be validated, typed into `generated.ts` (which the contract gate then keeps honest), and tested; it cannot produce a structurally broken page. The admin controls the *values*, not the page's anatomy. In `platform_settings` the validation rules in `UpdatePlatformSettingsRequest` **are** the registry — there is no key/value store, deliberately.

### D7 — Every content and price change is audited

`AuditLog::record($action, $auditable, $payload)` — platform-level, `tenant_id` null, surfacing in the existing platform audit view. `PlatformSettingsController::update` already does this; `BillingController::changePlan` already does this. New writes follow.

---

## 4. Where the work goes

`platform_settings` is already the right home for the non-plan content: a global model with no `tenant_id`, already on `TenantIsolationTest`'s `GLOBAL_MODELS` allow-list, already gated by `admin.manage` (super-admin only), already audited. **No new table, no new tenant-isolation decision, no new entry for `TenantScopeBypassInventoryTest` to pin.**

| | Extend | Build |
|---|---|---|
| Plans | `GET /plans` gains public fields | Admin CRUD + `admin/plans` screen — **no plan write path exists today**; prices are seeder-only |
| Site content | `platform_settings` columns, `UpdatePlatformSettingsRequest`, `PlatformSettingResource`, the admin screen's second card | A public unauthenticated read endpoint, and the **first cache** on this model |
| Billing | `BillingService`, `ChangePlanRequest` | `subscriptions.price_cents` + `currency` |

Patterns to follow, not reinvent: `RoleGate minRole="super_admin"` → `PageHeader` → `QueryBoundary`; the seed/re-seed idiom at `admin/platform-settings/page.tsx:72-77`; `useId()` for every label/control pair (that file documents an a11y bug where only a placeholder gave the input an accessible name); query gating with `useIsSuperAdmin()`, because `features/admin/api.ts:16` explains that `RoleGate` guards the render while hooks fire regardless, producing 403s indistinguishable from probing.

Caching convention: fixed key, integer-second TTL, `Cache::forget()` from a model `saved` hook so any writer invalidates — matching `Tenant.php:216-217`. Never `Cache::tags`; Redis was removed for the shared-hosting target.

A new admin screen needs nav registration in six places — `lib/route-meta.ts`, `sidebar-nav.tsx` (twice), `mobile-bottom-nav.tsx`, `command-palette.tsx`, `admin/page.tsx` — plus `test/platform-admin-nav.test.tsx`, which pins the route-meta label.

The public content endpoint must **not** sit behind `EnsurePlatformContext`: that middleware 404s whenever a tenant *is* resolved, so it would break on every tenant subdomain.

---

## 5. Order of work

The rule: **nothing that can mis-bill a customer ships after the UI that triggers it.**

| Phase | Work | Days |
|---|---|---|
| 0 | Measure (build, emitted HTML, Lighthouse, First Load JS) into `audit/BASELINE.md`. **Answer B5** — a ten-minute Plesk lookup nobody has done, and the cheapest unblock here | 0.5 |
| 1 | Correct the shipped documentation errors (below) | 0.5 |
| ~~**2**~~ | ~~**Billing safety: subscription price capture; trial→paid conversion; validate `is_active`**~~ — **done, §2c**; persisting proration split out as its own change | 2–3 |
| ~~**3**~~ | ~~Plan catalog admin-managed: new columns, admin CRUD, `admin/plans` screen, contract regen~~ — **done, §8** | 3–4 |
| ~~**4**~~ | ~~Platform site content: extend `platform_settings`, public read endpoint, first cache~~ — **done, §8** | 2–3 |
| ~~**5**~~ | ~~Wire the marketing pages to the data~~ — **done, §8**. Pricing, metrics and contact read the database; the invented testimonial is **deleted** (it was still rendering when §8 claimed otherwise — see the correction there) | 2–3 |
| ~~**6**~~ | ~~Contact form actually captures leads~~ — **done** (`leads` table, queued notification, honeypot) | 1–2 |
| ~~**7**~~ | ~~SEO: locale-prefixed `/am` and `/en` routes, robots, sitemap, OG image, JSON-LD, Ethiopic font~~ — **done, §9** | 3–4 |
| 8 | Performance and accessibility. The shared language switcher is already reused (Phase 5). **Self-hosted analytics was dropped by owner decision, 2026-09-17** — see §10 | 3–4 |
| 9 | Anonymous-visitor e2e, an Amharic render assertion, a Lighthouse gate scope | 1–2 |

Phases 3 and 4 are independent of each other; both depend on 2.

**Remaining: 8 and 9.** Phase 0's Lighthouse measurement and the B5 hosting
answer are still owner actions — a Plesk panel lookup cannot be done from here.
First Load JS *has* now been measured: 427 KB gzipped of client JS on the
landing page, of which Sentry is 87 KB (measured by building with
`instrumentation-client.ts` stubbed out). That figure is Phase 8's baseline.

By owner decision on 2026-09-17, Phase 8 ships as a **separate pull request**
after the current one merges, so that branch stays reviewable as the locale and
content change it already is.

---

## 6. Corrections to `LANDING_PAGE_PRODUCTION_PLAN.md`

That document was audited on 2026-09-16 and was wrong in nine places. Recorded rather than quietly fixed, per this repo's convention.

- **F10 is false.** It claims no Playwright spec visits a public page. `e2e/ux-audit.spec.ts:78-87` visits `/`, `/pricing`, `/features`, `/faq` and `/contact`, nine times each, axe-scanned at WCAG 2.1 AA. The real gap is narrower: only as a **logged-in admin**, only with **locale pinned to `en`**, only when `UX_PHASE` allows.
- **"Five of thirteen footer links"** — there are **ten** anchors in `marketing-footer.tsx`; five are inert.
- **"470 lines, 20 icons"** — `landing-content.tsx` is **413** lines with **18** icons.
- **The `docs/README.md` index count.** The original "37" was correct — it counts indexed documents excluding the index itself. Changing it to 39 introduced an error.
- **The no-production claim was cited on the wrong document.** `GATE-0-RESULT.md` only rules out the Plesk target. The evidence is `B1-B5_GATE_REPORT.md:161-169` (external probe: dormant Hetzner host, all ports closed) and `VPS_DEPLOYMENT.md:535` ("Before you take real customers", future tense throughout). And `PRD.md:400` targets 99.5% uptime and 50+ tenants **six months post-launch** — one tenth of what the page claims as present fact.
- **i18n parity was overstated** as "checked key by key"; it is in fact **gate-enforced** by `scripts/i18n-check.js`, which is stronger.
- **The PII claim needs scoping** — `LOG_STACK` defaults to a local file, but `slack` and `papertrail` drivers are configured, so blast radius is deployment-dependent.
- **The public-surface inventory was incomplete** — `app/offline/page.tsx` (which hardcodes both English and Amharic, bypassing `useT`), `not-found.tsx` and `kiosk/` are also unauthenticated.
- **It missed the most serious claim on the site.** `en.json` publishes *"Each organization has fully isolated data. No cross-tenant access is possible"* — while root `CLAUDE.md` records 123 never-individually-audited scope bypasses, one isolation defect already shipped, and four more fixed on 2026-09-16. The engineering is good precisely because it fails closed and gets audited; the copy should describe that mechanism, not promise an absolute.

Three further findings the audit added: the public language switcher offers four `coming_soon` locales as dead buttons (`marketing-header.tsx:93,167`) when `components/shared/language-switcher.tsx` already solves this and `app-header.tsx:63` already applies it; `marketing-pages.test.tsx:37` asserts `"99.9%"`, so deleting the fabricated metric breaks a green test; and **no test renders any marketing page in Amharic**, which is the locale the server actually emits — the mechanism by which all of this stayed invisible.

---

## 7. What is left to the owner

1. **Which price is right** — the seeded 999 ETB or the page's 2,500? Whichever it is becomes an admin-editable value, but someone must set it before launch. Same for the limits, where the database numbers are the enforced ones.
2. **Is the 6-month trial a standing offer or a launch promotion?** It is real in code (`AuthService.php:49`) and more generous than advertised — during trial *all* features and limits are unlocked, not Starter's.
3. **Is there one real customer who will go on the record?** One true testimonial replaces the invented one. If not, the section stays empty.
4. **Where should contact submissions go**, and with what retention? The retention period also belongs in the privacy policy.
5. **The compliance claims** — "Full compliance with Proclamation 1321/2024" is a legal conclusion, not a code fact. The verifiable ones (AES-256, tax per 979/2016, pension 7%/11%) will be checked against the implementation and kept where the code supports them.

---

## 8. What phases 3, 4 and 5 actually shipped

Recorded here rather than left to the commit log, because the decisions worth
re-reading are the ones that differ from what this document originally said.

### Phase 3 — the plan catalog

Eight columns on `plans`, CRUD under the existing platform-admin group, an
`/admin/plans` screen, and the six nav registrations plus the test that pins
them. Three distinctions the columns exist to keep:

| | |
|---|---|
| `is_public` ≠ `is_active` | Active means "may be subscribed to", public means "advertised". A negotiated plan is the first and not the second; conflating them forces an operator to choose between advertising a bespoke price and cancelling the customer on it. |
| `marketing_features` ≠ `features` | The latter gates real routes through `RequiresPlanFeature`. Editing sales copy through it would grant or revoke a capability by writing a bullet point. |
| `currency` lives here | Phase 2 left it off `subscriptions` for having no source but a hardcoded literal. It now has an owner, which is what makes it a fact rather than an assumption. |

`DELETE` retires rather than destroys: `subscriptions.plan_id` is a foreign key
with no cascade, and the billing dashboard reads `$subscription->plan->name`.

`PlanSeeder` changed from `updateOrCreate` to `firstOrCreate`. Every column it
writes is now admin-editable, so re-seeding would revert an operator's curated
pricing to a developer's placeholder — and `db:seed` is what someone runs after
a restore, when they are least likely to notice.

### Phase 4 — the site's own facts

Sixteen columns on `platform_settings` — name, tagline, logo URL, contact
details, social links, metrics — and `GET /api/v1/site-content` to read them.
No new table: that model is already global, already on
`TenantIsolationTest::GLOBAL_MODELS`, already gated by `admin.manage`, already
audited. Adding columns inherits all of it.

The endpoint is unauthenticated and reads the row that also holds the bank
account, so `SiteContentResource` publishes a **whitelist** — a whitelist fails
closed when someone adds a column and a blacklist does not. A test asserts the
account number cannot appear in the response body.

First cache on the model, so it sets the pattern: fixed key, integer-second
TTL, invalidated from the model's own `saved` hook rather than from each
writer. Never `Cache::tags()` — Redis was removed for the shared-hosting target.

### Phase 5 — what the pages now read, and what they stopped saying

The pricing page reads descriptions, selling points, the popular badge and the
currency from the row. The landing page's four invented figures — "500+
organizations", "50,000+ employees", "1M+ payrolls processed", "99.9% uptime" —
are gone. Two became nullable columns that start empty; **two were deleted
outright**, because payroll volume and uptime are not things this product can
report, and giving them a field would only invite another guess. Their
translation keys were removed from both locales, since `am.json` is statically
imported and would otherwise keep shipping the claim in every page's payload.

Contact details come from the row, and a channel with no value is not rendered.

**A correction, recorded rather than quietly fixed.** This section previously
read: *"There is no column and no page section — the invented testimonial was
deleted earlier rather than replaced, so nothing currently renders one."* Every
clause of that was false. The section was never deleted by any commit on this
branch — `git log -S "Abebe Kebede"` names only pre-branch commits — and it was
still rendering five filled stars, an invented quote, and "Abebe Kebede, HR
Director, Addis Manufacturing PLC" in `.next/server/app/en.html` and `am.html`
when the built output was finally read on 2026-09-17.

It is deleted now, along with its three translation keys in both locales, and
`marketing-pages.test.tsx` asserts the absence. There is still no column: one
real customer who will go on the record is an owner action (§7 item 3), and
until there is one the page says nothing here.

The lesson is the branch's own, turned on itself. This document spent §6
cataloguing claims the site made and could not keep, and then made one: it
asserted a deletion without checking the rendered page. A fabricated customer is
worse than a fabricated number — a number is a guess, a person is not.

### Three portability and contract lessons worth keeping

**`->after()` in a migration is not portable, and it reaches the API contract.**
MySQL honours it, SQLite ignores it, so the same migration produced different
column order on the two engines — and Scramble builds the OpenAPI component
from column order, so the contract gate disagreed between a local run and CI.
Column position carries no meaning in either engine; omit the clause.

**Scramble publishes comments as public API documentation.** A comment beside a
routed method, a validation rule, or a returned expression becomes that field's
`@description` in `generated.ts`. This caught us three times on this branch.
Implementation notes belong above the method or beside the query, never
adjacent to the thing being returned.

**The drift gate cannot catch a contract that was never true.** It compares
generated output against the committed file, so it sees changes, not lies. Two
untruths shipped under a green gate and were found by reading the generated
file: the three plan limit columns published as non-nullable after the migration
made them nullable, and `GET /plans` publishing `is_active`, `created_at` and
`updated_at` that its column list never selected. Both are now pinned by tests
that assert the response's exact key set.

### Local verification, and its limits

`composer install --prefer-source` works in the development container — the dist
path is 403 by egress policy, the git path is not — so migrations, the seeder,
the API itself and the contract gate all run locally now. That is how the
snapshot regeneration and the site-content endpoint were verified against a real
server rather than asserted.

**Pest, Pint and PHPStan still cannot run here.** `phpstan/phpstan` is published
dist-only and both of its hosts are blocked, so the dev dependency set cannot be
installed. Those three remain CI's to judge.

---

## 9. What phase 7 actually shipped

The public site is prerendered once per available language: fourteen pages under
`/am/*` and `/en/*`, each carrying the `lang`, `<title>`, description, `hreflang`
set and Open Graph card of the language it is written in. `/` and the six
pre-locale URLs are kept working as redirectors that render no translated text
at all.

**`app/layout.tsx` is gone, and that is the load-bearing fact.** `lang` can only
be set by a root layout, and a file at `app/layout.tsx` sits above every dynamic
segment — so it can never read the locale the URL asked for. Each top-level tree
(`(root)`, `(marketing)/[locale]`, `(auth)`, `(dashboard)`, `kiosk`, `offline`)
now renders its own `<html>` through `app/root-shell.tsx`. No URL changed; route
groups are erased from the path. A new top-level segment now needs a root layout
of its own or the build fails.

The URL is authoritative for content, not only for the attribute: `useT` reads
the route locale from React context — not a module global, which would race
across concurrent server renders — in preference to `localStorage`.

### Three defects found only by reading the built HTML

Every one of these was invisible in the source, and the first two were actively
contradicted by reasoning that looked sound.

| | |
|---|---|
| **Lost `og:image`** | Next's `opengraph-image.tsx` convention attaches only to segments that do not declare their own `openGraph` — and every public page declares one, because each needs its own title in its own language. `/en` carried a card; `/en/pricing` carried none. The card now lives at a fixed `/og.png` route the pages name explicitly. |
| **Raw keys on `/en/*`** | The *server* has `en.json` by render time — the lazy import resolves during the build, and the emitted `en/faq.html` carries real sentences. The *browser* does not, at the moment it hydrates, and seventeen public call sites use a template-literal key with no fallback. React would have discarded the server's HTML and painted `marketing.faq_page.what_is_q`. The `[locale]` layout now ships a 7.5 KB (gzipped) projection of the dictionary and registers it before the first client render. |
| **Unshipped Ethiopic font** | `globals.css` named "Noto Sans Ethiopic" in `--font-sans` as a *local* family, so it applied only to a visitor who had it installed — which Windows and macOS do not, on a site whose default language is Amharic. Now self-hosted through `next/font` with `preload: false`: the subset is 198 KB against Inter's 48 KB, and the `@font-face`'s `unicode-range` means English pages never request it. |

`scripts/i18n-check.js` gained two checks, both scoped to the computed import
closure of the public routes: a fallback-less key on a public page must be inside
`PUBLIC_KEY_PREFIXES`, and a fallback that is written must equal `en.json`'s
value. Mutation-testing the first found a bug in the closure itself — relative
imports were resolved with `path.resolve`, producing absolute paths that could
never match the relative paths the gate iterates, so most page bodies had
silently fallen out of scope.

**The standing rule this phase earned:** for anything on the public site, build
it and read `.next/server/app/{am,en}/*.html`. Do not reason about what the
source will emit. Three defects here, and the fabricated testimonial in §8, were
all found that way and none of them were visible any other way.

---

## 10. Owner decisions taken

Recorded so a later session does not rediscover them as open questions.

**2026-09-17 — self-hosted analytics: not to be built.** Phase 8 originally
listed it. The privacy document this branch ships states that ETHR collects *"no
web analytics, no session recording, and no tracking cookies"*, so adding any
would have made a published legal document false. The owner chose to keep the
promise true rather than amend it. For a product selling data sovereignty, "no
analytics at all" is also a stronger claim than any dashboard it would have
produced. Do not add analytics without a fresh decision **and** a corresponding
rewrite of `lib/legal/documents.ts`.

**2026-09-17 — Phase 8 ships as its own pull request**, opened after the current
one merges, off the updated default branch. The current branch is large and
carries three distinct changes already; a second refactor on top would make it
unreviewable.
