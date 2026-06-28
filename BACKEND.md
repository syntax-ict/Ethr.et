# ETHR — Backend Conventions

## Stack

- Laravel 12, PHP 8.2 (strict_types on all files)
- MariaDB 10.11 (prod), SQLite in-memory (tests)
- Redis 7+ (cache, queues, sessions, rate limiting)
- Sanctum (API tokens)
- Horizon (queue dashboard)
- Reverb (WebSocket)
- MinIO (file storage via S3 driver)
- Pest (testing)
- PHPStan level 6
- Laravel Pint (formatting)

---

## Project Structure

```
api/
  app/
    Console/
      Commands/             Artisan commands
    Enums/                  PHP 8.1 backed enums
    Events/                 Domain events
    Exceptions/             Custom exception classes
      Handler.php           RFC-7807 error formatting
    Http/
      Controllers/
        Api/V1/             All API controllers
      Middleware/
        ResolveTenant.php   Subdomain -> CurrentTenant
        EnsureTenantActive.php
      Requests/             FormRequest validation classes
      Resources/            JsonResource response classes
    Jobs/                   Queue jobs
    Listeners/              Event listeners
    Mail/                   Mailable classes
    Models/
      Tenant.php
      User.php
      Employee.php
      ...
    Notifications/          Laravel notification classes
    Observers/              Model observers
    Policies/               Authorization policies
    Providers/
    Rules/                  Custom validation rules
    Services/               Business logic services
      Attendance/
        AttendanceEngine.php
        ShiftMatcher.php
        ConflictResolver.php
        ConfidenceScorer.php
      Payroll/
        PayrollEngine.php
        TaxCalculator.php
        PensionCalculator.php
      Billing/
        BillingService.php
        InvoiceGenerator.php
      Import/
        CsvParser.php
        EmployeeImporter.php
      Devices/
        DeviceAdapter.php        Interface
        HikvisionAdapter.php
        ZktecoAdapter.php
      Notifications/
        SmsSender.php            Interface
        EthioTelecomSmsSender.php
        LogSmsSender.php
      FileService.php
      CalendarService.php        Ethiopian calendar
    Traits/
      BelongsToTenant.php
      HasPublicId.php
      HasAuditLog.php
  config/
  database/
    migrations/
    seeders/
  lang/
    en/                     English translations
    am/                     Amharic translations
  routes/
    api.php                 All API routes
  tests/
    Feature/                Feature tests (HTTP, database)
    Unit/                   Unit tests (pure logic)
```

---

## Model Conventions

### Base Pattern

Every domain model follows this structure:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    use BelongsToTenant, HasPublicId, HasFactory;

    protected $fillable = [
        'tenant_id',
        'public_id',
        'name',
        'email',
        // ... explicit fields only
    ];

    protected $hidden = [
        'id',           // never expose numeric PK
        'tenant_id',    // never expose in API
    ];

    protected $casts = [
        'hire_date' => 'date',
        'status' => EmployeeStatus::class,
        'salary_cents' => 'integer',
    ];
}
```

### Key Traits

**BelongsToTenant** — auto-applies `WHERE tenant_id = ?` on all queries:
- Uses `CurrentTenant` singleton to resolve tenant
- Deny-all (`WHERE 0 = 1`) when no tenant resolved
- Override with `withoutGlobalScopes()` for super admin/seeder contexts

**HasPublicId** — auto-generates ULID on creation:
- `public_id` is the only ID exposed in API responses
- Route model binding uses `public_id`, never `id`

**HasAuditLog** — convenience for recording audit entries:
- `$this->audit('action', ['key' => 'value'])`

### Enum Pattern

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum EmployeeStatus: string
{
    case HIRED = 'hired';
    case PROBATION = 'probation';
    case CONFIRMED = 'confirmed';
    case SUSPENDED = 'suspended';
    case RESIGNED = 'resigned';
    case TERMINATED = 'terminated';
    case RETIRED = 'retired';

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::HIRED => in_array($target, [self::PROBATION]),
            self::PROBATION => in_array($target, [self::CONFIRMED, self::TERMINATED]),
            self::CONFIRMED => in_array($target, [self::SUSPENDED, self::RESIGNED, self::TERMINATED, self::RETIRED]),
            self::SUSPENDED => in_array($target, [self::CONFIRMED, self::TERMINATED]),
            default => false,
        };
    }
}
```

---

## Controller Conventions

### Pattern

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;

