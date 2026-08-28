# Phase 3 — Attendance Platform (Flagship) (v2.0)

> **Design record — not a progress tracker.**
> The `- [ ]` checkboxes below are the original up-front specification and were
> never maintained against the code. They under-report reality badly: several
> phases read as 0% complete while the features they describe are live and
> covered by tests. **Do not use them to judge what is done.**
>
> The live, code-grounded status is [`ENTERPRISE_ROADMAP.md`](ENTERPRISE_ROADMAP.md),
> with the standing audits in [`ETHR_AUDIT.md`](ETHR_AUDIT.md) and
> [`ETHR_AUDIT_2026-08-14.md`](ETHR_AUDIT_2026-08-14.md). Per the project rule,
> the source of truth is the code — verify against it, not against this file.
>
> Keep this document for its design intent: scope, data model, and acceptance
> criteria, which remain accurate and useful.

## Status: ✅ COMPLETE — all gaps closed, all exit criteria met

**Last Audited:** 2026-07-27  
**Auditor:** Principal SaaS Architect (automated + manual)

---

## Completion Matrix

| Story | Backend | Frontend | Tests | Status |
|-------|---------|----------|-------|--------|
| S13 — Attendance Engine Core | ✅ | ✅ | ✅ | DONE |
| S14 — Shift Engine | ✅ | ✅ | ✅ | DONE |
| S15 — Web, Manual, CSV, Kiosk | ✅ | ✅ | ✅ | DONE |
| S16 — Biometric + Hikvision | ✅ | ✅ | ✅ | DONE |
| S17 — ZKTeco + Device Dashboard | ✅ | ✅ | ✅ | DONE |
| S18 — Mobile & Offline Attendance | ✅ | ✅ | ✅ | DONE |
| S19 — Intelligence & Corrections | ✅ | ✅ | ✅ | DONE |
| Kiosk Token Auth | ✅ | ✅ | ✅ | DONE |
| Attendance Settings | ✅ | ✅ | ✅ | DONE |
| QR Auto-refresh | ✅ | ✅ | ✅ | DONE |
| Shift Management Frontend | ✅ | ✅ | ✅ | DONE |
| GAP CLOSURE (GAP-FIX-1..7) | ✅ | ✅ | ✅ | DONE |

---

## What Is Fully Implemented ✅

(See original PHASE_03 for full inventory — all items listed there remain accurate.)

---

## Gaps to Close Before Phase 4

### GAP-FIX-1: ShiftMatcher Test Bug [P0 — 1 hour]

**Root cause:** `ShiftFactory` doesn't set `is_active = true` by default. `ShiftMatcher::match()` filters by `is_active = true`, so test shifts aren't found.

**Fix:**
- [ ] `ShiftFactory`: set `'is_active' => true` and `'is_default' => false` as defaults
- [ ] `ShiftEngineTest`: verify test setup creates active shifts
- [ ] Add default shift seeder for test helpers: `DefaultShiftSeeder` that creates a standard 8:30-17:30 active shift
- [ ] Run `ShiftEngineTest` — both failing tests must pass

**Files:**
- `api/database/factories/ShiftFactory.php`
- `api/tests/Feature/ShiftEngineTest.php`
- `api/database/seeders/DefaultShiftSeeder.php` (new)

---

### GAP-FIX-2: Conflict Resolution State Machine [P1 — 1-2 days]

**Current state:** `ConflictResolver` exists but uses informal logic. Needs formal state machine.

**Fix:**
- [ ] Implement formal conflict decision table in `ConflictResolver`:

