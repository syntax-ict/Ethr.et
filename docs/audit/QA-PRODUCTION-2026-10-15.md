# Production QA — 2026-10-15, release v2026.10.15.5

Live QA of https://www.ethr.et on release `v2026.10.15.5` (`d24ec2c92280`), the first
release carrying Wave 2 (device commands, ADMS, statutory schedules), the organisation
portal series (#259–#269) and single-tenant mode (#270). Writes only inside the QA Audit
organisation; everything else read-only. Two findings, one of them real money.

## Method

Three layers, in order:

1. **Automated, before production:** `gates.sh` scopes already green in CI on the release
   commit; `gates.sh security` re-run locally (composer audit + npm audit on production
   deps: clean, dev-only advisories informational).
2. **Production read-only:** `release-status.sh https://www.ethr.et` (exit 0 — local,
   GitHub, `production` and the live site all at `d24ec2c92280`); `/api/v1/health`
   (healthy, queue healthy); redirect matrix (org subdomain → `/login` 302, admin host →
   `/admin/` 302, `ethr.et/{slug}` → www 301); `.env` / `artisan` / logs → 403;
   organisations sitemap → 200; portal head (canonical, `hreflang` en/am/x-default,
   JSON-LD, `og:image` serves 200 `image/png`); `robots: noindex` on qa-audit because its
   listing is off, as intended.
3. **Production writes, QA Audit org only, via the browser at human pace:** the surfaces
   that had never run on production with data — payroll pipeline, statutory exports menu,
   webhook event list, device dialog, portal in Amharic, and the whole app shell in `am`.

## What passed

- **Payroll pipeline end to end on the real queue:** a run for Meskerem 2019 (2026-09-11 –
  2026-10-10) was created, queued, picked up by the drain and completed within seconds.
- **Statutory exports (#250) are live:** the run's export menu offers the payroll register,
  bank file, journal CSV, and the income tax and pension schedules in CSV and PDF, all
  under an Amharic "statutory reports" section.
- **Attendance webhook events (#261):** `attendance.checked_in`, `.checked_out` and
  `.corrected` all offered in the webhook dialog.
- **ZKTeco ADMS (#248):** the device dialog offers "ZKTeco (ADMS: the device connects to
  ETHR)" alongside Hikvision, ZKTeco and Suprema.
- **API reference (#258):** Settings → API Keys → API reference renders, and the curl
  example is built on the organisation's own host with no copy-paste defects.
- **Amharic coverage:** the entire authenticated app (sidebar, payroll, employees,
  contract and run pages) rendered in `am` with no English leakage observed and no
  missing-key literals.
- **Portal (en+am):** branded sign-in with uploaded marks, custom words, tenant badge,
  complete head.

## Findings

### F1 — a contract's salary never reaches payroll, and the employee is skipped silently (S2)

QA Employee A was given an active permanent contract, 15,000.00 ETB from 2026-09-01,
through Employee → Terms → Add contract. A payroll run covering that period then completed
with **0 employees and 0.00 ETB**.

Cause, confirmed in code: `EmployeeContractService` stores `salary_cents` on the
**contract row only** (`EmployeeContractService.php:31,57`) and never syncs
`employees.salary_cents`; `PayrollEngine::processEmployee()` reads
`$employee->salary_cents` and **returns null when it is ≤ 0**
(`PayrollEngine.php:240-244`), so the employee vanishes from the run with no warning, no
skip count, nothing on the run page. Meanwhile the employee edit UI reached from the
profile (personal-info inline edit) offers no salary field at all, so the contract form is
the one visible place an admin can type a salary — and it is the place payroll ignores.

Two sources of truth for "what is this person paid" that disagree, and the disagreement is
silent. The same shape as the silent-null defects this repository documents. Decide one:
the contract syncs the employee's salary (with an effective date), or the engine reads the
active contract, or the run page reports "N employees skipped: no salary" so the silence
at least becomes visible.

### F2 — two payroll runs for the same period are accepted (S3)

The same period (2026-09-11 – 2026-10-10) was processed twice; both runs completed and
both now sit in the history. Only the `Idempotency-Key` deduplicates
(`ProcessPayrollRequest` validates dates only; the controller's dedupe is per key), so a
second click from a different session or day creates a second run. Both 0-value here, but
with employees both would carry full entries, and nothing was observed stopping both being
approved — double pay guarded only by human attention. An overlap check at `store()` (or
at approval) decides it; the register already knows the periods.

### F3 — Devices page froze the tab ~30 s once (S4)

First navigation to `/devices` froze rendering for roughly 30 seconds (screenshot capture
timed out twice), then recovered and rendered normally with no console errors. Not
reproduced on later visits in the same session. Worth one look at what that page loads
first (health dashboard counts?), not worth more than that until it recurs.

## Artifacts left in QA Audit, deliberately

- Two completed payroll runs for 2026-09-11 – 2026-10-10, both 0 employees / 0.00 ETB —
  the evidence for F1 and F2. **Neither is approved**, and nothing here should be approved.
- One active contract on QA Employee A: permanent, 15,000.00 ETB, from 2026-09-01.

## Not covered

Device hardware (owner action O1), SMS delivery (sender still an untested guess), real
webhook deliveries to an external receiver (the previous QA endpoint was deleted on
request), mail beyond what #249 already verified end to end, and anything in a real
organisation.

## Resolutions — 2026-10-16

- **F1 and F2 are fixed, merged in #273** (`f3f74ab2d230`, released as v2026.10.15.8):
  a contract in force now syncs the employee's salary (audited with from/to), the
  engine falls back to the contract covering the run's period, what is still skipped is
  counted in `payroll_runs.skipped_no_salary` and said on the run page, and a period
  overlapping a live run is refused — voided and failed runs do not block, replay is
  untouched. One existing test had **pinned F2's behaviour as intended** ("different
  idempotency keys allow separate payroll runs for the same period"); it is inverted
  with the reasoning recorded in place. The deploy carries one additive migration.
- **F3 took its one look and closes as environmental.** The page runs a single devices
  query on first load — no polling, no interval, no loop — and the live page serves in
  under 0.25 s on repeated measurement. The freeze happened minutes after a deploy, on
  the first navigation to that segment, and never again: a cold first hit, not a page
  defect. Reopen only if it recurs away from a deploy.
- The QA Audit artifacts above stay as described, and the two 0-value runs stay
  unapproved: with the overlap guard live they are also the quickest manual proof that
  a third run for that period is refused.