class EmployeeController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', Employee::class);

        $employees = Employee::query()
            ->filter(request('filter', []))
            ->search(request('search'))
            ->sort(request('sort', '-created_at'))
            ->paginate(request('per_page', 25));

        return EmployeeResource::collection($employees);
    }

    public function store(StoreEmployeeRequest $request)
    {
        $this->authorize('create', Employee::class);

        $employee = Employee::create($request->validated());

        AuditLog::record('employee.created', $employee);

        return new EmployeeResource($employee);
    }

    public function show(Employee $employee)
    {
        $this->authorize('view', $employee);

        return new EmployeeResource($employee->load(['department', 'branch', 'documents']));
    }
}
```

### Rules
- One controller per resource (no god controllers)
- `$this->authorize()` on every action — no exceptions
- Return `JsonResource` instances (never raw arrays)
- Inject `FormRequest` for create/update (never inline validation)
- Use Eloquent query scopes for filtering/sorting
- Record audit log for state-changing operations

---

## FormRequest Conventions

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\EmployeeStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // handled by Policy in controller
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:employees,email'],
            'phone' => ['nullable', 'string', 'regex:/^\+251\d{9}$/'],
            'employee_code' => ['required', 'string', 'max:50'],
            'department_id' => ['required', 'exists:departments,public_id'],
            'position_id' => ['required', 'exists:positions,public_id'],
            'hire_date' => ['required', 'date'],
            'salary_cents' => ['required', 'integer', 'min:0'],
            'status' => ['sometimes', new Enum(EmployeeStatus::class)],
        ];
    }
}
```

---

## Policy Conventions

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\User;

class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([
            UserRole::TENANT_ADMIN,
            UserRole::HR_ADMIN,
            UserRole::DEPT_ADMIN,
            UserRole::SUPERVISOR,
        ]);
    }

    public function view(User $user, Employee $employee): bool
    {
        if ($user->hasRole(UserRole::TENANT_ADMIN) || $user->hasRole(UserRole::HR_ADMIN)) {
            return true;
        }

        if ($user->hasRole(UserRole::SUPERVISOR)) {
            return $employee->supervisor_id === $user->employee?->id;
        }

        return $user->employee?->id === $employee->id;
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole([UserRole::TENANT_ADMIN, UserRole::HR_ADMIN]);
    }

    public function update(User $user, Employee $employee): bool
    {
        return $user->hasAnyRole([UserRole::TENANT_ADMIN, UserRole::HR_ADMIN]);
    }

    public function delete(User $user, Employee $employee): bool
    {
        return $user->hasRole(UserRole::TENANT_ADMIN);
    }
}
```

---

## Service Layer

Business logic lives in Services, never in Controllers or Models.

```php
<?php

declare(strict_types=1);

namespace App\Services\Attendance;

class AttendanceEngine
{
    public function __construct(
        private readonly ConfidenceScorer $scorer,
        private readonly ConflictResolver $resolver,
        private readonly ShiftMatcher $shiftMatcher,
    ) {}

    public function record(AttendanceInput $input): AttendanceRecord
    {
        // 1. Validate idempotency
        // 2. Match to shift
        // 3. Calculate confidence
        // 4. Check for duplicates/conflicts
        // 5. Store record
        // 6. Dispatch AttendanceRecorded event
        // 7. Return result
    }
}
```

### Rules
- Services are stateless (injected via constructor DI)
- Services throw domain exceptions (never return error arrays)
- Services dispatch events (never send notifications directly)
- Services are testable in isolation (mock dependencies)

---

## Error Handling (RFC-7807)

All API errors return this format:

```json
{
  "type": "https://ethr.et/errors/validation",
  "title": "Validation Error",
  "status": 422,
  "detail": "The given data was invalid.",
  "errors": {
    "email": ["The email field is required."]
  }
}
```

Standard error types:

| Type | Status | When |
|---|---|---|
| `validation` | 422 | FormRequest validation fails |
| `unauthorized` | 401 | Not authenticated |
| `forbidden` | 403 | Policy denies access |
| `not-found` | 404 | Model not found |
| `conflict` | 409 | Duplicate / conflict |
| `rate-limited` | 429 | Too many requests |
| `server-error` | 500 | Unexpected server error |

---

## Migration Conventions

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();                                    // BIGINT auto-increment (internal)
            $table->char('public_id', 26)->unique();         // ULID (API-facing)
            $table->foreignId('tenant_id')->constrained();   // tenant isolation
            $table->foreignId('user_id')->nullable()->constrained();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('employee_code');
            $table->foreignId('department_id')->nullable()->constrained();
            $table->foreignId('branch_id')->nullable()->constrained();
            $table->foreignId('position_id')->nullable()->constrained();
            $table->string('status')->default('hired');
            $table->date('hire_date');
            $table->bigInteger('salary_cents')->default(0);  // ETB in cents
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'employee_code']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'department_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
```