| Scenario | Time Gap | Resolution | Action |
|---|---|---|---|
| Same source, same minute | < 1 min | Deduplicate | Keep first, discard duplicate |
| Different source, same minute | < 1 min | Merge | Keep highest confidence |
| Different source, close time | 1-5 min | Merge | Earliest check-in, latest check-out |
| Different source, far apart | > 5 min | Flag | Create conflict record for HR review |
| Offline arrives after payroll close | Any | Retroactive | Create adjustment record, notify HR |
| Confidence scores tied | Any | Source priority | Biometric > Mobile+GPS > QR > Web > Manual > CSV |
| Check-in without check-out | End of day | Auto-flag | Missing punch alert |
| Check-out without check-in | Any | Flag | Create conflict record for HR review |
| Duplicate idempotency key | Any | Skip | Return `was_duplicate: true` |

- [ ] Add `attendance_conflicts` table: `id, tenant_id, employee_id, record_a_id, record_b_id, conflict_type, resolution, resolved_by, resolved_at`
- [ ] Test every row in the decision table explicitly
- [ ] Add conflict list endpoint: `GET /api/v1/attendance/conflicts` (HR view)
- [ ] Add conflict resolution endpoint: `PUT /api/v1/attendance/conflicts/{id}/resolve`

**Tests:**
- [ ] ConflictResolver — one test per decision table row (Pest)
- [ ] Conflict creation and resolution flow (Pest)

---

### GAP-FIX-3: Missing Punch Alert Job [P1 — 2 hours]

**Fix:**
- [ ] Create `ScanMissingPunchesJob`:
  - Runs daily at configurable time (default: 18:00 EAT / 15:00 UTC)
  - Scans each tenant's attendance for the current day
  - Finds check-ins without check-outs (and vice versa)
  - Creates `MissingPunchDetected` event per employee
  - Dispatches `MissingPunchNotification` to employee + supervisor
- [ ] Register in `routes/console.php`: `Schedule::job(ScanMissingPunchesJob::class)->dailyAt('15:00');`
- [ ] `MissingPunchNotification` class (in-app + email channels)
- [ ] Add notification type to notification preferences

**Tests:**
- [ ] ScanMissingPunchesJob finds missing punches (Pest)
- [ ] Notification dispatched to employee + supervisor (Pest)
- [ ] Job handles multiple tenants (Pest)
- [ ] Job is idempotent — running twice doesn't duplicate alerts (Pest)

---

### GAP-FIX-4: Complete Amharic Translation Files [P2 — 3 hours]

**Currently present:** `am/attendance.php`, `am/auth.php`, `am/general.php`, `am/kiosk.php`

**Fix — add all missing files:**
- [ ] `am/employee.php` — matching `en/employee.php`
- [ ] `am/leave.php` — matching `en/leave.php`
- [ ] `am/payroll.php` — matching `en/payroll.php`
- [ ] `am/correction.php` — matching `en/correction.php`
- [ ] `am/shift.php` — matching `en/shift.php`
- [ ] `am/device.php` — matching `en/device.php`
- [ ] `am/notification.php` — matching `en/notification.php`
- [ ] `am/organization.php` — matching `en/organization.php`
- [ ] `am/settings.php` — matching `en/settings.php`
- [ ] `am/dashboard.php` — matching `en/dashboard.php`
- [ ] `am/common.php` — verify complete (if exists, compare with en/common.php)

**Verification:**
- [ ] Script: compare all `en/*.php` keys against `am/*.php` keys — flag missing translations
- [ ] Every key in `en/` must exist in `am/`

---

### GAP-FIX-5: PWA Service Worker [P2 — 3-4 hours]

**Fix:**
- [ ] Create `src/public/sw.js`:
  - Cache static assets (CSS, JS, fonts, icons) on install
  - Network-first strategy for API calls
  - Cache-first strategy for static assets
  - Offline fallback page for non-cached routes
  - Background sync for offline attendance queue
- [ ] Create `src/public/manifest.json`:
  - App name, short name, icons (all sizes: 72, 96, 128, 144, 152, 192, 384, 512)
  - Start URL, display: standalone, theme color, background color
  - Categories: business, productivity
- [ ] Create `src/app/offline/page.tsx`:
  - Friendly offline page with ETHR branding
  - "You're offline" message (bilingual)
  - "Your attendance data is saved and will sync when connected" reassurance
  - Retry button
