# QA — pre-release pass on the demo organisation, 2026-10-13

A full-stack pass before the release that carries #222–#232 is pulled and deployed. Run
against the **local production rehearsal** (`scripts/local-production/up.sh`, Apache +
MariaDB on `:8081`, the exported frontend), signed in to the seeded **demo** organisation
(`demo.localhost:8081`) as its tenant admin and as an ordinary employee. Nothing in
production was touched.

Method: every main screen viewed at 1440×900 and at 375×812, then ~90 test cases sent as
same-origin requests from the signed-in page: valid, invalid, boundary and hostile inputs
(empty, too long, wrong type, another organisation's ID, duplicates, impossible dates,
script in text fields), and the same surfaces again as an employee to check every
permission refusal. Each failing case was traced to its rule before anything was changed.

What this report does not contain: emails, passwords, tokens, IP addresses.

Severity: **S1** wrong results for every tenant / data integrity · **S2** a broken feature
or a missing control · **S3** wrong behaviour with a workaround · **S4** polish.

## Ratings

Scores out of 10, before and after this branch. They are judgments from the pass, not
measurements.

| Area | Before | After | Why |
|---|---|---|---|
| Security and permissions | 9 | 9 | All 17 employee-role probes refused (403/404). Foreign IDs, script in text, oversize input and huge `per_page` handled correctly |
| Core functionality | 7.5 | 8.5 | Seven inputs were accepted that no one could mean (F1–F7); all now refused, with tests |
| Demo data | 4 | 8 | Punches were stored in UTC and ran into the future; payroll stopped two months back (D1, D2) |
| Desktop UI | 7 | 8 | Template-like gradient and icon tiles removed; the setup card contradicted the sidebar; a chart drew dots (U1–U5) |
| Mobile UI | 6 | 7.5 | Quick actions overflowed into one column of four; the header crowded the organisation name (U6, U7) |
| UX consistency | 6.5 | 7.5 | Navigation called the organisation-wide page "My Attendance"; the payroll card ran two captions together (U3, U8) |

## Summary

| # | Sev | Finding | Status |
|---|---|---|---|
| F1 | S2 | A shift whose end equals its start (08:00–08:00) was **created** — zero length, so every punch on it is late and early at once | Fixed |
| F2 | S3 | Hire date **2099-01-01** accepted | Fixed — at most a year ahead (a signed offer) |
| F3 | S2 | An employee **aged five on the hire date** accepted | Fixed — at least 15 on the hire date, Labour Proclamation 1156/2019 art. 89 |
| F4 | S3 | Phone **"call me maybe"** accepted (employee and emergency contact) | Fixed — digits, spaces, `+ ( ) -`, 6–20 characters |
| F5 | S2 | Organisation time zone **"Mars/Olympus"** saved. `TenantTime::zone()` falls back to Addis Ababa, so punches survived, but the setting showed a zone that does not exist | Fixed — `timezone:all` |
| F6 | S3 | The **same holiday on the same date** could be added twice | Fixed — refused with a message |
| F7 | S3 | An **open** vacancy could close in the past, so it was published and never shown | Fixed — closing date must be today or later while open; drafts and closed records may keep old dates |
| D1 | S2 | Demo attendance stored 08:xx as **UTC** (11:xx Addis time) and **ran to the end of the month**, into days not yet lived | Fixed — Addis wall clock, through yesterday |
| D2 | S3 | Demo payroll stopped two months back, so the payroll card had nothing to compare | Fixed — three months, the latest one completed |
| U1 | S3 | Dashboard "Complete Setup" said **80%** beside a sidebar saying **0 of 4** — it guessed from record counts | Fixed — reads the same onboarding status the sidebar does |
| U2 | S4 | Welcome block drawn as a gradient card with a coloured rule; every KPI had a coloured icon tile in its corner — the look of a template | Fixed — plain page header; icon sits small beside the label |
| U3 | S4 | Payroll KPI read **"↓10.3% vs last month This month"** | Fixed — the caption shows only when there is no comparison |
| U4 | S4 | "Hires over time" drawn as a line between monthly counts, so a gap left **isolated dots** | Fixed — bars; whole numbers on the axis |
| U5 | S4 | The same chart's axis **silently dropped month labels** ("September 2026" ×6 does not fit), so a month with no hires looked missing | Fixed — short tick labels ("Sep 2026"), long form kept in the tooltip |
| U6 | S3 | At 375 px the four quick actions stacked into a tall single column | Fixed — 2×2 grid on phones, a row from `sm` |
| U7 | S4 | At 375 px the header's theme switcher squeezed the organisation name to "E…" | Fixed — theme switcher hidden below `sm`; on a phone the theme follows the phone's own light/dark setting |
| U8 | S4 | The organisation-wide attendance page was labelled **"My Attendance"** for admins | Fixed — "Attendance" when the user can see everyone |
| O1 | S4 | Requesting `user_role: super_admin` when creating an employee returns **201** and quietly provisions an *employee* login | Open — safe (no escalation), but a 422 would tell the admin the truth |
| O2 | S4 | An admin with no employee record sees "No leave configured — contact HR" on the dashboard although leave types exist | Open — the card should say the admin has no employee record |
| O3 | — | 100-row list endpoints took ~1.4 s on the rehearsal | Not a finding — php-cgi on Windows; production not measured here |

