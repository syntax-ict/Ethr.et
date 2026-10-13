<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

use App\Models\AttendanceSetting;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Grade;
use App\Models\LeaveType;
use App\Models\PayrollRule;
use App\Models\Position;
use App\Models\Shift;
use App\Models\ShiftRotation;
use App\Models\ShiftRotationStep;
use App\Models\Tenant;
use App\Services\Holiday\HolidayService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns an organization template (or an edited configuration plan) into real
 * tenant records: branches, departments, positions, grades, shifts, shift
 * rotations, leave types, allowance definitions, public holidays, attendance
 * settings and tenant settings.
 *
 * Two rules govern every write:
 *
 *  1. **Create-only.** A record whose natural key already exists is reported as
 *     skipped and left untouched, so re-applying a template never overwrites
 *     configuration the tenant has edited. This is what makes application
 *     idempotent.
 *  2. **Soft-delete aware.** Departments, positions, grades, shifts, leave
 *     types, and branches are all soft-deleted per the CLAUDE.md policy, and
 *     their `(tenant_id, code)` unique indexes still cover trashed rows. A
 *     trashed match is restored rather than re-inserted, which would otherwise
 *     be an integrity violation.
 *
 * Queries bypass the tenant global scope and pass `tenant_id` explicitly so the
 * service is safe to call from a job or console command where no tenant is
 * resolved on the container.
 */
final class OrganizationProvisioner
{
    public function __construct(
        private readonly LeaveTypeCatalog $leaveTypes,
        private readonly HolidayService $holidays,
    ) {}

    /**
     * @param  array<string, mixed>  $plan  a template's `template_data`, or an edited copy of one
     */
    public function apply(Tenant $tenant, array $plan): ProvisioningResult
    {
        return DB::transaction(function () use ($tenant, $plan): ProvisioningResult {
            $result = new ProvisioningResult;

            $this->provisionBranches($tenant, $this->listOf($plan, 'branches'), $result);
            $this->provisionDepartments($tenant, $this->listOf($plan, 'departments'), $result);
            $this->provisionPositions($tenant, $this->listOf($plan, 'positions'), $result);
            $this->provisionGrades($tenant, $this->listOf($plan, 'grades'), $result);
            $this->provisionShifts($tenant, $this->listOf($plan, 'shifts'), $result);
            // After shifts: a rotation's steps name shifts, and a step whose
            // shift does not exist would silently become a rest day.
            $this->provisionRotations($tenant, $this->listOf($plan, 'rotations'), $result);
            $this->provisionLeaveTypes($tenant, $this->listOf($plan, 'leave_types'), $result);
            $this->provisionAllowances($tenant, $this->listOf($plan, 'allowances'), $result);
            $this->provisionHolidays($tenant, $plan, $result);
            $this->applyAttendanceSettings($tenant, $plan['attendance'] ?? [], $result);
            $this->applySettings($tenant, $plan['settings'] ?? [], $result);

            return $result;
        });
    }

    // ── Resources ──────────────────────────────────────────────────

    /** @param array<int, mixed> $entries */
    private function provisionBranches(Tenant $tenant, array $entries, ProvisioningResult $result): void
    {
        foreach ($entries as $entry) {
            $attributes = $this->normalizeNamed($entry, 'name');
            if ($attributes === null) {
                continue;
            }

            $this->createOrSkip($result, 'branches', Branch::class, $tenant, 'name', $attributes['name'], function () use ($tenant, $attributes) {
                return [
                    'tenant_id' => $tenant->id,
                    'name' => $attributes['name'],
                    'name_am' => $attributes['name_am'] ?? null,
                    'code' => $attributes['code'] ?? $this->generateCode($attributes['name'], Branch::class, $tenant->id, 20),
                    'city' => $attributes['city'] ?? null,
                    'is_active' => true,
                ];
            });
        }
    }

    /** @param array<int, mixed> $entries */
    private function provisionDepartments(Tenant $tenant, array $entries, ProvisioningResult $result): void
    {
        foreach ($entries as $entry) {
            $attributes = $this->normalizeNamed($entry, 'name');
            if ($attributes === null) {
                continue;
            }

            $this->createOrSkip($result, 'departments', Department::class, $tenant, 'name', $attributes['name'], function () use ($tenant, $attributes) {
                return [
                    'tenant_id' => $tenant->id,
                    'name' => $attributes['name'],
                    'name_am' => $attributes['name_am'] ?? null,
                    'code' => $attributes['code'] ?? $this->generateCode($attributes['name'], Department::class, $tenant->id, 20),
                    'is_active' => true,
                ];
            });
        }
    }

