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

## Carried from the 2026-11-08 pass (still open)

Waiting on a signed-in QA Audit pass: K-05, K-46, K-47, K-48, K-49, K-50, and the supervisor half of K-51
(the employee half of K-51 and K-44 passed on 2026-11-08 in Syntax).

## Summary

Signed out (QA Audit): 7 passed, 0 failed. Signed in (Habru, TA only): 10 passed, 0 failed. Not run: S-05, S-09,
S-11, S-13 and the other personas' S-08. No failures, but not a release sign-off: one persona, in an organisation that
is not a QA one, and the seven carried-over cases are still open.
