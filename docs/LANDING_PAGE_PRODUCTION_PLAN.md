# Public Landing Page — Production Readiness Plan

**Prepared:** 2026-09-16 · **Scope:** the unauthenticated public site — `/`, `/features`, `/pricing`, `/faq`, `/contact`, and the header and footer they share.

**Method, stated up front because this repository has been burned by the alternative:** every finding below was read out of the source, and the file and line are given so it can be checked. **None of it was measured against a build.** `src/node_modules` is not installed in the session this was written in, so no `next build` ran, no HTML was inspected, no Lighthouse score was taken. Where a finding depends on what the build actually emits — F1 above all — the plan's first step is to build and read the output, not to start fixing.

That distinction is the whole point of [`audit/BASELINE.md`](audit/BASELINE.md), and this document is not exempt from it. Nothing here is tagged `[verified]`.

---

## What the public site is today

| | |
|---|---|
| `src/src/app/page.tsx` | 17 lines. Server component: exports `metadata`, renders `<LandingContent />` |
| `src/src/app/landing-content.tsx` | 470 lines, `"use client"`. Eight sections: hero, social proof, features, how-it-works, industries, trust, testimonial, CTA |
| `src/src/app/(marketing)/` | `features`, `pricing`, `faq`, `contact` — each a thin server `page.tsx` plus a `"use client"` content component (109–257 lines) |
| `src/src/components/layouts/marketing-header.tsx` | 214 lines. Sticky header, nav, language switcher, mobile menu |
| `src/src/components/layouts/marketing-footer.tsx` | 120 lines. Three link columns, brand column, Tibeb pattern strip |
| `src/src/test/marketing-pages.test.tsx` | 6 Vitest cases across landing, pricing, FAQ |
| i18n | `en.json` and `am.json` both carry every `marketing.*` key the pages read — checked key by key for the ones called without a fallback string. `om`/`ti`/`so`/`sid` are stubs |

The visual work is done and it is good. The design system is used correctly throughout — semantic tokens, no raw hex, consistent spacing scale, dark mode via `next-themes`. **What is missing is not design. It is the layer underneath a marketing site: what a crawler sees, where a lead goes, and whether the claims are true.**

---

## Findings

Ordered by whether they block a launch, not by effort.

### P0 — do not launch with these

#### F1. The prerendered HTML is Amharic; the `<html lang>` and the `<title>` are English

Four facts that only matter together:

- `translations.ts:49` — `DEFAULT_LOCALE = "am"`.
- `useT.ts:23` — `useSyncExternalStore`'s `getServerSnapshot` returns `DEFAULT_LOCALE`.
- `translations.ts:1` — `am.json` is a **static** import, not one of the lazy ones. It is in memory on the server.
- `layout.tsx:36` — `<html lang="en">`, hardcoded.

So the server renders the landing page in Amharic, inside a document that declares itself English, under an English `<title>` and `<meta name="description">` from `page.tsx:4-13`. `Providers` corrects the `lang` attribute in `HtmlLangSync` (`providers.tsx:16-26`) — in a `useEffect`, after hydration, which a crawler does not wait for and a link-preview fetcher never reaches at all.

The consequence is not subtle: Google, Telegram, LinkedIn and Facebook all see English metadata wrapped around Amharic body copy, tagged as English. A visitor who set English gets a first paint of Amharic before the store settles.

This went unnoticed because the tests look the other way — `marketing-pages.test.tsx` registers `en` in its setup and asserts English strings, so the suite exercises a locale the server never produces by default.

**Verify before fixing.** `npm run build && npx serve .next` (or read `.next/server/app/index.html`) and look at the actual bytes. Everything below assumes the reading is right; the build is what settles it.

#### F2. The contact form goes nowhere

`api/app/Http/Controllers/Api/V1/ContactController.php:18`:

```php
Log::channel('stack')->info('Contact form submission', $data);
```

That is the entire handler. It logs and returns 201. The UI then tells the sender *"Message sent successfully"* and *"We will get back to you within 24 hours"* (`contact-content.tsx`). **Every sales lead from the public site is lost**, and the person who sent it has been told otherwise.

