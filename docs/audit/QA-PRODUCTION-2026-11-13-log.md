# Production QA execution log, 2026-11-13

Pack: [`docs/production testing/`](<../production testing/README.md>), fifth edition (baseline `c8cc3a4b2d`). Format: [10, section 2](<../production testing/10-TEMPLATES-AND-REFERENCE.md#2-execution-log>).

| | |
|---|---|
| Release under test | `v2026.11.13.10`, `release.json` commit `53fbe82b0b09` (#717), built 2026-11-13 18:07:55Z |
| Organisation | None signed in. Signed-out checks resolve QA Audit (`qa-audit`) |
| Persona | None (no saved sessions under `src/e2e-production/.auth/`) |
| Browser | Guarded Playwright (`playwright.production.config.ts`, `PW_CHANNEL=chrome`, fresh profile, service workers blocked) |
| Tester | Playwright, run by Claude Code |

Read-only. Nothing was written to production in this pass. `main` is two commits ahead of the live site
(`9f4d635e5a` #711, `e489b6d925` #719); `production` carries `v2026.11.13.11` (main@`9f4d635e5a`), not yet deployed.

## Release smoke (10, section 3)

| Case ID | Result | Tester | Time (EAT) | Persona | Language | Notes / defect link |
|---|---|---|---|---|---|---|
| S-01 | Pass | Playwright | 22:45 | | en | live-commit `53fbe82b0b09605213a1e1329dc3b2c243d2e4fc` = `EXPECTED_COMMIT`, built 2026-11-13T18:07:55Z |
| S-02 | Pass | Playwright | 22:45 | | en | healthy, schema current, scheduler running. Failed-jobs growth not compared (one reading) |
| S-03 | Not run | | | | | No script; a route new in this release is checked by hand |
| S-04 | Pass | Playwright | 22:45 | | en | redirects |
| S-05 | Not run | | | | | Opt-in secret probes (`PROD_PROBE_SECRETS=1`); firewall ban risk, 01 rule 10. Not an agent's case |
| S-06 | Pass | Playwright | 22:45 | | en | `/en`, `/am`, `/en/pricing`. Prices not checked by eye |
| S-07 | Pass | Playwright | 22:45 | | en | QA Audit sign-in page. Branding not checked by eye |
| S-08 | Not run | Playwright | 22:45 | TA, HR, FIN, SUP, EMP | en | No saved session (11, section 3) |
| S-09 | Not run | Playwright | 22:45 | PA | en | No saved session |
| S-10 | Not run | Playwright | 22:45 | TA | en | No saved session (9 module reads) |
| S-11 | Not run | Playwright | 22:45 | EMP | en | No saved session (4 self-service reads) |
| S-13 | Not run | Playwright | 22:45 | EMP | en | No saved session |

## Habru Woreda, read-only, on the owner's instruction

Habru Woreda Administration (`habru-woreda.ethr.et`) is a real organisation, not a QA one (01 rule 1). The owner
named it as the organisation for tonight's pass; it was used read-only, nothing staged, uploaded or saved.

| Case ID | Result | Tester | Time (EAT) | Persona | Language | Notes / defect link |
|---|---|---|---|---|---|---|
| S-08 | Not run | Playwright, guarded | 22:57 | TA | en | First attempt: every page answered with Habru's signed-out `/login`. Cause found at 23:11, neither product nor harness: the file was the **QA Audit** window's signed-out state, written when Claude stopped that window (Ctrl-C) at 22:56:19. The Habru window was never closed, so the owner's sign-in was never saved. See the note below |
| S-08 | Pass | Playwright, guarded | 23:12 | TA | en | Re-run on the Habru session written at 23:11:38: dashboard reached, 14 API reads all 200 (`auth/me`, the three dashboards, approvals, next steps, announcements and others), no write blocked, no page error |
| S-10 | Pass | Playwright, guarded | 23:13 | TA | en | All nine module reads: `/employees`, `/attendance`, `/leave`, `/payroll`, `/reports`, `/announcements`, `/settings`, `/billing`, `/devices`. Every API answer 2xx, no write blocked, no error toast or page error. Main visible on each; "with data" versus empty state not judged by eye |
| S-08 (HR, FIN, SUP, EMP), S-11, S-13 | Not run | | | | | Habru has no saved session for these personas |
| (import page) | Pass | Claude, Chrome, unguarded | 23:05 | TA (owner, `Nubmilli`) | am | `/employees/import` renders the staged-review import (file or paste, template, E.C./G.C. hire dates, 20 known columns). Reloaded: 8 API reads all 200, including `GET /onboarding/migration/sources`, which answered 500 once on 2026-11-12. Console clean. Only moved and read; no file chosen, nothing staged |

**Why the first signed-in run failed (for 11, section 3).** `playwright open --save-storage` writes the file when the
browser process ends, by closing the window **or by Ctrl-C**, and writes whatever that window holds, signed in or not.
Two traps followed. Stopping an unused window overwrote `ta.json` with a signed-out state for another organisation,
and the scripts loaded it as TA without complaint because they check only the file's age. Closing the page did not end
the process: the terminal still showed the command running. The tell is the terminal returning to its prompt. The
session file was deleted after the pass, and the scripts' temporary changes (Habru allowed in `env.ts`, an API-status
logger in `guard.ts`) were reverted.

Observation, not yet a defect: the page subtitle reads "from a CSV file" (ሰራተኞችን ከ CSV ፋይል በጅምላ ያስገቡ) while the body
accepts Excel or CSV.

## Signed-out cases, built-in browser (23:30 to 23:55)

The desktop app's built-in browser, on the owner's instruction: it has no write guard, so only pages were opened and
read (rule 3). Header cases used the single `curl -I` each case names (rule 5).

| Case ID | Result | Tester | Time (EAT) | Persona | Language | Notes / defect link |
|---|---|---|---|---|---|---|
| SEC-002 | Pass | Claude, `curl -I` | 23:32 | | | `/api/v1/ping`: `X-Frame-Options: DENY`, `nosniff`, HSTS one year with includeSubDomains, CSP with `frame-ancestors 'none'`, `base-uri 'self'`, `form-action 'self'`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy: camera=(self), microphone=(), geolocation=(self)`. Observation: a second `Strict-Transport-Security: max-age=15768000` (six months) follows, added by the host; browsers keep the first |
| SEC-003 | Pass | Claude, `curl -I` | 23:32 | | | `/en`, `/features` and `/` all carry CSP `default-src 'self'`, HSTS, `X-Frame-Options: DENY`; `X-Powered-By: PleskLin` only. **Pack is out of date:** `/` now carries the headers too, where SEC-003 says the host answers it without them |
| LAND-010 | Pass | Claude, built-in | 23:35 | | am | Hydrated DOM holds one robots tag each: `habru-woreda.ethr.et/login` `index, follow` (listed), `qa-audit.ethr.et/login` `noindex, nofollow` (hidden). Each matches its `X-Robots-Tag` |
| LAND-011 | Pass (part) | Claude, built-in | 23:35 | | am | Habru `/news`: one tag, `index, follow`, as its header. Vacancy page and the sitemap's page not checked |
| TEN-002 | Pass | Claude, `curl -I` | 23:36 | | | `https://ethr.et/qa-audit` 301 to `https://www.ethr.et/qa-audit` |
| MKT-002 | Pass | Claude, built-in | 23:37 | | en | `https://www.ethr.et/` to `/en` in a browser whose language is `en-US` and had no saved choice on this host |
| MKT-007 | Pass | Claude, built-in | 23:38 | | en | The home page's Trusted by lists the same nine organisations as `GET /api/v1/trusted-by`, in order, each linking to its sign-in page. **Owner action:** two of them, "Alice secasdas" (`alice-sec`) and "Bob sec" (`bob-sec`), read as test organisations named in public; hide them in the console (CON-P-008) if they are not customers |
| MKT-005 | Pass | Claude, built-in | 23:40 | | en, om | `GET /api/v1/plans` and the rendered `/en/pricing` and `/om/pricing` agree: Enterprise lists "Everything in Professional" and "API access, webhooks and the audit log", in Oromo "Wanti Professional keessa jiru hundi" and "Seensa API, webhooks fi galmee odiitii". K-71 is closed on production. Served HTML not compared |
| NEWS-001 | Pass (part) | Claude, built-in | 23:44 | | en | `syntaxtech.ethr.et/news`: its one public story, featured, one robots tag `index, follow`. Nine per page, search and a non-public story's 404 not exercised (one story). **Pack is wrong on the step:** `www.ethr.et/news` is an organisation's page with no organisation, and by design (`news-listing.tsx`, `host.context !== "tenant"`) shows "No news here" without asking the API. Observation (P3 copy): on ETHR's own site it says "This organisation does not publish news on this page." and offers "Back to the organisation's page" |
| ISO-008 | Pass | Claude, built-in | 23:46 | | en | QA Audit's public feed (`/auth/tenant-landing/feed`) holds its own branch and four holidays, no announcements or vacancies; its news page is empty. Nothing of another organisation's. Weak: QA Audit publishes nothing to leak beside |
| PERF-009 | **Fail** | Claude, built-in | 23:50 | | en | `/en` loads `/_next/static/chunks/1jm-5dwy7-al5.js`, the **whole of `am.json`** (604,002 bytes unpacked, about 110,000 Ethiopic characters, `accounting_page.account_code` "የሂሳብ ኮድ" among them). Cause: the home page prefetches `/register` in view, a route in the `(auth)` group, whose layout imports `amharic-dictionary.tsx`. A background prefetch, so not on first paint, but the bytes are spent on every visit to the home page. `/en/pricing` and `/en/no-such-page`: not found. Draft defect below |
| PERF-009 (404) | **Fail** | Claude, built-in | 23:51 | | am | `/am/no-such-page` is served in Amharic ("ገጹ አልተገኘም") and then switches to English after hydration, because this browser's saved language was `en`. `/am/pricing` with the same saved choice stays Amharic and saves `am`: only the 404 page lets the saved choice outrank the address. P3 |

### Re-run after the fix, 2026-11-14 (v2026.11.14.3, live `d591fb2ad33c`)

| Case ID | Result | Tester | Time (EAT) | Persona | Language | Notes / defect link |
|---|---|---|---|---|---|---|
| PERF-009 | Pass | Claude, built-in | 10:57 | | en | `/en`: 22 scripts, none holds "የሂሳብ ኮድ", and no `/register` prefetch (#722) |
| PERF-009 (404) | Pass | Claude, built-in | 10:58 | | am | `/am/no-such-page` with `en` saved stays Amharic after hydration, `lang="am"` (#723) |

### After the release steps, 2026-11-14 (Chrome, the owner's profile)

The release steps ran at 08:02Z: backup, migrate (`device_inbound_punches`, #720), seed, clear and optimize, all ok.
Health is healthy with `pending_migrations` 0.

| Check | Result | Notes |
|---|---|---|
| Habru terminal push (#720) | Pass | `habru-woreda.ethr.et/devices`, read only: the Hikvision terminal (S/N GB9654979) is Online and last heard "now". Unrecognised badges show punches dated Hidar 5, 2019 (2026-11-14), so pushes are accepted again. They wait under unrecognised badges until Habru's staff carry badge numbers |
| MKT-007 owner action | Done | In the console, Bob sec (owner "Bob Test", a throwaway email domain, webhook, API key and roles created within minutes of sign-up) and Alice secasdas ("Alice Test") were set to "Hidden by the platform". `GET /api/v1/trusted-by` and the live home page in Chrome now list seven organisations, not nine. Reversible from the same switch |
| QA Audit signed-in cases | Not run | Chrome is not signed in to QA Audit; K-05, K-48, K-50 and K-51 (supervisor half) still wait for a sign-in |

### Draft defect: the home page fetches the whole Amharic dictionary (PERF-009)

| | |
|---|---|
| Severity | P3, performance; a regression of #491 / #500 for the home page |
| Release | `v2026.11.13.10`, live `53fbe82b0b` |
| Steps | Signed out, a browser that has not visited the site: open `https://www.ethr.et/en` and wait for it to settle; list the scripts it loaded and search them for "የሂሳብ ኮድ" |
| Expected | Not found (09, PERF-009); a public page carries only the public projection (`public-dictionary.ts`) |
| Actual | Found in a 604 KB chunk loaded by the prefetch of `/register` |
| Likely fix | No prefetch on marketing links into the `(auth)` group (`/register`, `/login`), or keep `amharic-dictionary.tsx` out of the `(auth)` layout for the register page. Verify with PERF-009 after |

## Carried from the 2026-11-08 pass (still open)

Waiting on a signed-in QA Audit pass: K-05, K-46, K-47, K-48, K-49, K-50, and the supervisor half of K-51
(the employee half of K-51 and K-44 passed on 2026-11-08 in Syntax).

## Summary

Signed out (QA Audit): 7 passed, 0 failed. Signed in (Habru, TA only): 10 passed, 0 failed. Signed-out cases in the
built-in browser: 11 passed (two in part), 2 failed (PERF-009 on `/en`, and the 404 page's language), both P3. Not
run: S-05, S-09, S-11, S-13 and the other personas' S-08; and every signed-in case in QA Audit, because the built-in
browser was never signed in (an agent may not type a production password). Not a release sign-off: no P1 or P2
failure, but one persona, in an organisation that is not a QA one, and the seven carried-over cases are still open.
K-46 (SICK-004) and K-47 (LOAN-009) are Mode W and K-49 (SET-008) needs a fresh registration: none of the three can
be run read-only.
