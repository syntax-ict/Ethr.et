# Production QA execution log, 2026-10-28

Pack: [`docs/production testing/`](<../production testing/README.md>). Format: [10, section 2](<../production testing/10-TEMPLATES-AND-REFERENCE.md#2-execution-log>).

| | |
|---|---|
| Release under test | `v2026.10.28.10`, `release.json` commit `7cf43b1b3` (#459), built 2026-10-28 15:23:55Z |
| Organisation | Syntax Technology (`syntaxtech`, reached at `https://syntaxtech.ethr.et`), the owner's own organisation |
| Persona | TA (`syntaxict01`, organisation admin), already signed in in Chrome |
| Browser | Chrome, Windows 11, 1280 px |
| Tester | Claude Code, on the owner's instruction |

Run read-only first. Nothing was written to production in this pass unless a row says so.

## Release smoke (10, section 3)

| Case ID | Result | Time (EAT) | Persona | Language | Notes / defect link |
|---|---|---|---|---|---|
| S-01 | Pass | 20:21 | none | | `release.json` commit `7cf43b1b3` = tag `v2026.10.28.10`. `main` is one PR ahead (#460, not deployed) |
| S-02 | Pass | 20:21 | none | | All core services healthy; scheduler heartbeat 63 s old; four queues at depth 0; `failed_jobs` 0. `mail_detail.last_sent_at` is null |
| S-03 | Pass | 20:22 | none | | `GET /api/v1/attendance/devices/unregistered` (added in #459) answers 401 signed out, as `/employees` does. Route cache is current |
| S-04 | Pass | 20:22 | none | | `http://ethr.et` 301 to `https://www.ethr.et/`; `https://ethr.et/qa-audit` 301 to `www`; `https://syntaxtech.ethr.et/` 302 to `/login`; `https://admin.ethr.et` 302 to `/admin/` |
| S-05 | Pass | 20:22, 20:37 | none | | 403 for `/.env`, `/api/.env`, `/artisan`, both `laravel.log` paths, `/api/composer.json`; 404 for `/api/artisan`. `/.git/config` timed out at 20:22 (outage began); re-sent alone at 20:37: 403, health 200 at +5 s and +25 s, so that request did not cause the outage |
| S-06 | Pass | 20:31 | none | | `/en` 200 (12.4 s, cold after the outage), `/am` 200, `/en/pricing` 200, `/news` 200, `/robots.txt` 200. Prices are rendered client-side, not checked in the HTML |
| S-07 | Pass | 20:31 | none | | `www.ethr.et/qa-audit` and `/syntaxtech` 302 to their subdomain `/login` (subdomains on) |
| S-08 | Partial | 20:22 | TA | en | TA reaches the dashboard: headcount 3, present 33.3 %, 0 pending approvals, payroll not run. HR, FIN, SUP, EMP accounts not available in this organisation |
| S-09 | Not run | | PA | | Console not in scope of this session |
| S-10 | Pass | 20:32 | TA | en | Employees (3), Attendance (setup notes: "Working week and default shift disagree", "Kiosk check-in is on with no kiosk"), Leave (empty state), Payroll (no runs), Reports (builder), Announcements (empty state), Settings, Billing (Starter, trial, next billing Miyazia 12, 2019), Devices (empty state), Approvals (empty state), Organization (9 departments). No error toast, console clean |
| S-11 | Blocked | | EMP | | TA has no employee record ("Your account has no employee record"); no EMP account in this session |
| S-12 | Pass with findings | 20:34 | TA | am | Dashboard translated, no raw keys. One English sentence in Recent Activity (F-02); "33.3 %" wraps onto two lines in the Present Today card and the Payroll card subtitle is cut off (F-04). Switched back to English afterwards |
| S-13 | Blocked | | | | The Chrome window did not take the 390 px resize; still 1280 px |
| S-14 | Pass | 20:31 | TA | en | Pages loaded after the outage served the `7cf43b1b3` build; no stale-bundle errors |

## Release under test: #459 registered phones

| Case | Result | Time (EAT) | Persona | Language | Notes |
|---|---|---|---|---|---|
| Phones page (`/attendance/devices`) reads | Pass | 20:38 | TA | en | Summary "People with a phone 2 / 3, Waiting 0, Still to register 1" agrees with the tabs: Waiting empty, Not registered lists 1 person, Registered lists 2 (Android, Chrome, synced passkey, last punched 3 h ago, with Punch without a phone and Revoke). Console clean. No write made |

## Outage, 2026-10-28 about 17:22Z (20:22 EAT)

`/api/v1/health` answered healthy at 17:21:07Z. About a minute later every request to `213.55.96.154` began failing: from this machine a 15 s timeout on `www`, the organisation subdomain and `admin`, while `google.com` answered in 2 s; from an outside vantage point `ECONNREFUSED 213.55.96.154:443`. The refusal from outside rules out a block on this machine's address alone. The last requests before it were the S-05 secret-path probes, each answered 403 normally, and nothing in this pass sent load.

## Findings

### F-01 [PWA] The offline page crashes when the server is unreachable, and shows Amharic to an English user (S3)

Case: PWA offline (09). Seen for real during the outage above.

**Steps.** Signed in, interface in English (`localStorage.locale = en`, `<html lang="en">`). Server becomes unreachable. Navigate to `/leave`.

**Expected.** The service worker falls back to `/offline`, which says "You are offline" in the person's language and offers Try again.

**Actual.** The page first renders in Amharic ("ከመስመር ውጪ ነዎት"), then replaces itself with the error boundary "Something went wrong ... Try again / Go to dashboard". Console:
`ChunkLoadError: Failed to load chunk /_next/static/chunks/2xqyjy6er19dl.js from module 964893`.

**Cause (from the code).** `src/public/sw.js` precached the `/offline` HTML (`SHELL_ASSETS`) but none of the `/_next/static/` files it loads; those were cached only when something fetched them online, and nothing visits `/offline` online. The failing chunk is one of the 20 scripts the live `/offline` HTML names (a UI component chunk, checked against production). The static export renders `/offline` with `DEFAULT_LOCALE = "am"` (`src/src/lib/i18n/config.ts:13`), which is why the HTML shows Amharic before hydration. And because `sw.js` does not change when a release is deployed, the worker is never reinstalled, so even a correct copy would go stale with the next release's chunk names.

**Fixed on `fix/qa-2026-10-28`.** The worker stores each shell page only together with every script and stylesheet it names, keeps the previous copy when one of them cannot be fetched, re-reads the shell on a successful navigation once its copy is six hours old, and is bumped to cache `ethr-v3` so existing installs drop the HTML-only copy. A failed dictionary import no longer leaves an unhandled rejection (`translations.ts`). Test: `src/src/test/service-worker-shell-cache.test.ts`, three cases, each failing on the old worker.

**Frequency.** Once, on the first offline navigation in this browser.

### F-02 [Notifications] Notification text is frozen in the language it was stored in (S3)

Case: S-12, and 09 Amharic.

**Steps.** As TA, switch to አማርኛ, open the dashboard, read Recent Activity.

**Expected.** Every line in Amharic.

**Actual.** "Abnet degefaw has requested a change to protected profile fields." in English. The Amharic line exists (`api/lang/am/notification.php:15`).

**Cause.** `ProfileUpdateRequestedNotification::toArray()` stores `'message' => __('notification.profile_update_requested_body', …)`, so the sentence is translated once and saved into the `notifications` row. Since 2026-10-20 that is in the recipient's saved `users.locale` (`HasLocalePreference`), not the sender's. But the header's language switch changes only the browser's locale (localStorage and a cookie, `setLocale()` in `translations.ts`) and never `users.locale`, which defaults to `en`. So a reader who switches the header to Amharic keeps reading notices stored in English. 25 notifications build `message` this way. The employee name is frozen the same way (Recent Activity says "Abnet degefaw", the employee list "Abenet gedefaw"). That is the name as it was when the notice was sent, and is left as is.

**Fixed on `fix/qa-2026-10-28`.** `App\Support\NotificationMessage::make()` stores `message_key` and `message_params` beside `message` in 24 of the 25 notifications. `NotificationResource` translates them again in the request's `Accept-Language`, which the client sends from the UI locale. Rows stored before the fix, unknown keys and malformed keys keep their stored `message`. `ConcernRaisedNotification` is left out, because its parameter is itself a translated category label. Web push still sends the stored `message`.

**Not yet covered.** Ten more notifications translate their `message` some other way and still keep their stored text: through a method (`ApprovalEscalated`, `AttendanceDeviceDecided`, `AttendancePhotosDueForDeletion`, `ConcernUpdated`, `LetterDecided`, `RecognitionReceived`, `TenantExportFinished`), through `trans_choice` (`ExpiryReminder`, `TrialExpiring`), or as a choice between two keys (`OvertimeDecided`). `Announcement` and `SystemAlert` carry text written by a person or the system, not a translation key, and are right as they are. Tests: four new cases in `api/tests/Feature/NotificationLanguageTest.php`.

### F-03 [Navigation] Breadcrumb sections disagree with the sidebar (S4)

Breadcrumbs use their own `route.section.*` map rather than the sidebar registry (`src/src/lib/navigation/registry.ts`). Seen: Reports "Finance > Reports" (sidebar: Insights); Announcements "My Work" (sidebar: Communication); Attendance "My Work > Attendance" for an admin, whose Attendance sits under Operations; Leave and Approvals "My Work" (sidebar: Workspace). The two names for one section ("My Work" vs "Workspace") are the most visible.

### F-04 [Amharic] Dashboard cards: value wraps, subtitle cut off (S4)

In Amharic at 1280 px, the Present Today value renders as "33.3" with "%" on the next line, and the Payroll (gross) subtitle ends in "…". English fits on one line. Lower priority than F-02.

## Not covered in this session

Writes of any kind, personas other than TA, the console (PA), the E2E documents 03 to 09 beyond the reads above, tenant isolation (needs QA Audit and a `uat-` organisation), and the phone width check. Nothing was created, changed or deleted on production. The only state touched was this browser's language, set to Amharic and back to English.
