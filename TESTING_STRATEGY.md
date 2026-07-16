# ETHR — Testing Strategy (v2.0)

## Testing Pyramid

```
        /  E2E (Playwright)  \          7 critical path flows
       /  Contract (OpenAPI)   \        API type enforcement
      /  Integration (Pest)      \      Multi-service flows
     /  Feature (Pest + Vitest)    \    Controller + component tests
    /  Unit (Pest + Vitest)          \  Service, calculator, utility tests
   /  Security (Pest)                  \ Tenant isolation, permissions
  /  Performance (Pest)                  \ Benchmark assertions
```

---

## 1. Backend Testing (Pest)

### Configuration
- Database: SQLite `:memory:` for speed
- Trait: `RefreshDatabase` on all test classes
- Host-based routing: `$this->getJson('http://acme.ethr.et/api/v1/...')`
- Auth guard: `$this->app['auth']->forgetGuards()` between requests that change auth state
- Assertion: `assertJsonMissingPath('id')` — never leak numeric PKs in any test

### Test Organization

```
api/tests/
  Feature/
    Auth/
      LoginTest.php
      MfaTest.php
      SessionTest.php
    Employees/
      EmployeeControllerTest.php
      EmployeeTransitionTest.php
      EmployeeImportTest.php
      EmployeeSearchTest.php
    Attendance/
      AttendanceEngineTest.php
      AttendanceSourcesTest.php
      ConflictResolverTest.php
      ShiftEngineTest.php
      MobileOfflineAttendanceTest.php
      KioskSessionTest.php
      AttendanceSettingTest.php
    Leave/
      LeaveRequestTest.php
      LeaveBalanceTest.php
      LeaveApprovalTest.php
      HolidayTest.php
    Payroll/
      TaxCalculatorTest.php
      PensionCalculatorTest.php
      OvertimeCalculatorTest.php
      PayrollEngineTest.php
      PayrollVoidTest.php
      LoanServiceTest.php
    Notifications/
      NotificationTest.php
      AnnouncementTest.php
    Reports/
      ReportBuilderTest.php
      ScheduledReportTest.php
    Integration/
      WebhookTest.php
      ApiKeyTest.php
      ExportTest.php
      ImportTest.php
    Admin/
      SuperAdminTest.php
      ImpersonationTest.php
      BillingTest.php
  Unit/
    Services/
      CalendarServiceTest.php
      FileServiceTest.php
      ConfidencesScorerTest.php
    Calculators/
      TaxCalculatorUnitTest.php
      PensionCalculatorUnitTest.php
      OvertimeCalculatorUnitTest.php
  Security/
    TenantIsolationTest.php
    PermissionTest.php
    WebhookSsrfTest.php
    FileUploadSecurityTest.php
    ImpersonationGuardrailsTest.php
  Performance/
    ResponseTimeTest.php
    SearchPerformanceTest.php
  Ethiopian/
    PagumenTest.php
    EthiopianCalendarTest.php
    EthiopianHolidayTest.php
    TaxBracketBoundaryTest.php
```

### Mandatory Test Patterns

**Every controller test must verify:**
1. Successful CRUD operations
2. Validation errors (missing required fields, invalid formats)
3. Authorization (permission denied returns 403)
4. Tenant isolation (cross-tenant access returns 404)
5. Numeric PK never in response (`assertJsonMissingPath('id')`)
6. Audit log created for sensitive operations

**Every calculation test must verify:**
1. Correct result for normal input
2. Boundary values (exactly at bracket limits, zero, maximum)
3. Integer arithmetic only (no float in any intermediate step)

---

## 2. Security Testing

### TenantIsolationTest (CI — Every Commit)

```php
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_tenant_scoped_models_have_trait(): void
    {
        $models = $this->discoverEloquentModels();
        $globalModels = ['Tenant', 'Plan', 'FeatureFlag', 'SuperAdmin'];

        foreach ($models as $model) {
            if (in_array(class_basename($model), $globalModels)) continue;

            $this->assertTrue(
                in_array(BelongsToTenant::class, class_uses_recursive($model)),
                "{$model} has tenant_id but missing BelongsToTenant trait"
            );
        }
    }

    public function test_cross_tenant_data_never_leaks(): void
    {
        // Create tenants A and B with data
        // Authenticate as tenant A
        // Assert all model queries for tenant B return empty
        // Repeat for every tenant-scoped model
    }

    public function test_all_api_endpoints_enforce_tenant_scope(): void
    {
        // Create data for tenant A
        // Authenticate as tenant B user
        // Hit every endpoint with tenant A's public_ids
        // Assert 404 or empty results (never 200 with data)
    }
}
```

### PermissionTest

```php
class PermissionTest extends TestCase
{
    public function test_each_endpoint_requires_permission(): void
    {
        // For each route that has a Policy:
        // 1. Create user with NO permissions
        // 2. Hit endpoint → assert 403
        // 3. Grant required permission
        // 4. Hit endpoint → assert 200
    }

    public function test_employee_cannot_access_admin_endpoints(): void { ... }
    public function test_supervisor_cannot_process_payroll(): void { ... }
    public function test_finance_cannot_manage_employees(): void { ... }
    public function test_custom_role_permissions_work(): void { ... }
}
```