There is a second problem in the same line. `$data` is name, email, phone, organization and message — written in plaintext into application logs, which are not a system with a retention policy, an access boundary, or a deletion path. The trust section of the landing page sells *"Full compliance with Ethiopian data protection law"* about forty lines away from the form that does this.

#### F3. The numbers and the testimonial are not true

| Claim | Where |
|---|---|
| "Now serving 500+ Ethiopian organizations" | `landing-content.tsx:88` |
| "500+" organizations | `landing-content.tsx:58` |
| "50,000+" employees managed | `landing-content.tsx:59` |
| "1M+" payrolls processed | `landing-content.tsx:60` |
| "99.9%" uptime | `landing-content.tsx:61` |
| "Abebe Kebede, HR Director, Addis Manufacturing PLC" + quote | `landing-content.tsx:326-350` |

[`deployment/GATE-0-RESULT.md`](deployment/GATE-0-RESULT.md) states it plainly: *"Nothing in this repository has ever touched the Ethio Telecom account."* There is no production deployment, so there are no organizations, no payrolls processed, and no uptime to have measured — 99.9% is an availability commitment printed on a site with zero days of availability data.

The testimonial is a different and sharper exposure than the round numbers: it attributes words to a named individual at a named company. Both are also translated into Amharic (`am.json`), which means the false claim ships in two languages.

These are not a content-polish item. They are the kind of thing that is cheap to fix now and expensive to have shipped.

#### F4. No privacy policy, no terms

`marketing-footer.tsx:33-34` — Privacy and Terms both link to `href="#"`. Neither page exists anywhere under `src/src/app`.

The site collects name, email, phone and organization through the contact form and offers self-serve registration at `/register`. It also makes an explicit data-protection compliance claim in the trust section. A privacy policy is the document that claim points at, and right now it points at the current page.

#### F5. Five of thirteen footer links are inert

`marketing-footer.tsx:24-26, 33-34` — About, Blog, Careers, Privacy, Terms are all `href="#"`. Clicking any of them does nothing visible, which reads as a broken site rather than an unfinished one.

---

### P1 — the launch works but underperforms

#### F6. No `robots.txt`, no sitemap, no Open Graph image, no canonical

`find src/src/app -name 'sitemap*' -o -name 'robots*' -o -name 'opengraph-image*'` returns nothing.

`page.tsx:8-12` sets `openGraph.title`, `.description` and `.type` — and no `images`, no `url`. `layout.tsx` sets no `metadataBase`, so even a relative image path could not be resolved into the absolute URL the OG spec requires. Every share of this site on Telegram — the dominant sharing channel for the Ethiopian market this product targets — renders as a bare text card.

Also absent: `alternates.canonical`, and any `hreflang` pairing of the `am` and `en` renderings. No Twitter card metadata.

#### F7. No structured data

No JSON-LD anywhere. The obvious candidates are `Organization` on the landing page, `FAQPage` on `/faq` (which is a literal list of questions and answers), and `SoftwareApplication` with `Offer` on `/pricing` (which has three tiers and a real ETB price).

#### F8. The entire landing page ships as client JavaScript

`landing-content.tsx:1` is `"use client"`, and it imports 20 icons from `lucide-react`. Every one of the eight sections is static markup — no state, no effects, no handlers. The only genuinely interactive things on the page are the header's language dropdown and mobile menu.

The fix is structural rather than clever: make the sections server components, keep `MarketingHeader` as the client island. The same applies to `features-content.tsx` and the non-form parts of the other three pages.

#### F9. No web analytics, and therefore no way to know if any of this works

Nothing in `src/src` reports page views. (`grep` hits for "analytics" are the authenticated dashboard feature, not instrumentation.) Launching a marketing site with no measurement means the next iteration of it is guesswork.

Constraint worth stating before a vendor is chosen: the trust section claims *"No data leaves the country."* A third-party analytics script contradicts that claim on the very page that makes it. Self-hosted and cookieless — Plausible CE or Umami — keeps the two consistent and avoids needing a cookie banner.

