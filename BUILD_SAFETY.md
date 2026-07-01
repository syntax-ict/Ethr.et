# ETHR — Build Safety & Pre-Deploy Checklist

**Last Updated:** 2026-07-01  
**Status:** All 547 backend tests passing · 0 TypeScript errors

---

## Quick Pre-Deploy Gate

Run this before every build. ALL must be green.

```bash
# Backend
cd api
php artisan test                        # 547 tests must pass
./vendor/bin/phpstan analyse            # Level 6, zero errors
./vendor/bin/pint --test                # No formatting issues

# Frontend
cd ../src
npx tsc --noEmit                        # Zero type errors
npx prettier --check src/               # No formatting issues
npx vitest run                          # All Vitest tests pass
```

---

## Environment Configuration Safety

### Production `.env` Must-Haves

```bash
APP_ENV=production
APP_DEBUG=false                         # NEVER true in prod
APP_KEY=base64:...                      # Must be set — php artisan key:generate

DB_CONNECTION=mysql
DB_DATABASE=ethr_prod
DB_PASSWORD=<strong_password>           # Never default/empty

REDIS_PASSWORD=<strong_password>

MINIO_KEY=<access_key>
MINIO_SECRET=<secret_key>

MAIL_MAILER=smtp
MAIL_PASSWORD=<smtp_password>

REVERB_APP_SECRET=<secret>
SANCTUM_STATEFUL_DOMAINS=app.ethr.et

QUEUE_CONNECTION=redis                  # NOT sync in prod
CACHE_STORE=redis                       # NOT file/array in prod
SESSION_DRIVER=redis                    # NOT file in prod
```

### Development vs Production Switches

| Setting | Dev Value | Prod Value |
|---------|-----------|------------|
| `APP_DEBUG` | `true` | **`false`** |
| `APP_ENV` | `local` | **`production`** |
| `QUEUE_CONNECTION` | `sync` | **`redis`** |
| `CACHE_STORE` | `array` | **`redis`** |
| `DB_CONNECTION` | `mysql` | **`mysql`** |
| `LOG_LEVEL` | `debug` | **`error`** |
| `TELESCOPE_ENABLED` | `true` | **`false`** |
| `PULSE_ENABLED` | `false` | **`false`** |

---

## Database Migration Safety Protocol

### Before Running Migrations in Production

1. **Backup first:**
   ```bash
   ./scripts/backup.sh
   ```

2. **Test on staging:**
   ```bash
   php artisan migrate --database=staging
   ```

3. **Check for destructive operations** in new migration files:
   - `dropColumn` — irreversible, verify data is backed up
   - `dropTable` — irreversible, check for FK dependencies
   - `change()` — may truncate data in SQLite/MySQL inconsistently
   - Any column added with `NOT NULL` and no default — will fail on non-empty table

4. **Run in maintenance mode:**
   ```bash
   php artisan down
   php artisan migrate
   php artisan up
   ```

5. **Verify migration status:**
   ```bash
   php artisan migrate:status
   ```

### Known Safe Migrations (Already Applied)
- `0001_01_01_*` — Initial schema (org, attendance, leave, payroll, notifications)
- `2026_06_27_*` — Personal access tokens
- `2026_06_28_*` — Badge number, reports, API keys, webhooks
- `2026_07_01_000001_*` — Notification preferences
- `2026_07_01_100001_*` — Device management upgrade
- `2026_07_01_200001_*` — Attendance settings + kiosk sessions (adds `kiosk_pin` to employees)

---

## Subsystem Integration Points

These subsystems talk to each other. A change in one affects others.

### Attendance Engine → Other Modules

| Dependency | Integration Point | Risk on Change |
|------------|-------------------|----------------|
| **Payroll** | `AttendanceRecord.worked_minutes`, `overtime_minutes` | Payroll runs use these for pay calculation |
| **Leave** | Attendance records vs leave requests for absence detection | Leave dates must not overlap attendance |
| **Corrections** | `AttendanceCorrection` links to `AttendanceRecord` | Corrections must apply to real records |
| **Devices** | `DeviceManager` → `AttendanceEngine` via `AttendanceSource::BIOMETRIC` | Token auth outside sanctum — don't add sanctum middleware to webhook routes |
| **Shifts** | `ShiftMatcher` reads `shift_assignments` | Date cast stored as datetime in SQLite — always use `->whereDate()` not `->where()` for date columns |
| **Notifications** | `AttendanceRecorded` event → listeners | Event must fire for all sources |

