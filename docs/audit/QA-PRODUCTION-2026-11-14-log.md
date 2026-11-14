# Production QA execution log, 2026-11-14

Pack: [`docs/production testing/`](<../production testing/README.md>), sixth edition (this branch). Format: [10, section 2](<../production testing/10-TEMPLATES-AND-REFERENCE.md#2-execution-log>).

| | |
|---|---|
| Release under test | `v2026.11.14.3`, `release.json` commit `d591fb2ad33c` (#723), built 2026-11-14 05:23:47Z. `production` carries `v2026.11.14.4` (#724, docs only), not deployed and not needed |
| Organisation | None signed in. Public pages of QA Audit (`qa-audit`), Syntax Technology (`syntaxtech`) and the marketing site |
| Persona | None. No saved sessions under `src/e2e-production/.auth/`. In Chrome, checked by reading only: `qa-audit.ethr.et/login`, `www.ethr.et/login` and `admin.ethr.et` (13:25) each showed their sign-in form, so no QA Audit, apex or platform session was held. Other organisations' hosts were not checked. This is the owner's everyday Chrome, used today for Plesk; it is not the dedicated ETHR QA profile of 11, section 6.1, which is why only public pages were read in it |
| Browser | Scripted: guarded Playwright (`playwright.production.config.ts`, `PW_CHANNEL=chrome`, service workers blocked). Agent: Chrome, unguarded, signed out, public pages only. A single `curl` GET or HEAD where a case asks for one |
| Tester | Playwright and Claude Code; to be signed by the owner |

Read-only. Nothing was written to production in this pass: no POST, no form submitted, no secret path
probed (S-05, SEC-004 and SEC-006 are left to a person, 01 rule 10).

**Coverage before this pass.** `scripts/qa-pack-coverage.sh d591fb2ad33c` listed 13 user-visible PRs
(#707 to #723) cited nowhere in the fifth edition, and `02-UAT-GUIDE.md` had not changed since
2026-10-29. The sixth edition on this branch writes cases for all 13 and brings 02 up to date. Rows
marked "new" below were run for the first time today.

## Release smoke (10, section 3)

The first scripted run (12:26) failed to launch: the bundled Playwright browser is not installed on
this machine, so nothing was sent. The rows below are the re-run on the installed Chrome.

| Case ID | Result | Tester | Time (EAT) | Persona | Language | Notes / defect link |
|---|---|---|---|---|---|---|
| S-01 | Pass | Playwright | 12:27 | | en | live commit `d591fb2ad33ca5a046f1485b1672ebd78b284efa` = `EXPECTED_COMMIT`, built 2026-11-14T05:23:47Z |
| S-02 | Pass | Playwright | 12:27 | | en | healthy, schema current, scheduler running. Failed-jobs growth not compared (one reading) |
| S-03 | Not run | | | | | No script; a new route is checked by hand |
| S-04 | Pass | Playwright | 12:27 | | en | redirects |
| S-05 | Not run | | | | | Secret probes are a person's (01 rule 10); excluded with `--grep-invert` |
| S-06 | Pass | Playwright | 12:27 | | en | `/en`, `/am`, `/en/pricing`. Prices not checked by eye |
| S-07 | Pass | Playwright | 12:27 | | en | QA Audit sign-in page. Branding not checked by eye |
| S-08 to S-11, S-13 | Not run | Playwright | 12:27 | TA, HR, FIN, SUP, EMP, PA | en | No saved session (11, section 3) |

## Public pages and headers

| Case ID | Result | Tester | Time (EAT) | Persona | Language | Notes / defect link |
|---|---|---|---|---|---|---|
| SEC-001 | Pass | Claude Code | 12:50 | | | `http://www.ethr.et/` and `http://admin.ethr.et/` answer 301 to `https://`. Certificates on organisation subdomains not inspected |
| SEC-002 | Pass | Claude Code | 12:50 | | | `curl -I /api/v1/ping`: `X-Frame-Options: DENY`, `nosniff`, HSTS one year with `includeSubDomains`, CSP with `frame-ancestors 'none'`, Referrer-Policy, Permissions-Policy. The host adds a second, shorter HSTS header (`max-age=15768000`) after the app's; noted, outside the release |
| SEC-003 | Pass | Claude Code | 12:50 | | | `/en`, `/features` and `/` carry CSP, HSTS, `X-Frame-Options: DENY`, `nosniff` |
| SEC-005 | Pass (404 only) | Claude Code | 12:51 | | | Unknown API route: 404 `application/problem+json`, no stack trace or path. The 422 half needs a POST and was left to a person |
| SEC-007 | Pass (signed out) | Claude Code | 12:51 | | | `/api/docs`: 403 `application/problem+json`. Signed-in half not run |
| SEC-008 | Pass (headers only) | Claude Code | 13:15 | | | `/login` sends `X-Frame-Options: DENY` and `frame-ancestors 'none'`. The iframe itself was not loaded |
| SEC-021 | Pass (one route) | Claude Code | 12:51 | | | `/api/v1/employees` with `X-Tenant: qa-audit` and no `Accept`: 401 problem+json "You are not authenticated." The other routes were not repeated |
| MKT-001 | Pass (served HTML) | Claude Code | 12:58 | | en, am, om | Read as 36 sequential `curl` GETs, 1.5 s apart (one per page, no retries), not opened in a browser. All 33 pages (11 × 3) answer 200 with `<html lang>` matching the address and no raw translation key in the served text; `/ti`, `/so`, `/sid` answer 404. Translation completeness and images not read by eye |
| MKT-006 | **Fail** | Claude Code | 13:05 | | | robots.txt as expected; `sitemap.xml` has 33 entries with x-default; the organisations sitemap has `max-age=3600`. **The organisations sitemap names `en` and `am` alternates only, while every portal page declares `en`, `am`, `om` and x-default.** Defect D-1 below. The pack row expected the same two languages, so it is corrected too |
| MKT-007 | Pass | Claude Code | 13:00 | | | `GET /api/v1/trusted-by`: 7 organisations; Alice sec and Bob sec absent since they were hidden on 2026-11-14 |
| LAND-005 | Pass | Claude Code | 13:08 | | | syntaxtech `/login`: canonical, `hreflang` en, am, om, x-default, one JSON-LD block, `X-Robots-Tag: index, follow` |
| LAND-010 | Pass | Chrome, unguarded | 13:10 | | en | Live DOM after hydration: one robots tag. syntaxtech `/login` `index, follow` (header agrees); QA Audit `/login` (a trial below the listing floor) `noindex, nofollow` (header agrees) |
| LAND-011 | Pass (news only) | Chrome, unguarded | 13:10 | | en | syntaxtech `/news` and one story: one tag each, `index, follow`, agreeing with the header. No vacancy page checked |
| PERF-009 | Pass | Chrome, unguarded | 13:12 | | en | `/en`: no `/register` or `/login` resource loaded (#722) |
| PERF-012 | Pass | Claude Code | 13:15 | | | One script and one stylesheet from `/en`: `Content-Encoding: br` with `Accept-Encoding: br`, plain without; `Vary: Accept-Encoding` |
| L10N-012 | Pass (served HTML) | Claude Code | 12:58 | | om | `/om` and its ten pages answer 200 with `lang="om"` (from MKT-001) |
| L10N-022 | Pass (a to c) | Chrome, unguarded | 13:13 | | am, en, om | new (#723). `/am/no-such-page`, `/en/no-such-page`, `/om/no-such-page` each settle with the `<html lang>` of their address; the Oromo tab title reads "Fuulli hin argamne". Part (d) not run |
| PWA-011 | Pass (files) | Claude Code | 12:55 | | | new (#719). `/favicon.ico` 200; `/app-icons/favicon.svg` 200 (64-unit tile, title "ETHR", amber dot); `apple-touch-icon.png`, `icon-512.png`, `maskable-512.png`, `badge-72.png` 200; the manifest lists 72 to 512 `any`, two `maskable`, `badge-72` `monochrome`; `/en` links the ICO, the SVG and the apple icon. Install comparison not run |
| MKT-016 | Pass (light theme) | Chrome, unguarded | 13:18 | | en | new (#719). The `/en` header logo link and the `/login` panel logo link are both named "ETHR"; the panel shows the reversed lowercase e with the amber dot and the drawn "ethr." wordmark. Dark theme not checked |

**An observation for the owner, not a defect.** The organisations sitemap still lists `alice-sec` and
`bob-sec`, the two security-test organisations hidden from Trusted by today. That is consistent with
the rules: the sitemap follows "List this page in search engines" (LAND-005), and the platform's
showcase veto (CON-P-008) governs Trusted by only. To take them out of search as well, switch their
listing off or suspend them.

## Not run, and why

- **Every signed-in case.** There was no saved session and no QA persona signed in to Chrome. The
  sixth edition's signed-in reads (UI-010, PERF-013, DEV-032, DEV-037, the #708 menu cases, S-08 to
  S-13) wait for the owner to save sessions (11, section 3) or to sign the ETHR QA Chrome profile in
  to a QA Audit persona.
- **Mode W and X cases**, including every #707 to #720 device case that registers a device, assigns
  a badge or needs a terminal. Writes were not agreed for this session (01, section 9).
- **QA Audit's data still blocks K-05, K-48 and K-50** (2 employees, no supervisor, no Sick Leave
  type, payroll runs with no entries), as recorded on 2026-11-13.

## Defect drafts (10, section 1), for the tester to file

**D-1. The organisations sitemap leaves out Afaan Oromoo.** P3, search listing.

- Where: `https://www.ethr.et/sitemap-organisations.xml`, built by
  `api/app/Http/Controllers/OrganisationSitemapController.php:115-117`.
- Seen: each `<url>` carries `hreflang` `en`, `am` and `x-default`.
- Expected: the set the page itself declares. `api/app/Support/PortalHead.php:65-68` gives every
  portal page `en`, `am`, `om` and `x-default`, and Afaan Oromoo shipped on 2026-11-02
  (`App\Support\Locales::SHIPPED`). Search engines expect the two to agree, and the controller
  restates the language list as literals, which convention 9 forbids.
- Fix: build the alternates from `Locales::SHIPPED` in the controller. MKT-006 is corrected on this
  branch to expect `om`.

**D-2. The wizard's policy names carry an em dash, and have no Afaan Oromoo text.** P3, copy style.
Found reading the code while reviewing SET-024; not yet seen on production (the wizard needs a
signed-in, writable organisation).

- Where: the setup wizard's "Which rules apply" select, whose options come from
  `api/app/Services/Onboarding/PolicyProfileCatalog.php:46` and `:60`.
- Seen in the source: `'Ethiopia — private sector'`, `'Ethiopia — public sector (civil service)'`,
  and the same dash in `label_am`. There is no `label_om`.
- Expected: no em dash in English or Amharic interface text (house style, MKT-009; the frontend gate
  does not read backend catalogues, which is how these got through), and an Oromo label, since
  Afaan Oromoo is a shipped language.
- Fix: "Ethiopia, private sector" or "Ethiopia: private sector" (and the Amharic equivalent), plus
  `label_om`. SET-024 quotes the labels as they are today.