- [ ] Register service worker in Next.js app entry
- [ ] Install prompt handler (`beforeinstallprompt` event)
- [ ] Splash screen configuration

**Tests:**
- [ ] Service worker registers (manual browser test)
- [ ] Offline page renders when disconnected (manual)
- [ ] Static assets served from cache when offline (manual)
- [ ] Background sync triggers when connectivity restored (manual)

---

### GAP-FIX-6: Payroll Impact Preview on Corrections [P3 — 2 hours]

**Fix:**
- [ ] Backend endpoint: `GET /api/v1/attendance/corrections/{id}/payroll-impact`
  - Calculate: what the payroll entry looks like with current record vs corrected record
  - Return delta: `{ current: { ot_minutes, ot_amount_cents, ... }, corrected: { ... }, delta: { ... } }`
  - Only available for corrections affecting records within an approved payroll period
- [ ] Frontend: in correction approval view, show `ComparisonCard` with payroll impact
  - Show before/after for: overtime hours, overtime amount, attendance status
  - Highlight changes in accent color

---

### GAP-FIX-7: QR Scan Camera Fallback [P3 — 1 hour]

**Fix:**
- [ ] On `/attendance/scan` page:
  - If camera permission denied: show clear message explaining why camera is needed + link to browser settings
  - Manual token input fallback: text input field where employee can type the QR code string (for accessibility)
  - Camera permission request on page load with explanatory dialog

---

## Ethiopian Organization Requirements — Updated Checklist

| Requirement | Status | Notes |
|-------------|--------|-------|
| Multi-source attendance (9 sources) | ✅ | Biometric, Mobile, QR, Kiosk, Web, Manual, CSV, API, Offline |
| Ethiopian work hours (8:30–17:30 default) | ✅ | Default shift seeder |
| Ethiopian calendar display | ✅ | `lib/calendar/ethiopian.ts` |
| Amharic UI | ✅ after GAP-FIX-4 | Full coverage — 15+ translation files |
| Biometric device compatibility | ✅ | Hikvision, ZKTeco, adapter pattern for more |
| Offline attendance (poor connectivity) | ✅ | IndexedDB queue + sync |
| PWA installable | ✅ after GAP-FIX-5 | Service worker + manifest |
| Multi-branch geofencing | ✅ | Per-branch lat/lng/radius |
| Attendance correction workflow | ✅ | 4-step approval chain |
| Payroll impact preview on corrections | ✅ after GAP-FIX-6 | Shows delta before final approval |
| Kiosk for shared devices | ✅ | Token auth, PIN, numpad |
| Manual HR entry | ✅ | Audit logged, lowest confidence |
| CSV bulk import (migration from Excel) | ✅ | With validation preview |
| Confidence scoring (anti-fraud) | ✅ | 50-100 based on source + GPS |
| Conflict resolution (formal) | ✅ after GAP-FIX-2 | State machine with decision table |
| Idempotency (prevent duplicates) | ✅ | All write endpoints |
| Tenant isolation | ✅ | BelongsToTenant + TenantIsolationTest |
| Per-tenant method control | ✅ | AttendanceSetting |
| Missing punch auto-alerts | ✅ after GAP-FIX-3 | Daily job + notifications |
| Shift management UI | ✅ | Shifts, assignments, roster views |

---

## Gap Closure Status (all closed 2026-07-27)