### Withdrawn

Three early results were the test script's fault, not the product's, and are recorded so
nobody chases them:

- *"The demo organisation has no leave types"* — the script asked `/leave/types`; the route
  is `/leave-types`, which returns Annual and Sick leave.
- *"Leave type create fails"* — the script omitted the required `accrual_type`.
- *"Inviting a user answers 405"* — wrong path in the script.

## Test cases that passed

Employees: duplicate code, invalid email, probation before hire, DOB in the future, another
organisation's department ID, 300-character name, negative salary, salary as text, unknown
gender, unknown ID → 404, SQL-looking search, script in a name (stored as text, rendered
escaped). Organisation: department without a name, invalid shift time, overnight shift
22:00–06:00 accepted, invalid holiday date. Attendance: manual entry with check-out before
check-in, in the future, without a reason, without an idempotency key — all refused.
Settings: grace 999 minutes, an invalid brand colour, an `http://` landing image — refused.
Voice: pulse score 6, unknown tag, empty concern, unknown category — refused; guessing a
concern tracking code → 404. Devices: invalid IP allowlist, the mock adapter in production,
port 70000 — refused. Payroll: invalid period, end before start — refused. Announcements:
empty title refused. Pagination: `per_page=100000` capped at 100.

As an **employee**: list or create employees, read a colleague's attendance, the
organisation-wide attendance list, payroll runs, the integrity queue, voice insights, all
concerns, settings, branding, audit logs, posting a vacancy, manual attendance for
themselves → all **403**. Leave starting in the past → 422. Approving a leave request by a
guessed ID → 404. Offline sync naming a colleague → the record is rejected.

## Verification

- `api/tests/Feature/QaReleaseValidationTest.php`: 8 tests covering F1–F7, each also
  asserting the legitimate neighbour (night shift, a 15-year-old school leaver, an
  Ethiopian phone, a closed old vacancy) is still accepted.
- Backend suite 2896 passed. PHPStan clean, Pint clean.
- Frontend gate: i18n, Prettier, ESLint, tsc, Vitest 1101 passed.
- API contract regenerated: unchanged. Two QA notes first placed above `rules()` keys were
  published as OpenAPI descriptions, the trap root `CLAUDE.md` records, and were moved into
  the docblocks.
- Local production rebuilt from this branch, `verify.sh` 51 passed, 0 failed. Dashboard,
  analytics and mobile re-checked in the browser; no horizontal overflow at 375 px.
- The seeder ran on a scratch MariaDB database: attendance ends yesterday, check-ins
  08:15–08:59 Addis time, about 10% late, payroll July–September.

## Not covered

- Production itself. This pass ran on the rehearsal; the release still needs the usual
  post-deploy checks.
- Payroll processing a second time for the same month (the run hit the 2/hour throttle,
  which is itself the documented limit).
- Real biometric hardware and the kiosk's offline seal on a physical tablet.
- Dark mode was not re-checked after the visual changes; they use semantic tokens only.