    /** @param array<int, mixed> $entries */
    private function provisionPositions(Tenant $tenant, array $entries, ProvisioningResult $result): void
    {
        foreach ($entries as $entry) {
            // Positions call the human-readable field `title`, but templates have
            // always written them as bare strings or `{name: ...}`.
            $attributes = $this->normalizeNamed($entry, 'title');
            if ($attributes === null) {
                continue;
            }

            $this->createOrSkip($result, 'positions', Position::class, $tenant, 'title', $attributes['title'], function () use ($tenant, $attributes) {
                return [
                    'tenant_id' => $tenant->id,
                    'title' => $attributes['title'],
                    'title_am' => $attributes['title_am'] ?? $attributes['name_am'] ?? null,
                    'code' => $attributes['code'] ?? $this->generateCode($attributes['title'], Position::class, $tenant->id, 20),
                    'description' => $attributes['description'] ?? null,
                    'is_active' => true,
                ];
            });
        }
    }

    /** @param array<int, mixed> $entries */
    private function provisionGrades(Tenant $tenant, array $entries, ProvisioningResult $result): void
    {
        foreach ($entries as $index => $entry) {
            $attributes = $this->normalizeNamed($entry, 'name');
            if ($attributes === null) {
                continue;
            }

            $this->createOrSkip($result, 'grades', Grade::class, $tenant, 'name', $attributes['name'], function () use ($tenant, $attributes, $index) {
                return [
                    'tenant_id' => $tenant->id,
                    'name' => $attributes['name'],
                    'min_salary_cents' => (int) ($attributes['min_salary_cents'] ?? 0),
                    'max_salary_cents' => (int) ($attributes['max_salary_cents'] ?? 0),
                    'sort_order' => (int) ($attributes['sort_order'] ?? ($index + 1) * 10),
                ];
            });
        }
    }

    /** @param array<int, mixed> $entries */
    private function provisionShifts(Tenant $tenant, array $entries, ProvisioningResult $result): void
    {
        $tenantHasDefault = Shift::withoutGlobalScope('tenant')
            ->withTrashed()
            ->where('tenant_id', $tenant->id)
            ->where('is_default', true)
            ->exists();

        foreach ($entries as $index => $entry) {
            $attributes = $this->normalizeNamed($entry, 'name');
            if ($attributes === null) {
                continue;
            }

            // Templates have used `start`/`end`/`days` since the first seeder;
            // the column names are `start_time`/`end_time`/`working_days`.
            $start = $this->normalizeTime($attributes['start_time'] ?? $attributes['start'] ?? null);
            $end = $this->normalizeTime($attributes['end_time'] ?? $attributes['end'] ?? null);

            if ($start === null || $end === null) {
                $result->warn("Shift \"{$attributes['name']}\" skipped: start or end time missing.");

                continue;
            }

            $days = $this->normalizeWorkingDays($attributes['working_days'] ?? $attributes['days'] ?? '1,2,3,4,5');

            if ($days === null) {
                $result->warn("Shift \"{$attributes['name']}\" skipped: working days must be ISO days 1 (Monday) to 7 (Sunday).");

                continue;
            }

            $isFirst = $index === 0;

            $this->createOrSkip($result, 'shifts', Shift::class, $tenant, 'name', $attributes['name'], function () use ($tenant, $attributes, $start, $end, $days, $isFirst, $tenantHasDefault) {
                return [
                    'tenant_id' => $tenant->id,
                    'name' => $attributes['name'],
                    'name_am' => $attributes['name_am'] ?? null,
                    'start_time' => $start,
                    'end_time' => $end,
                    'crosses_midnight' => $end <= $start,
                    'grace_minutes' => (int) ($attributes['grace_minutes'] ?? 15),
                    'early_departure_minutes' => (int) ($attributes['early_departure_minutes'] ?? 15),
                    'break_minutes' => (int) ($attributes['break_minutes'] ?? 60),
                    'working_days' => $days,
                    'is_default' => $isFirst && ! $tenantHasDefault,
                    'is_active' => true,
                ];
            });
        }
    }