#### F10. The public site has no end-to-end coverage at all

Thirteen Playwright specs. Not one visits `/`, `/pricing`, `/faq` or `/contact` — `accessibility.spec.ts` covers `/login` and twelve authenticated routes, and stops there. The most-visited page in the product is the least tested.

#### F11. Amharic renders in a fallback font

`globals.css:19-20` puts `"Noto Sans Ethiopic"` in the family stack, but `layout.tsx:2` loads only `Inter` through `next/font`, and `globals.css` has zero `@font-face` rules. Noto Sans Ethiopic is therefore a *system* font reference: present on most Android and modern Linux, absent on plenty of Windows installs, where Ge'ez text drops to whatever the browser can find.

Given F1 — Amharic is the default first paint — this is the typography most visitors see first, and it is the only typography on the site that was not chosen.

---

### P2 — polish, worth doing in the same pass

- **F12.** The header language switcher (`marketing-header.tsx:96-129`) is a `<button>` and a `<div>`: no `aria-expanded`, no `role="menu"`/`menuitem`, no arrow-key or Escape handling. The mobile toggle (`marketing-header.tsx:159`) sets no `aria-expanded` either, and the opened menu is not focus-trapped.
- **F13.** The hero is text-only. No product screenshot, no interface preview — nothing that shows a visitor what they would be buying.
- **F14.** No `loading.tsx` or route-level `error.tsx` for the `(marketing)` group; it falls back to the root boundary.
- **F15.** `landing-content.tsx:342-350` renders a single testimonial with no carousel and no second voice, which is a structural limit on the section once real customers exist to quote.
- **F16.** The `(marketing)` layout re-renders `MarketingHeader`/`MarketingFooter`, and `landing-content.tsx` renders its own copies because `/` sits outside that route group. Two sources of truth for the page chrome.

---

## The plan

Five phases. Phases 1 and 2 are launch-blocking; 3 and 4 are what make the launch worth doing; 5 is the part that keeps it from regressing. Each phase names what "done" means, because "improve SEO" is not a completion criterion.

### Phase 0 — measure first (half a day)

Nothing is fixed in this phase. It exists because F1 is the load-bearing finding and it has not been verified.

1. `cd src && npm ci && npm run build`.
2. Read `.next/server/app/index.html` (or curl the started server). Record, verbatim: the `<html lang>`, the `<title>`, and which language the `<h1>` is in.
3. Run Lighthouse against `/` on a cold production build — mobile preset, throttled. Record LCP, CLS, TBT, and the SEO and Accessibility scores. **These are the baseline numbers Phase 5 is measured against, and there are none today.**
4. `ANALYZE=true npm run build` and record the `/` route's First Load JS. That is the number F8 is trying to move.
5. Write all of it into [`audit/BASELINE.md`](audit/BASELINE.md) as a new section, tagged `[verified]` — the only claims in this whole document that will carry that tag.

**Done when:** BASELINE has measured values for the landing page, and F1 is either confirmed or withdrawn on evidence.

### Phase 1 — truth and the legal floor (2–3 days)

The launch-blocking content work. Deliberately first: it is the cheapest phase and the one with actual exposure attached.

1. **Strip every unverifiable claim** (F3). Remove the hero badge's "500+", and replace the four social-proof metrics with something true. Suggested substitutes that are claims about the *product* rather than about *traction*: offline-capable attendance, Ethiopian calendar and payroll-tax rules built in, bilingual by default, per-tenant data isolation. Delete the fabricated testimonial section outright — an empty slot is better than an invented person — and keep the component so it can return when a real customer says a real thing.
2. **Delete or substantiate "99.9%"**. If it stays anywhere, it belongs in an SLA on the Enterprise tier, not as a headline metric.
3. **Write the privacy policy and terms** (F4) as real routes under `(marketing)`, in English and Amharic. The privacy policy must describe what the contact form collects and how long it is kept — which is a question Phase 2 has to answer anyway.
4. **Resolve the remaining footer links** (F5). About gets a page or the link goes. Blog and Careers go until they exist; an absent link costs nothing and a dead one costs trust.
5. Mirror every copy change into `am.json` and `en.json` together. The i18n gate (`scripts/i18n-check.js`) will catch a key that exists in one and not the other; it cannot catch a claim that is false in both.