### WebhookSsrfTest

```php
class WebhookSsrfTest extends TestCase
{
    /** @dataProvider privateIpProvider */
    public function test_rejects_private_ips(string $url): void
    {
        $this->postJson('/api/v1/webhooks', ['url' => $url, 'events' => ['employee.created']])
            ->assertStatus(422);
    }

    public function privateIpProvider(): array
    {
        return [
            ['http://10.0.0.1/webhook'],
            ['http://172.16.0.1/webhook'],
            ['http://192.168.1.1/webhook'],
            ['http://127.0.0.1/webhook'],
            ['http://169.254.169.254/webhook'],  // AWS metadata
            ['http://localhost/webhook'],
            ['http://[::1]/webhook'],
        ];
    }
}
```

---

## 3. Ethiopian Edge Case Tests

### PagumenTest

```php
class PagumenTest extends TestCase
{
    public function test_leave_spanning_pagumen_counts_correctly(): void
    {
        // Leave from Nehase 28 to Meskerem 5
        // Should count Pagumen working days correctly (5 or 6 days)
    }

    public function test_payroll_proration_pagumen_full_month(): void
    {
        // Employee hired on Pagumen 3, strategy = full_month
        // Should receive full monthly salary
    }

    public function test_payroll_proration_pagumen_daily_rate(): void
    {
        // Employee hired on Pagumen 3, strategy = daily_rate
        // 5-day month: salary = (annual / 365) × 3 remaining days
    }
}
```

### TaxBracketBoundaryTest

```php
class TaxBracketBoundaryTest extends TestCase
{
    public function test_salary_exactly_at_600(): void
    {
        // 600 ETB → 0% tax → 0 tax
    }

    public function test_salary_at_601(): void
    {
        // 601 ETB → 10% - 60 → 0.10 ETB
    }

    public function test_salary_exactly_at_10900(): void
    {
        // 10,900 ETB → 30% - 955 → 2,315 ETB
    }

    public function test_salary_at_10901(): void
    {
        // 10,901 ETB → 35% - 1,500 → 2,315.35 ETB
    }

    public function test_zero_salary(): void
    {
        // 0 ETB → 0 tax (unpaid leave month)
    }
}
```

### EthiopianCalendarTest

```php
class EthiopianCalendarTest extends TestCase
{
    public function test_new_year_conversion(): void
    {
        // Meskerem 1 = September 11 (non-leap) or September 12 (leap)
    }

    public function test_pagumen_length(): void
    {
        // 5 days in non-leap year, 6 in leap year
    }

    public function test_utc_plus_3_constant(): void
    {
        // Ethiopia does not observe DST
        // Verify +3 offset for summer and winter dates
    }

    public function test_holiday_detection_for_known_year(): void
    {
        // Verify all 13 Ethiopian holidays for Ethiopian year 2019
    }
}
```

---

## 4. Performance Benchmark Tests

```php
class ResponseTimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Seed 1000 employees, 3 months attendance, 2 months payroll
        $this->seed(PerformanceBenchmarkSeeder::class);
    }

    public function test_employee_list_under_500ms(): void
    {
        $start = microtime(true);
        $this->getJson('http://acme.ethr.et/api/v1/employees?per_page=25&sort=-created_at');
        $elapsed = (microtime(true) - $start) * 1000;
        $this->assertLessThan(500, $elapsed);
    }

    public function test_employee_search_under_100ms(): void
    {
        $start = microtime(true);
        $this->getJson('http://acme.ethr.et/api/v1/employees?search=abebe');
        $elapsed = (microtime(true) - $start) * 1000;
        $this->assertLessThan(100, $elapsed);
    }

    public function test_dashboard_executive_under_200ms(): void { ... }
    public function test_dashboard_manager_under_200ms(): void { ... }
    public function test_attendance_list_30days_under_300ms(): void { ... }
    public function test_payroll_run_detail_under_300ms(): void { ... }
    public function test_leave_balance_under_100ms(): void { ... }
}
```

---

## 5. Frontend Testing (Vitest)

### Configuration
- Framework: React Testing Library
- API mocking: MSW (Mock Service Worker) with generated OpenAPI types
- Snapshot testing: include Amharic text in snapshots

### Test Patterns

**Every component test must verify:**
1. Renders correctly with data
2. Loading state (skeleton)
3. Empty state (CTA)
4. Error state (retry button)
5. Dark mode (semantic tokens applied)
6. Amharic text rendering (where applicable)

**DataTable tests:**
- Renders with data
- Keyboard navigation (arrow keys, Enter, Escape)
- Column pinning
- Column resizing
- Sort direction toggle
- Filter application
- Row selection
- Row expansion
- Empty state with CTA
- Virtual scrolling with 1000+ rows

### Contract Testing (CI)

```bash
# Generate TypeScript types from OpenAPI spec
npx openapi-typescript api/openapi.yaml -o src/api/types/generated.ts

# Check for uncommitted changes
git diff --exit-code src/api/types/generated.ts
# If changes exist → CI fails → frontend types are out of sync with backend
```