    /**
     * A rotation and its steps, created together. Steps name shifts by name;
     * every one must resolve to a live shift of this tenant, or the rotation is
     * skipped with a warning rather than created with a hole where a shift
     * should be — a missing step is read as a rest day.
     *
     * @param  array<int, mixed>  $entries
     */
    private function provisionRotations(Tenant $tenant, array $entries, ProvisioningResult $result): void
    {
        foreach ($entries as $entry) {
            $attributes = $this->normalizeNamed($entry, 'name');
            if ($attributes === null) {
                continue;
            }

            $name = $attributes['name'];
            $cycle = (int) ($attributes['cycle_days'] ?? 0);
            $steps = is_array($attributes['steps'] ?? null) ? $attributes['steps'] : [];

            if ($cycle < 1 || $cycle > 366 || $steps === []) {
                $result->warn("Rotation \"{$name}\" skipped: it needs a cycle of 1 to 366 days and at least one step.");

                continue;
            }

            $existing = $this->query(ShiftRotation::class, $tenant->id)->where('name', $name)->first();
            if ($existing instanceof ShiftRotation) {
                if ($existing->trashed()) {
                    $existing->restore();
                    $result->restored('rotations');
                } else {
                    $result->skipped('rotations');
                }

                continue;
            }

            $shiftIds = $this->query(Shift::class, $tenant->id)
                ->whereNull('deleted_at')
                ->pluck('id', 'name');

            $resolved = [];
            $problem = null;
            foreach ($steps as $step) {
                $offset = is_array($step) ? ($step['day_offset'] ?? null) : null;
                $shiftName = is_array($step) ? ($step['shift'] ?? null) : null;

                if (! is_int($offset) || $offset < 0 || $offset >= $cycle || isset($resolved[$offset])) {
                    $problem = 'its steps must have distinct day offsets inside the cycle';
                    break;
                }
                if ($shiftName !== null && (! is_string($shiftName) || ! isset($shiftIds[$shiftName]))) {
                    $problem = 'a step names the shift "'.(is_string($shiftName) ? $shiftName : '?').'", which does not exist';
                    break;
                }

                $resolved[$offset] = $shiftName === null ? null : (int) $shiftIds[$shiftName];
            }

            if ($problem !== null) {
                $result->warn("Rotation \"{$name}\" skipped: {$problem}.");

                continue;
            }

            $rotation = new ShiftRotation;
            $rotation->fill([
                'tenant_id' => $tenant->id,
                'name' => $name,
                'name_am' => $attributes['name_am'] ?? null,
                'description' => $attributes['description'] ?? null,
                'cycle_days' => $cycle,
                'is_active' => true,
            ]);
            $rotation->save();

            foreach ($resolved as $offset => $shiftId) {
                ShiftRotationStep::query()->create([
                    'tenant_id' => $tenant->id,
                    'shift_rotation_id' => $rotation->id,
                    'day_offset' => $offset,
                    'shift_id' => $shiftId,
                ]);
            }

            $result->created('rotations');
        }
    }

    /** @param array<int, mixed> $entries */
    private function provisionLeaveTypes(Tenant $tenant, array $entries, ProvisioningResult $result): void
    {
        foreach ($entries as $entry) {
            if (! is_string($entry) && ! is_array($entry)) {
                continue;
            }

            $definition = $this->leaveTypes->resolve($entry);

            if ($definition === null) {
                $label = is_string($entry) ? $entry : json_encode($entry);
                $result->warn("Leave type {$label} skipped: unknown code and no name supplied.");

                continue;
            }

            $this->createOrSkip($result, 'leave_types', LeaveType::class, $tenant, 'code', $definition['code'], function () use ($tenant, $definition) {
                return array_merge($definition, ['tenant_id' => $tenant->id]);
            });
        }
    }

    /**
     * Allowance definitions, always created switched off with a zero amount.
     *
     * The payroll engine pays every active allowance to every employee
     * (AllowanceService), so an amount here would be a pay decision a template
     * has no business making. The admin sets the amount and tax treatment and
     * switches it on; until then it is a named, reviewable placeholder that
     * changes no one's pay. Natural key: the name within the allowance category.
     *
     * @param  array<int, mixed>  $entries
     */
    private function provisionAllowances(Tenant $tenant, array $entries, ProvisioningResult $result): void
    {
        foreach ($entries as $index => $entry) {
            $attributes = $this->normalizeNamed($entry, 'name');
            if ($attributes === null) {
                continue;
            }

            $name = mb_substr($attributes['name'], 0, 100);

            $exists = $this->query(PayrollRule::class, $tenant->id)
                ->where('category', 'allowance')
                ->where('name', $name)
                ->exists();

            if ($exists) {
                $result->skipped('allowances');

                continue;
            }

            $rule = new PayrollRule;
            $rule->fill([
                'tenant_id' => $tenant->id,
                'name' => $name,
                'type' => 'fixed',
                'category' => 'allowance',
                'formula' => ['amount_cents' => 0],
                'is_taxable' => true,
                'is_active' => false,
                'sort_order' => ($index + 1) * 10,
            ]);
            $rule->save();

            $result->created('allowances');
        }
    }

