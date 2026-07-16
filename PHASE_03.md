# Phase 3 — Attendance Platform (Flagship) (v2.0)

## Status: 92% Complete — Action Plan for Remaining 8%

**Last Audited:** 2026-07-15  
**Auditor:** Principal SaaS Architect (automated + manual)

---

## Completion Matrix

| Story | Backend | Frontend | Tests | Status |
|-------|---------|----------|-------|--------|
| S13 — Attendance Engine Core | ✅ | ✅ | ✅ | DONE |
| S14 — Shift Engine | ✅ | ✅ | ⚠️ 2 tests fail | NEEDS FIX |
| S15 — Web, Manual, CSV, Kiosk | ✅ | ✅ | ✅ | DONE |
| S16 — Biometric + Hikvision | ✅ | ✅ | ✅ | DONE |
| S17 — ZKTeco + Device Dashboard | ✅ | ✅ | ✅ | DONE |
| S18 — Mobile & Offline Attendance | ✅ | ✅ | ✅ | DONE |
| S19 — Intelligence & Corrections | ✅ | ✅ | ✅ | DONE |
| Kiosk Token Auth | ✅ | ✅ | ✅ | DONE |
| Attendance Settings | ✅ | ✅ | ✅ | DONE |
| QR Auto-refresh | ✅ | ✅ | ✅ | DONE |
| Shift Management Frontend | ✅ | ✅ | ✅ | DONE |
| GAP CLOSURE (new) | — | — | — | REMAINING |

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

## Gap Closure Priority (Execution Order)

| Priority | Gap | Effort | Blocked By |
|----------|-----|--------|------------|
| 1 | GAP-FIX-1: ShiftMatcher test bug | 1 hour | Nothing |
| 2 | GAP-FIX-2: Conflict resolution state machine | 1-2 days | Nothing |
| 3 | GAP-FIX-3: Missing punch alert job | 2 hours | Nothing |
| 4 | GAP-FIX-4: Complete Amharic translations | 3 hours | Nothing (parallel) |
| 5 | GAP-FIX-5: PWA service worker | 3-4 hours | Nothing (parallel) |
| 6 | GAP-FIX-6: Payroll impact preview | 2 hours | Payroll engine (can stub) |
| 7 | GAP-FIX-7: QR scan camera fallback | 1 hour | Nothing |

**Total effort to close Phase 3: ~3-4 days**

---

## Phase 3 Exit Criteria (Updated)

- [x] Unified attendance engine handles all 9 sources
- [x] Shift engine matches employees to shifts correctly
- [ ] ShiftMatcher tests passing (GAP-FIX-1)
- [ ] Conflict resolution formal state machine (GAP-FIX-2)
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
- [ ] Missing punch alert job (GAP-FIX-3)
- [ ] Full Amharic translation coverage (GAP-FIX-4)
- [ ] PWA service worker + install prompt (GAP-FIX-5)
- [ ] Payroll impact preview on corrections (GAP-FIX-6)
- [ ] QR scan camera fallback (GAP-FIX-7)
- [ ] Browser verified: desktop + tablet + mobile + dark + light
- [ ] All Pest tests passing
- [ ] TenantIsolationTest passes