All TanStack Query hooks and MSW handlers use generated types:

```typescript
import type { paths } from '@/api/types/generated';

type EmployeeResponse = paths['/api/v1/employees/{id}']['get']['responses']['200']['content']['application/json'];
```

---

## 6. E2E Testing (Playwright)

### Configuration
- Environment: Docker Compose with test database (seeded via DemoSeeder)
- Browsers: Chromium (primary), Firefox (secondary)
- Viewports: 1280px (desktop), 375px (mobile)
- Accessibility: aXe integrated in every flow

### Critical Path Flows (7 Suites)

**Suite 1: Signup Flow**
```
1. Visit landing page
2. Click "Start Free Trial"
3. Fill registration form (org name, email, password)
4. Verify email (enter code)
5. Complete setup wizard (select template, configure departments, skip to review)
6. Launch dashboard
7. Verify dashboard renders with correct data
```

**Suite 2: Employee Flow**
```
1. Login as HR admin
2. Navigate to employees
3. Create new employee (fill all tabs)
4. Verify employee appears in list
5. View employee detail
6. Edit employee (change department)
7. Transition employee to active
8. Verify timeline shows transition
```

**Suite 3: Attendance Flow**
```
1. Login as employee
2. Check in via web
3. Verify "Checked In" status on dashboard
4. Wait 1 second
5. Check out
6. Navigate to My Attendance
7. Verify today's record shows
```

**Suite 4: Leave Flow**
```
1. Login as employee
2. Apply for annual leave (3 days)
3. Verify pending status
4. Logout
5. Login as supervisor
6. Navigate to approval center
7. Approve leave request
8. Logout
9. Login as employee
10. Verify leave balance reduced
```

**Suite 5: Payroll Flow**
```
1. Login as finance admin
2. Navigate to payroll
3. Run payroll for current month
4. Review entries (verify calculation)
5. Approve payroll run
6. Logout
7. Login as employee
8. Navigate to My Payslips
9. Verify payslip appears
10. Download PDF
```

**Suite 6: Admin Flow**
```
1. Login as super admin
2. View tenant list
3. Click tenant → view detail
4. Impersonate tenant admin (enter MFA)
5. Verify impersonation banner shows
6. Verify restricted action blocked (try create API key)
7. Exit impersonation
8. Verify back to super admin view
```

**Suite 7: Offline Flow**
```
1. Login as employee
2. Enable offline mode (DevTools network throttle)
3. Check in via web
4. Verify offline banner shows
5. Verify check-in queued (UI feedback)
6. Disable offline mode
7. Verify sync occurs
8. Verify attendance record created
```

### Accessibility Integration

```typescript
import { injectAxe, checkA11y } from 'axe-playwright';

test('dashboard is accessible', async ({ page }) => {
    await page.goto('/dashboard');
    await injectAxe(page);
    await checkA11y(page, null, {
        detailedReport: true,
        detailedReportOptions: { html: true },
    });
    // aXe must report zero violations
});
```

Integrated into every E2E suite: after each major page load, run aXe check.

---

## 7. CI Pipeline

```
on push to main:
  1. Run PHPStan (level 6) → fail on any error
  2. Run Pint --test → fail on formatting issues
  3. Run Pest (all tests including security + performance) → fail on any failure
  4. Run TypeScript compilation (tsc --noEmit) → fail on type errors
  5. Run Prettier --check → fail on formatting issues
  6. Run Vitest → fail on any failure
  7. Regenerate OpenAPI types → fail if uncommitted changes
  8. Run Playwright E2E (Docker environment) → fail on any failure
  9. Check bundle size → warn if > 150KB gzipped
  10. Run Lighthouse CI → warn if perf < 80 or a11y < 90

Steps 1-7: blocking (PR cannot merge)
Steps 8-10: blocking on main branch, warning on feature branches
```

---

## 8. Test Data

### PerformanceBenchmarkSeeder

Seeds test database with realistic volume:
- 1 tenant
- 1000 employees across 10 departments and 5 branches
- 3 months of attendance data (22 working days × 1000 employees × 3 months = ~66,000 records)
- 2 months of payroll data (2 runs × 1000 entries = 2,000 entries)
- 500 leave requests (mixed statuses)
- 100 leave types × balance records
- Realistic distribution of statuses, late arrivals, absences

### DemoSeeder (Phase 1)

Seeds demo tenant with presentation-ready data:
- 150+ employees with Ethiopian names
- 6 months of varied attendance data
- 3 months of approved payroll
- Pending leave requests and approvals
- Active and inactive devices
- Published announcements

---

## 9. Test Coverage Targets

| Area | Target | Measurement |
|---|---|---|
| Backend endpoint coverage | 100% | Every controller action has a test |
| Backend security coverage | 100% | TenantIsolationTest + PermissionTest |
| Frontend component coverage | > 80% | Vitest coverage report |
| E2E critical path coverage | 100% | 7 suites all passing |
| Accessibility | Zero errors | aXe on 10 key pages |
| Performance benchmarks | All passing | 7 benchmark assertions |
| Contract testing | Enforced in CI | Type generation check |