| Gap | Status | Delivered |
|-----|--------|-----------|
| GAP-FIX-1: ShiftMatcher test bug | ✅ | `ShiftFactory` defaults `is_active = true`, `is_default = false`; ShiftEngineTest green |
| GAP-FIX-2: Conflict resolution state machine | ✅ | `attendance_conflicts` table + `AttendanceConflict` model, `ConflictType`/`ConflictResolutionStatus` enums, source-priority tiebreak in `ConflictResolver`, `GET /attendance/conflicts` + `PUT /attendance/conflicts/{id}/resolve`, 10 decision-table tests |
| GAP-FIX-3: Missing punch alert job | ✅ | `ScanMissingPunchesJob` (per-tenant), `MissingPunchNotification`, scheduled in `routes/console.php` |
| GAP-FIX-4: Complete Amharic translations | ✅ | 16 `am/*.php` files matching `en/` |
| GAP-FIX-5: PWA service worker | ✅ | `public/sw.js` (precache, network-first API, background sync, push), manifest, `/offline` page |
| GAP-FIX-6: Payroll impact preview | ✅ | `GET /attendance/corrections/{id}/payroll-impact` returning before/after + delta |
| GAP-FIX-7: QR scan camera fallback | ✅ | `camera_denied` state with explanation, manual token entry (also reachable from idle for accessibility), retry-camera action, 4 Vitest tests |

### Post-completion fixes

**2026-07-28 — Device events endpoint 500'd on phantom columns (GAP-FIX-8) ✅**

`DeviceController::deviceEvents` (`GET /devices/{device}/events`, S17 device dashboard) eager-loaded
`employee:id,public_id,first_name,last_name,employee_code` and built `employee_name` from
`first_name`/`last_name` — but `employees` has a single `name` column and no `first_name`/`last_name`,
so the endpoint threw *Unknown column 'first_name'* and returned **500 for every request**. Hidden from
PHPStan by the untyped `AttendanceRecord::employee()` relation (access resolved to `Model`) and covered
by **no test**. Fixed to load/return `name`; typed `AttendanceRecord::employee()` as
`BelongsTo<Employee, $this>` (root cause), which retired 12 now-obsolete PHPStan baseline entries across
6 files. Added 2 regression tests to `DeviceManagementTest` (returns 200 with employee name/code;
employee forbidden). Found via a repo-wide sweep for the same bug class as PHASE 4 GAP-4A-3 — no other
constrained eager-load, explicit `select()`, or query-builder column reference (`orderBy`/`whereDate`/
`groupBy`/`sum`/`pluck`) targets a missing column.

Added durable regression nets that iterate the router and assert non-5xx:
- `tests/Feature/ReadEndpointSmokeTest.php` — all 82 parameterless `GET /api/v1/*` routes, against both
  an empty tenant and a data-rich fixture (verified once against the full 150-employee demo tenant too).
- `tests/Feature/ParameterizedEndpointSmokeTest.php` — all 44 parameterized `GET /api/v1/*` routes,
  binding a real fixture record to every `{param}` (with a coverage guard that fails if a newly added
  route has an unmapped param). Two routes are intentionally skipped: the DomPDF payslip PDF
  (memory-heavy) and the invoice receipt (needs a Subscription chain).

All read endpoints pass.

**2026-07-28 — Device status probe blocked a worker for ~20s on unreachable devices (GAP-FIX-9) ✅**

Surfaced by the parameterized smoke net: `GET /devices/{device}/status` calls the adapter's
`getStatus()` + `getDeviceInfo()`, each a live HTTP request. `Http::timeout(10)` only caps the request
*after* connecting, so an unreachable device stalled on the TCP connect phase — ~20s total (two calls),
tying up a PHP-FPM worker per offline device (worker-exhaustion risk). Added `->connectTimeout(2)` to the
shared `request()` helper in `HikvisionAdapter`, `ZktecoAdapter`, and `SupremaAdapter` (the read
`timeout(10)` is left intact — it is shared with large event pulls). Probe now fails in ~4s and returns a
graceful `offline` (200), verified by a regression test hitting a TEST-NET-1 (RFC 5737) address.

Gates: Pest 912 green, PHPStan level 6 clean, Pint clean.

**2026-07-30 — Mobile check-in with a selfie always failed validation (GAP-FIX-10) ✅**