### Critical Subsystem Rules

1. **Never expose numeric PKs** — all API responses must return `public_id` only. Check: `->assertJsonMissingPath('id')`

2. **Idempotency keys required** on all attendance writes — prevents duplicates from mobile retries

3. **Kiosk routes outside `auth:sanctum`** — `/kiosk/authenticate` and `/kiosk/check-in` must stay in the pre-sanctum group. DeviceWebhook routes also outside sanctum.

4. **BelongsToTenant scope** — All tenant-scoped models use `whereDate()` not `where()` for date column comparisons (SQLite datetime vs date mismatch in tests)

5. **QR tokens use Laravel Crypt** — changing APP_KEY will invalidate all existing QR codes. Warn users before key rotation.

6. **AttendanceSetting::defaults()** must stay in sync with the `enabled_methods` array — all new sources must be added to both places

---

## Known Issues & Workarounds

### SQLite vs MariaDB Date Handling

**Problem:** Laravel's `date` cast stores values as `"2026-07-01 00:00:00"` in SQLite, but as `"2026-07-01"` in MariaDB DATE columns. String comparison in SQLite fails: `"2026-07-01 00:00:00" <= "2026-07-01"` → FALSE.

**Affected code:** `ShiftMatcher` (fixed — uses `whereDate()` now), any code comparing date-cast columns as strings.

**Rule:** When querying date-cast Eloquent columns against date strings, ALWAYS use `->whereDate('column', '<=', $date)` not `->where('column', '<=', $date)`.

### Carbon Mock Cleanup

**Problem:** Tests that call `Carbon::setTestNow()` and fail before resetting it contaminate the Carbon state for subsequent tests.

**Fix applied:** `AttendanceEngineTest.php` has `afterEach(fn() => Carbon::setTestNow())` to always reset.

**Rule:** Any test file that uses `Carbon::setTestNow()` must have an `afterEach` cleanup.

### QR Generate — Tenant Required

**Problem:** `QrAttendanceController::generate()` crashed with null pointer when tenant wasn't resolved from subdomain or X-Tenant header.

**Fix applied:** Early null guard with proper RFC-7807 400 response.

**Rule:** All controllers that call `CurrentTenant::get()` must null-check the result before calling `->id`.

---

## Deployment Order for Next Build

```
1. Run all tests locally (php artisan test — 547 pass)
2. TypeScript check (npx tsc --noEmit — 0 errors)
3. PHPStan (./vendor/bin/phpstan analyse — level 6 clean)
4. git tag vX.Y.Z
5. Deploy to staging:
   ./scripts/deploy.sh staging
6. Run php artisan migrate on staging
7. Smoke test: login, check-in, QR generate, kiosk auth
8. Deploy to production:
   ./scripts/deploy.sh prod
9. php artisan migrate (prod)
10. Monitor Horizon queue, error logs for 30 min
```

---

## Shift Management Frontend Gap

The biggest missing piece before production is the **Shift Management UI** (backend is complete):

- `GET /api/v1/shifts` — list shifts
- `POST /api/v1/shifts` — create shift
- `POST /api/v1/shifts/assign` — assign to employee/dept/branch
- `GET /api/v1/shifts/schedule` — view schedule

Until the UI is built, HR admins must use the API directly to create and assign shifts. Without shifts, attendance records default to 'pending' status.

**Priority:** Build `src/src/app/(dashboard)/shifts/` before production launch.

---

## Queue & Background Jobs

Horizon must be running in production for:

- `PullDeviceEventsJob` — runs every 5 min, syncs device attendance
- `SyncDevicesCommand` — manual trigger via `php artisan devices:sync`
- Notification jobs — email/SMS dispatch
- Report generation jobs

```bash
# Verify Horizon is running
php artisan horizon:status

# Restart after deploy
php artisan horizon:terminate
supervisorctl restart horizon
```

---

## Security Reminders

- [ ] Rotate `APP_KEY` on first production deploy, but NOT after (breaks Crypt tokens)
- [ ] Kiosk tokens are raw hex strings in DB — MinIO must not expose the DB directly
- [ ] Admin PIN for kiosks is bcrypt hashed — never store plain
- [ ] QR tokens expire (default 30 min) — valid only within that window
- [ ] Biometric webhook tokens are per-device — rotate via `POST /devices/{id}/regenerate-token`
- [ ] Employee `kiosk_pin` is `$hidden` in model — never returned in API responses