    /**
     * Per-field and create-only, like the rest. A tenant's attendance settings
     * row is created with the model defaults the first time anything reads it,
     * so "the row exists" says nothing about whether the admin chose anything.
     * A field is set only while it still holds its default; one the admin has
     * changed is reported as skipped and left alone.
     */
    private function applyAttendanceSettings(Tenant $tenant, mixed $values, ProvisioningResult $result): void
    {
        if (! is_array($values) || $values === []) {
            return;
        }

        $defaults = AttendanceSetting::defaults();
        $values = array_intersect_key($values, $defaults);

        if ($values === []) {
            return;
        }

        $setting = $this->query(AttendanceSetting::class, $tenant->id)->first();

        if (! $setting instanceof AttendanceSetting) {
            $setting = new AttendanceSetting;
            $setting->fill(['tenant_id' => $tenant->id, ...$defaults]);
        }

        foreach ($values as $field => $value) {
            if ($setting->getAttribute($field) == $defaults[$field] && $value != $defaults[$field]) {
                $setting->setAttribute($field, $value);
                $result->created('attendance_settings');
            } else {
                $result->skipped('attendance_settings');
            }
        }

        $setting->save();
    }

    /**
     * Seed this year's Ethiopian public holidays via the shared HolidayService,
     * which computes the movable feasts exactly (Orthodox computus for
     * Fasika/Siklet, tabular Hijri for the Eids, flagged estimated). A template
     * can opt out with `holidays: false`.
     *
     * @param  array<string, mixed>  $plan
     */
    private function provisionHolidays(Tenant $tenant, array $plan, ProvisioningResult $result): void
    {
        if (($plan['holidays'] ?? true) === false) {
            return;
        }

        // autoDetect is itself idempotent — it matches on date and skips
        // holidays the tenant already has, so re-applying a template is safe.
        // This year and next: a run covers one Gregorian year (audit N57), and
        // an organisation set up in October needs January's Genna and Timkat
        // as much as this year's remaining ones. Years after that are
        // RollForwardHolidaysJob's.
        $year = (int) Carbon::now()->year;
        $created = $this->holidays->autoDetect($tenant->id, $year)
            + $this->holidays->autoDetect($tenant->id, $year + 1);

        for ($i = 0; $i < $created; $i++) {
            $result->created('holidays');
        }
    }

    private function applySettings(Tenant $tenant, mixed $settings, ProvisioningResult $result): void
    {
        if (! is_array($settings) || $settings === []) {
            return;
        }

        $current = $tenant->settings ?? [];
        $original = $current;

        // Create-only, one level deep: a namespace the tenant has already
        // configured (`payroll`, `attendance`, …) is left alone entirely rather
        // than deep-merged, so a template can never reintroduce a default the
        // tenant deliberately removed.
        foreach ($settings as $key => $value) {
            if (array_key_exists($key, $current)) {
                $result->skipped('settings');

                continue;
            }

            $current[$key] = $value;
            $result->created('settings');
        }

        if ($current !== $original) {
            $tenant->update(['settings' => $current]);
        }
    }

    // ── Helpers ────────────────────────────────────────────────────

    /**
     * Look up by natural key across trashed rows, then create, restore, or skip.
     *
     * @param  class-string<Model>  $modelClass
     * @param  callable(): array<string, mixed>  $attributes
     */
    private function createOrSkip(
        ProvisioningResult $result,
        string $resource,
        string $modelClass,
        Tenant $tenant,
        string $keyColumn,
        string $keyValue,
        callable $attributes,
    ): void {
        $query = $this->query($modelClass, $tenant->id)
            ->where($keyColumn, $keyValue);

        $existing = $query->first();

        if ($existing !== null) {
            if ($this->softDeletes($modelClass) && $existing->trashed()) {
                $existing->restore();
                $result->restored($resource);

                return;
            }

            $result->skipped($resource);

            return;
        }

        $model = new $modelClass;
        $model->fill($attributes());
        $model->save();

        $result->created($resource);
    }