The mobile attendance page captured the selfie with `canvas.toDataURL("image/jpeg", 0.7)` and sent the
resulting **base64 data URL** as `photo_path`, which `MobileCheckInRequest` validated as
`['nullable','string','max:500']`. Any real selfie is tens of thousands of characters, so *every*
check-in that included a photo returned **422** — silently costing the S18 confidence bonus (95 with a
selfie vs 88 without) and leaving no photographic evidence on the record. No test covered it: the one
selfie test posted a short fake path (`'selfies/photo-001.jpg'`), which passed `max:500` and never
exercised the real client payload.

Closed with a proper upload pipeline:
- `App\Rules\Base64Image` — parses the data URL, restricts to JPEG/PNG/WebP, caps the *decoded* size,
  and verifies magic bytes, giving inline images the same guarantee `VerifyFileContent` gives multipart
  uploads (convention #15). Messages added to `lang/en|am/validation.php`; the framework validation file
  was published, since `validation.*` keys previously rendered as raw key strings.
- `FileStorageService::uploadDataUrlImage()` — decodes, downscales to ≤ 1080px, and steps JPEG quality
  down until the object fits the 200 KB selfie budget, then stores it under `{tenant}/selfies/`.
  Requests now carry `photo` (the data URL); the object key is derived server-side and clients never
  supply `photo_path`.
- Check-out selfies are stored as `metadata.checkout_photo_path` rather than overwriting the check-in
  photo — `AttendanceEngine::processCheckOut()` previously discarded any photo it was handed, which
  would have orphaned the uploaded object.
- 5 tests in `MobileSelfieUploadTest` (storage, both punches retained, non-image rejected, SVG rejected,
  byte/dimension budget); the pre-existing confidence-95 test now posts a real data URL.

Also fixed a GD handle leak found by the same work: `compressImage()` replaced its image handle without
freeing the previous one, so a full-resolution photo exhausted the 128 MB PHP memory limit mid-request.

Gates: Pest 959 green, PHPStan level 6 clean, Pint clean, tsc + Vitest 211 green.

### Conflict decision table → implementation mapping

| Scenario | Resolution | Implemented as |
|---|---|---|
| Same source, < 1 min | Deduplicate | `deduped` — higher confidence survives, ties keep first |
| Different source, < 1 min | Merge (highest confidence) | `merged` via `mergeByConfidence()`; ties fall back to source priority |
| Different source, 1–5 min | Merge span | `merged` — earliest check-in, latest check-out |
| Different source, > 5 min | Flag for HR | `flagged` + `AttendanceConflict` record (`multi_source_far`) |
| Confidence tied | Source priority | Biometric > Mobile > QR > Web > Manual > CSV |
| Duplicate idempotency key | Skip | Unique `(tenant_id, idempotency_key)`, returns `was_duplicate: true` |

---

## Phase 3 Exit Criteria (Updated)

- [x] Unified attendance engine handles all 9 sources
- [x] Shift engine matches employees to shifts correctly
- [x] ShiftMatcher tests passing (GAP-FIX-1)
- [x] Conflict resolution formal state machine (GAP-FIX-2)
- [x] Hikvision + ZKTeco devices integrated
- [x] Offline attendance stores locally and syncs with conflict resolution
- [x] Mobile attendance with GPS + geofence + selfie
- [x] QR attendance with time-limited codes + auto-refresh
- [x] Intelligence detects late/early/OT
- [x] Correction workflow with 4-step approval chain
- [x] Kiosk mode with token auth + employee PIN
- [x] Device dashboard with real-time status
- [x] Attendance settings (per-tenant method control)
- [x] Shift management frontend (list, assignments, roster)
- [x] Missing punch alert job (GAP-FIX-3)
- [x] Full Amharic translation coverage (GAP-FIX-4)
- [x] PWA service worker + install prompt (GAP-FIX-5)
- [x] Payroll impact preview on corrections (GAP-FIX-6)
- [x] QR scan camera fallback (GAP-FIX-7)
- [x] Browser verified: desktop + tablet (768) + mobile (375) + dark + light + Amharic; console clean
- [x] All Pest tests passing (833 passed)
- [x] TenantIsolationTest passes