**Done when:** no statement on the public site asserts a fact the repository cannot support, and Privacy and Terms are live in both languages.

### Phase 2 — make the contact form real (1–2 days)

`ContactController` (F2) needs three things it does not have:

1. **Persistence.** A `contact_requests` table, so a lead survives a log rotation. It is a platform-level record with no tenant — which means it must **not** use `BelongsToTenant`, and every read of it belongs behind `admin.manage` on the platform host. Note this explicitly in the model, because the repository's default assumption is the opposite (root `CLAUDE.md` §4).
2. **Notification.** A queued `Notification` to a configured sales address. Queued, not synchronous — a mail failure must not turn a successful capture into a 500. Remember that jobs run with no HTTP tenant context and `CurrentTenant` is now `scoped`: this notification must not assume a tenant is resolved.
3. **Stop logging the PII** — drop `$data` from the log line, or reduce it to a request id. The row in the database is the record now.

Add spam protection. The route already has `throttle:auth` (`routes/api.php:125`), which is rate limiting, not bot defence — a honeypot field plus a minimum time-to-submit costs nothing and needs no third party, which matters given the "no data leaves the country" claim.

Tests: one that asserts a submission persists a row, one that asserts the notification is dispatched, one that asserts the response is still 201 when mail is down, and one that asserts the log line no longer contains the email address.

**Done when:** a submission on the deployed site produces a row and an email, and a `grep` of the logs for the sender's address finds nothing.

### Phase 3 — the crawler and the share card (2–3 days)

1. **Fix the locale/metadata mismatch** (F1). Decide the default deliberately — for this market Amharic-default is defensible, and it should then be *declared*, not accidental:
   - Set `metadataBase` in `layout.tsx`.
   - Make `<html lang>` match what is actually rendered, server-side. If the default stays `am`, say `lang="am"` and stop relying on `HtmlLangSync` for the first paint.
   - Give `page.tsx` metadata in the language the server renders, plus `alternates.languages` for the other.
   - The durable version of this is locale-prefixed routes (`/am`, `/en`) with `generateStaticParams`, which makes each language independently crawlable and fixes the flash of the wrong language as a side effect. Bigger change; worth scoping separately rather than bolting onto this phase.
2. **Add `robots.ts` and `sitemap.ts`** (F6). Both are App Router file conventions — a few dozen lines. `robots.ts` must disallow `/dashboard`, `/settings`, `/admin` and the rest of the authenticated tree.
3. **Add an OG image** — `opengraph-image.tsx` using `next/og`, so it is generated at build rather than maintained as a binary. Add `twitter` metadata alongside it. Test the result against Telegram's and LinkedIn's actual preview fetchers, not a validator.
4. **Add JSON-LD** (F7): `Organization` on `/`, `FAQPage` on `/faq`, `SoftwareApplication` + `Offer` on `/pricing`.
5. **Load Noto Sans Ethiopic through `next/font`** (F11) with the Ethiopic subset, so the default language of the site is rendered in a font the site actually ships.

Note for the build: if [`deployment/GATE-0-RESULT.md`](deployment/GATE-0-RESULT.md) row B5 comes back *no Node.js*, the frontend becomes `output: "export"`. Every item in this phase survives that — `robots.ts`, `sitemap.ts`, `opengraph-image.tsx` and JSON-LD all emit at build time. **Phase 3 is safe to do before B5 is answered**, which is not true of anything that needs a request at runtime.

### Phase 4 — performance, conversion, measurement (3–4 days)

