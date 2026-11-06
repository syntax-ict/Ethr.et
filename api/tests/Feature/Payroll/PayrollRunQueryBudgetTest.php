<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\PayrollRule;
use App\Models\Shift;
use App\Services\CurrentTenant;
use App\Services\Payroll\PayrollEngine;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

// A-Z review I (2026-11-04). A run of 500 employees took 37 s on the
// production-shaped stack, past the 30 s a shared-hosting request may run, and
// a third of its 9,693 queries asked again, for every employee, what is the
// same for the whole organisation: the organisation itself, its default shift
// and rotation, its holidays and its allowance rules. They are read once per
// run now, and this pins it: those reads do not grow with the headcount.

/** The reads of organisation-wide facts a run makes for `$people` employees. */
function organisationReads(int $people): int
{
    $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);
    app(CurrentTenant::class)->set($tenant);
    $admin = createUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    Shift::factory()->default()->create(['tenant_id' => $tenant->id]);
    PayrollRule::query()->create([
        'tenant_id' => $tenant->id, 'name' => 'Transport', 'type' => 'fixed', 'category' => 'allowance',
        'formula' => ['amount_cents' => 60000], 'is_taxable' => false, 'is_active' => true, 'sort_order' => 10,
    ]);
    Employee::factory()->count($people)->create(['tenant_id' => $tenant->id, 'salary_cents' => 1_000_000, 'hire_date' => '2024-01-01']);

    $reads = 0;
    DB::listen(function ($query) use (&$reads): void {
        // The table the statement reads from, not one named in a subquery: a
        // person's own shift assignments are read per person, rightly.
        if (preg_match('/^select [^(]*? from [`"](tenants|shift_rotations|holidays|payroll_rules|shifts)[`"] where [`"](id|tenant_id|tenants)/', $query->sql) === 1) {
            $reads++;
        }
    });

    app(PayrollEngine::class)->process($tenant->id, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'), $admin->id);
    DB::flushQueryLog();

    return $reads;
}

it('reads the organisation-wide facts of a run once, however many people it pays', function () {
    $few = organisationReads(2);
    app()->forgetScopedInstances();
    $many = organisationReads(8);

    expect($many)->toBe($few);
});