    /**
     * Query a tenant-scoped model without the global scope, including trashed
     * rows when the model soft-deletes — their unique-index entries still block
     * re-inserts, and the global scope would hide them.
     *
     * The tenant predicate is a required argument rather than something each
     * caller remembers to add: dropping the global scope is what this helper is
     * for, so re-applying the restriction has to be part of the same call. A
     * new caller cannot omit it.
     *
     * @param  class-string<Model>  $modelClass
     * @return Builder<Model>
     */
    private function query(string $modelClass, int $tenantId): Builder
    {
        $query = (new $modelClass)->newQuery()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId);

        if ($this->softDeletes($modelClass)) {
            $query->withTrashed();
        }

        return $query;
    }

    /** @param class-string<Model> $modelClass */
    private function softDeletes(string $modelClass): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($modelClass), true);
    }

    /**
     * Accept both template shapes: a bare string, or an object. Returns the
     * attributes with the human-readable value normalized onto $nameKey.
     *
     * @return array<string, mixed>|null
     */
    private function normalizeNamed(mixed $entry, string $nameKey): ?array
    {
        if (is_string($entry)) {
            $name = trim($entry);

            return $name === '' ? null : [$nameKey => $name];
        }

        if (! is_array($entry)) {
            return null;
        }

        $name = $entry[$nameKey] ?? $entry['name'] ?? $entry['title'] ?? null;

        if (! is_string($name) || trim($name) === '') {
            return null;
        }

        $entry[$nameKey] = trim($name);

        return $entry;
    }

    /**
     * ISO days, 1 = Monday … 7 = Sunday, the scale `Shift::isWorkingDay` reads.
     * Two templates once wrote Sunday as 0, which no ISO weekday ever equals,
     * so their seven-day shifts never worked a Sunday; 0 is accepted here and
     * read as 7. Anything else outside 1–7 is refused.
     */
    private function normalizeWorkingDays(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = implode(',', array_map('strval', $value));
        }

        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $days = [];
        foreach (explode(',', (string) $value) as $part) {
            $part = trim($part);
            if ($part === '' || ! ctype_digit($part)) {
                return null;
            }

            $day = (int) $part === 0 ? 7 : (int) $part;
            if ($day > 7) {
                return null;
            }

            $days[$day] = $day;
        }

        ksort($days);

        return implode(',', $days);
    }

    private function normalizeTime(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $value, $m) !== 1) {
            return null;
        }

        $hour = (int) $m[1];
        $minute = (int) $m[2];

        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return sprintf('%02d:%02d:%02d', $hour, $minute, (int) ($m[3] ?? 0));
    }

    /**
     * Derive a stable short code from a name, then make it unique within the
     * tenant. Trashed rows count as taken — their unique index entries persist.
     *
     * @param  class-string<Model>  $modelClass
     */
    private function generateCode(string $name, string $modelClass, int $tenantId, int $maxLength): string
    {
        $base = Str::upper(Str::slug($name, ''));

        if ($base === '' || strlen($base) > $maxLength) {
            $words = preg_split('/[^A-Za-z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $initials = Str::upper(implode('', array_map(static fn (string $w): string => $w[0], $words)));
            $base = $initials !== '' ? $initials : 'X';
        }

        $base = substr($base, 0, $maxLength);
        $candidate = $base;
        $suffix = 2;

        while ($this->codeTaken($modelClass, $tenantId, $candidate)) {
            $tail = (string) $suffix;
            $candidate = substr($base, 0, max(1, $maxLength - strlen($tail))).$tail;
            $suffix++;

            if ($suffix > 999) {
                return substr(Str::upper(Str::random($maxLength)), 0, $maxLength);
            }
        }

        return $candidate;
    }

    /** @param class-string<Model> $modelClass */
    private function codeTaken(string $modelClass, int $tenantId, string $code): bool
    {
        return $this->query($modelClass, $tenantId)
            ->where('code', $code)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<int, mixed>
     */
    private function listOf(array $plan, string $key): array
    {
        $value = $plan[$key] ?? null;

        return is_array($value) ? array_values($value) : [];
    }
}