### Rules
- Always include `id()`, `public_id`, `tenant_id` (except global models)
- Use `foreignId()->constrained()` for relationships
- Add composite indexes for common query patterns
- Use `bigInteger` for currency (never decimal/float)
- Use string columns for enums (for readability and flexibility)
- Include `softDeletes()` on all business entities
- `timestamps()` on everything

---

## Audit Logging

```php
// Recording an audit entry
AuditLog::record('employee.status_changed', $employee, [
    'from' => 'probation',
    'to' => 'confirmed',
    'reason' => 'Completed 3-month probation',
]);
```

The `audit_log` table:
- `id`, `tenant_id`, `user_id`, `action`, `auditable_type`, `auditable_id`, `payload` (JSON), `ip_address`, `user_agent`, `created_at`
- Append-only (no updates, no deletes)
- Indexed by `tenant_id`, `action`, `auditable_type + auditable_id`, `created_at`

---

## Queue Job Pattern

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\CurrentTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessPayroll implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int $tenantId,
        private readonly int $payrollRunId,
    ) {
        $this->onQueue('payroll');
    }

    public function handle(PayrollEngine $engine, CurrentTenant $currentTenant): void
    {
        $tenant = Tenant::findOrFail($this->tenantId);
        $currentTenant->set($tenant);

        $engine->process($this->payrollRunId);
    }

    public int $tries = 1;
    public int $timeout = 300;
}
```

### Rules
- Always serialize `tenantId` (not the Tenant model)
- Re-resolve tenant in `handle()` to set global scope
- Set appropriate `$tries` and `$timeout`
- Assign to named queue (`attendance`, `payroll`, `notifications`, `exports`, `devices`, `sync`)

---

## Notification Pattern

```php
// Dispatch via event listener (never from controller)
$employee->user->notify(new LeaveApprovedNotification($leave));
```

Notification channels:
- `database` — always (in-app notifications)
- `mail` — configurable per notification type
- `broadcast` — Reverb for real-time
- SMS — via `SmsSender` interface for critical alerts

---

## Testing Conventions

```php
<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;

describe('Employee Management', function () {
    beforeEach(function () {
        $this->tenant = Tenant::factory()->create(['subdomain' => 'acme']);
        $this->admin = User::factory()->tenantAdmin($this->tenant)->create();
    });

    it('lists employees for authorized user', function () {
        Employee::factory()->count(3)->for($this->tenant)->create();

        $this->actingAs($this->admin)
            ->getJson("http://acme.ethr.et/api/v1/employees")
            ->assertOk()
            ->assertJsonCount(3)
            ->assertJsonMissingPath('0.id')          // no numeric PK
            ->assertJsonPath('0.public_id', fn ($v) => strlen($v) === 26);
    });

    it('prevents cross-tenant access', function () {
        $otherTenant = Tenant::factory()->create(['subdomain' => 'other']);
        $employee = Employee::factory()->for($otherTenant)->create();

        $this->actingAs($this->admin)
            ->getJson("http://acme.ethr.et/api/v1/employees/{$employee->public_id}")
            ->assertNotFound();
    });

    it('requires authorization', function () {
        $regularUser = User::factory()->employee($this->tenant)->create();

        $this->actingAs($regularUser)
            ->postJson("http://acme.ethr.et/api/v1/employees", [...])
            ->assertForbidden();
    });
});
```

### Rules
- Use `describe` + `it` blocks (Pest style)
- Full URL with subdomain for every request
- Assert no numeric PK leaks (`assertJsonMissingPath('id')`, `assertJsonMissingPath('*.id')`)
- Test authorization (happy path + forbidden)
- Test tenant isolation (cross-tenant access denied)
- Test validation (missing/invalid fields)
- Use factories for test data
- One test file per controller/service

---

## Localization

```php
// In controller/service
__('employee.created_successfully')

// In FormRequest messages
public function messages(): array
{
    return [
        'name.required' => __('validation.employee.name_required'),
    ];
}
```

Translation file structure:
```
lang/
  en/
    auth.php
    employee.php
    attendance.php
    leave.php
    payroll.php
    notification.php
    validation.php
    common.php
  am/
    auth.php
    employee.php
    ... (same structure)
```

---

## Ethiopian Calendar Service

```php
<?php

declare(strict_types=1);

namespace App\Services;

class CalendarService
{
    public function toEthiopian(Carbon $gregorian): EthiopianDate;
    public function toGregorian(EthiopianDate $ethiopian): Carbon;
    public function getEthiopianHolidays(int $ethiopianYear): Collection;
    public function isEthiopianHoliday(Carbon $date): bool;
    public function formatEthiopian(Carbon $date, string $locale = 'am'): string;
    public function ethiopianMonthName(int $month, string $locale = 'am'): string;
}
```

All date storage is Gregorian (UTC). Ethiopian calendar is display-only, computed on the fly.