1. **Convert the static sections to server components** (F8). `landing-content.tsx` splits into section components; `MarketingHeader` stays the client island. Unify the chrome while in there (F16) so `/` and the `(marketing)` group share one header and footer rather than two.
2. **Add the hero visual** (F13). A real screenshot of the attendance or payroll screen, `next/image`, explicit dimensions so it costs no CLS.
3. **Self-hosted analytics** (F9) — Plausible CE or Umami, with the CSP `connect-src` in `next.config.ts` widened to exactly that origin and nothing more. Instrument the three events that matter: landing view, `/register` click, contact submit.
4. **Fix the header's accessibility** (F12): `aria-expanded` on both toggles, `role="menu"`/`menuitem` and arrow/Escape keys on the language dropdown, focus trap and restore on the mobile menu.
5. Add `loading.tsx` and an `error.tsx` for the `(marketing)` group (F14).

**Done when:** `/` First Load JS is materially below the Phase 0 number, and Lighthouse mobile Performance and Accessibility are both ≥ 90 on a cold production build.

### Phase 5 — lock it in (1–2 days)

Everything above is undone by the next change unless a gate holds it.

1. **Playwright spec for the public site** (F10) — `e2e/marketing.spec.ts`: each of the five pages renders its `h1`, every nav and footer link resolves to a real route (this catches a regression to `href="#"`), the contact form submits and shows the success state, and the language switcher swaps the rendered language.
2. **Extend `e2e/accessibility.spec.ts`** to the five public routes. They are the only pages in the product a random member of the public will ever load; they should be the *first* axe run, not the absent one.
3. **A test that asserts the served locale matches `<html lang>`.** This is the specific regression F1 is, and it is the one the current suite is structurally unable to see, because it renders in `en` while the server renders `am`.
4. **Wire a Lighthouse CI budget** into `scripts/gates.sh` as its own scope — `./scripts/gates.sh lighthouse` — outside the full sweep, for the same reason `security` and `performance` are outside it: it needs a built app and a browser, and the sweep must stay runnable on a fresh clone.
5. Add the measured results to [`audit/BASELINE.md`](audit/BASELINE.md).

---

## Sequencing

Phase 0 gates everything. After it:

- **Phases 1 and 2 are independent** — one is frontend copy and two new routes, the other is a backend controller, a migration and a notification. They can run at the same time.
- **Phase 3 depends on Phase 1** only in that it should not generate an OG image and a sitemap for copy that is about to be rewritten.
- **Phase 4 depends on Phase 3** for the server/client split, which is easier once the metadata and locale decisions are settled.
- **Phase 5 last**, because a test written against copy that is still moving is a test that will be deleted.

Roughly two weeks of focused work, with Phases 1 and 2 overlapping.

---

## Explicitly not in scope

- **A CMS or a blog.** The footer links to a blog; the plan removes the link rather than building the blog. Adding a content system to serve three posts is the wrong trade.
- **Locale-prefixed routing (`/am`, `/en`).** Named in Phase 3 as the durable answer and deliberately deferred — it touches routing for the whole app, not just the public site, and the launch does not need it.
- **A/B testing.** No traffic to test with. Phase 4's analytics is the prerequisite; revisit after there is a baseline.
- **Redesign.** The visual work is good. Nothing in this plan changes the design language, and the phases are ordered so that none of them needs to.

---

## Questions only the owner can answer

These are business decisions, and the plan stalls at Phase 1 without them.

1. **Is the 6-month free trial real?** It appears four times on the landing page and again as the Starter tier on `/pricing`. If it is a launch promotion rather than a standing offer, the copy needs to say so and the terms page needs to define it.
2. **Is there a single real customer who will go on the record?** One true testimonial with a real name replaces the invented one. If not, the section stays out until there is.
3. **Where should contact submissions go?** Phase 2 needs a destination address and a retention period — the retention period also goes into the privacy policy.
4. **Does the "No data leaves the country" claim bind the marketing site too, or only tenant data?** The answer decides whether Phase 4 can use any hosted analytics at all, and whether Google Fonts can keep serving Inter (`layout.tsx:2` currently fetches it at build, which is fine; a runtime CDN would not be).
5. **Amharic or English by default?** F1 has to be resolved either way, but which way is a market decision, not an engineering one.
