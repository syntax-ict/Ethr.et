<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\PayrollEntry;
use App\Models\PayrollRule;
use App\Models\Shift;
use App\Models\TaxBracket;
use App\Services\Payroll\OvertimeCalculator;
use App\Services\Payroll\PayrollEngine;
use App\Services\Payroll\TaxCalculator;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function configUrl(string $subdomain, string $path): string
{
    return "http://{$subdomain}.ethr.test/api/v1/payroll/{$path}";
}

/**
 * Inserts a platform-wide (tenant_id = null) tax bracket. It has to bypass the
 * model because `BelongsToTenant` stamps the current tenant onto any row
 * created without one.
 */
function platformBracket(int $min, int $max, float $rate): void
{
    DB::table('tax_brackets')->insert([
        'public_id' => (string) Str::ulid(),
        'tenant_id' => null,
        'min_amount_cents' => $min,
        'max_amount_cents' => $max,
        'rate' => $rate,
        'deduction_cents' => 0,
        'effective_from' => '2020-01-01',
        'effective_to' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** A valid, contiguous two-band ladder used by the tax bracket tests. */
function ladder(): array
{
    return [
        ['min_amount_cents' => 0, 'max_amount_cents' => 100000, 'rate' => 0, 'deduction_cents' => 0],
        ['min_amount_cents' => 100001, 'max_amount_cents' => null, 'rate' => 50, 'deduction_cents' => 0],
    ];
}

// ──────────────────────── Allowance rules ────────────────────────

test('tenant admin can create a fixed allowance rule', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $response = test()->postJson(configUrl($tenant->subdomain, 'rules'), [
        'name' => 'Transport Allowance',
        'type' => 'fixed',
        'formula' => ['amount_cents' => 100000],
        'is_taxable' => false,
        'sort_order' => 2,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('name', 'Transport Allowance')
        ->assertJsonPath('type', 'fixed')
        ->assertJsonPath('category', 'allowance')
        ->assertJsonPath('formula.amount_cents', 100000)
        ->assertJsonPath('is_taxable', false)
        ->assertJsonMissingPath('id')
        ->assertJsonMissingPath('tenant_id');

    expect(PayrollRule::where('tenant_id', $tenant->id)->count())->toBe(1);
    expect(AuditLog::where('action', 'payroll_rule.created')->exists())->toBeTrue();
});

test('tenant admin can create a percentage allowance rule', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->postJson(configUrl($tenant->subdomain, 'rules'), [
        'name' => 'Housing Allowance',
        'type' => 'percentage',
        'formula' => ['percent' => 12.5],
    ])->assertStatus(201)
        ->assertJsonPath('formula.percent', 12.5);
});

test('a fixed allowance rule rejects a percent formula', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->postJson(configUrl($tenant->subdomain, 'rules'), [
        'name' => 'Broken',
        'type' => 'fixed',
        'formula' => ['percent' => 10],
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['formula.amount_cents', 'formula.percent']);
});

test('a percentage allowance rule rejects a percent above 100', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->postJson(configUrl($tenant->subdomain, 'rules'), [
        'name' => 'Too much',
        'type' => 'percentage',
        'formula' => ['percent' => 120],
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['formula.percent']);
});

test('finance admin can list allowance rules but not change them', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    PayrollRule::factory()->fixed(50000)->create(['tenant_id' => $tenant->id]);

    test()->getJson(configUrl($tenant->subdomain, 'rules'))
        ->assertOk()
        ->assertJsonCount(1, 'data');

    test()->postJson(configUrl($tenant->subdomain, 'rules'), [
        'name' => 'Nope',
        'type' => 'fixed',
        'formula' => ['amount_cents' => 1000],
    ])->assertForbidden();
});

test('employee cannot view payroll configuration', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    test()->getJson(configUrl($tenant->subdomain, 'rules'))->assertForbidden();
    test()->getJson(configUrl($tenant->subdomain, 'tax-brackets'))->assertForbidden();
    test()->getJson(configUrl($tenant->subdomain, 'overtime-rates'))->assertForbidden();
});

test('updating an allowance rule changes what payroll pays', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'salary_cents' => 500000,
        'hire_date' => '2020-01-01',
    ]);

    $rule = PayrollRule::factory()->fixed(100000)->nonTaxable()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Transport Allowance',
    ]);

    test()->putJson(configUrl($tenant->subdomain, "rules/{$rule->public_id}"), [
        'type' => 'fixed',
        'formula' => ['amount_cents' => 250000],
    ])->assertOk()
        ->assertJsonPath('formula.amount_cents', 250000);

    $run = app(PayrollEngine::class)->process(
        $tenant->id,
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-06-30'),
        $user->id,
    )->run;

    $entry = PayrollEntry::where('payroll_run_id', $run->id)->firstOrFail();

    expect($entry->gross_cents)->toBe(750000); // 500,000 basic + 250,000 allowance
    expect(AuditLog::where('action', 'payroll_rule.updated')->exists())->toBeTrue();
});

test('deleting an allowance rule removes it from payroll', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $rule = PayrollRule::factory()->fixed(100000)->create(['tenant_id' => $tenant->id]);

    test()->deleteJson(configUrl($tenant->subdomain, "rules/{$rule->public_id}"))
        ->assertNoContent();

    expect(PayrollRule::where('tenant_id', $tenant->id)->count())->toBe(0);
    expect(AuditLog::where('action', 'payroll_rule.deleted')->exists())->toBeTrue();
});

test('allowance rules are isolated between tenants', function () {
    $other = createTenant();
    $otherRule = PayrollRule::factory()->fixed(999999)->create(['tenant_id' => $other->id]);

    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->getJson(configUrl($tenant->subdomain, 'rules'))
        ->assertOk()
        ->assertJsonCount(0, 'data');

    test()->getJson(configUrl($tenant->subdomain, "rules/{$otherRule->public_id}"))
        ->assertNotFound();

    test()->putJson(configUrl($tenant->subdomain, "rules/{$otherRule->public_id}"), [
        'type' => 'fixed',
        'formula' => ['amount_cents' => 1],
    ])->assertNotFound();
});

test('the overtime rate rule is not listed as an allowance', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    PayrollRule::factory()->create([
        'tenant_id' => $tenant->id,
        'category' => 'overtime',
        'type' => 'rate',
        'formula' => OvertimeCalculator::DEFAULT_RATES,
    ]);

    test()->getJson(configUrl($tenant->subdomain, 'rules'))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

// ──────────────────────── Tax brackets ────────────────────────

test('tax brackets fall back to the platform ladder when the tenant has none', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    platformBracket(0, 0, 5);

    test()->getJson(configUrl($tenant->subdomain, 'tax-brackets'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.rate', 5)
        ->assertJsonPath('data.0.max_amount_cents', null);
});

test('replacing the tax ladder changes the tax payroll withholds', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'salary_cents' => 500000,
        'hire_date' => '2020-01-01',
    ]);

    test()->putJson(configUrl($tenant->subdomain, 'tax-brackets'), [
        'effective_from' => '2026-01-01',
        'brackets' => ladder(),
    ])->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.1.max_amount_cents', null);

    $run = app(PayrollEngine::class)->process(
        $tenant->id,
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-06-30'),
        $user->id,
    )->run;

    $entry = PayrollEntry::where('payroll_run_id', $run->id)->firstOrFail();

    // 500,000 falls in the top band: 50% of 500,000, no deduction.
    expect($entry->income_tax_cents)->toBe(250000);
    expect(AuditLog::where('action', 'tax_brackets.replaced')->exists())->toBeTrue();
});

test('a tenant ladder fully replaces the platform ladder rather than merging', function () {
    $tenant = createTenant();

    platformBracket(0, 0, 90);

    TaxBracket::create([
        'tenant_id' => $tenant->id,
        'min_amount_cents' => 0,
        'max_amount_cents' => 0,
        'rate' => 10,
        'deduction_cents' => 0,
        'effective_from' => '2020-01-01',
    ]);

    // The tenant's 10% band wins outright — the platform 90% band is ignored.
    expect((new TaxCalculator)->calculate(100000, $tenant->id))->toBe(10000);
});

test('a tax ladder that does not start at zero is rejected', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $brackets = ladder();
    $brackets[0]['min_amount_cents'] = 100;

    test()->putJson(configUrl($tenant->subdomain, 'tax-brackets'), [
        'effective_from' => '2026-01-01',
        'brackets' => $brackets,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['brackets.0.min_amount_cents']);
});

test('a tax ladder with a gap between bands is rejected', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $brackets = ladder();
    $brackets[1]['min_amount_cents'] = 200000; // should be 100001

    test()->putJson(configUrl($tenant->subdomain, 'tax-brackets'), [
        'effective_from' => '2026-01-01',
        'brackets' => $brackets,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['brackets.0.max_amount_cents']);
});

test('only the final tax bracket may be open-ended', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $brackets = ladder();
    $brackets[0]['max_amount_cents'] = null;

    test()->putJson(configUrl($tenant->subdomain, 'tax-brackets'), [
        'effective_from' => '2026-01-01',
        'brackets' => $brackets,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['brackets.0.max_amount_cents']);
});

test('a tenant tax ladder does not leak to another tenant', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->putJson(configUrl($tenant->subdomain, 'tax-brackets'), [
        'effective_from' => '2026-01-01',
        'brackets' => ladder(),
    ])->assertOk();

    $other = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $other);

    test()->getJson(configUrl($other->subdomain, 'tax-brackets'))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('finance admin cannot replace the tax ladder', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    test()->putJson(configUrl($tenant->subdomain, 'tax-brackets'), [
        'effective_from' => '2026-01-01',
        'brackets' => ladder(),
    ])->assertForbidden();
});

// ──────────────────────── Overtime rates ────────────────────────

test('overtime rates report the proclamation defaults until customized', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    test()->getJson(configUrl($tenant->subdomain, 'overtime-rates'))
        ->assertOk()
        ->assertJsonPath('rates.normal', 1.25)
        ->assertJsonPath('rates.holiday_night', 2.5)
        ->assertJsonPath('is_customized', false);
});

test('updated overtime rates are applied by payroll', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->putJson(configUrl($tenant->subdomain, 'overtime-rates'), [
        'normal' => 2,
        'night' => 2.5,
        'holiday' => 3,
        'holiday_night' => 3.5,
    ])->assertOk()
        // JSON has no float/int distinction — 2.0 comes back as 2.
        ->assertJsonPath('rates.normal', 2)
        ->assertJsonPath('rates.night', 2.5)
        ->assertJsonPath('is_customized', true);

    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'salary_cents' => 500000,
        'hire_date' => '2020-01-01',
    ]);

    $shift = Shift::factory()->create(['tenant_id' => $tenant->id]); // 08:30–17:30

    AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'shift_id' => $shift->id,
        'date' => '2026-06-15',
        'check_in' => Carbon::parse('2026-06-15 08:30'),
        'check_out' => Carbon::parse('2026-06-15 19:30'), // 120 min OT
    ]);

    $run = app(PayrollEngine::class)->process(
        $tenant->id,
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-06-30'),
        $user->id,
    )->run;

    $entry = PayrollEntry::where('payroll_run_id', $run->id)->firstOrFail();
    $step = collect($entry->calculation_log['steps'])->firstWhere('step', 'overtime');

    // hourly = 500,000 / (22 * 8) = 2840.909…; 2h at 2.0x = 11,363.64 → 11,364
    $expected = (int) round(500000 / (22 * 8) * 2 * 2.0);

    expect($step['by_type']['normal']['amount_cents'])->toBe($expected);
    expect((float) $step['by_type']['normal']['rate'])->toBe(2.0);
    expect(AuditLog::where('action', 'overtime_rates.updated')->exists())->toBeTrue();
});

test('overtime rates below the statutory minimum are rejected', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->putJson(configUrl($tenant->subdomain, 'overtime-rates'), [
        'normal' => 1,
        'night' => 1.2,
        'holiday' => 1.5,
        'holiday_night' => 2,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['normal', 'night', 'holiday', 'holiday_night']);
});

test('overtime rates are isolated between tenants', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->putJson(configUrl($tenant->subdomain, 'overtime-rates'), [
        'normal' => 2,
        'night' => 2.5,
        'holiday' => 3,
        'holiday_night' => 3.5,
    ])->assertOk();

    $other = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $other);

    test()->getJson(configUrl($other->subdomain, 'overtime-rates'))
        ->assertOk()
        ->assertJsonPath('rates.normal', 1.25)
        ->assertJsonPath('is_customized', false);
});

test('finance admin cannot change overtime rates', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    test()->putJson(configUrl($tenant->subdomain, 'overtime-rates'), [
        'normal' => 2,
        'night' => 2.5,
        'holiday' => 3,
        'holiday_night' => 3.5,
    ])->assertForbidden();
});

// ──────────────────────── Loan lifecycle ────────────────────────

test('finance admin can adjust the monthly instalment on an active loan', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $loan = EmployeeLoan::create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'amount_cents' => 1000000,
        'remaining_cents' => 1000000,
        'monthly_deduction_cents' => 100000,
        'start_date' => now(),
        'status' => 'active',
    ]);

    test()->putJson(configUrl($tenant->subdomain, "loans/{$loan->public_id}"), [
        'monthly_deduction_cents' => 50000,
    ])->assertOk()
        ->assertJsonPath('monthly_deduction_cents', 50000);

    expect(AuditLog::where('action', 'loan.updated')->exists())->toBeTrue();
});

test('cancelling a loan stops it being deducted', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'salary_cents' => 500000,
        'hire_date' => '2020-01-01',
    ]);

    $loan = EmployeeLoan::create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'amount_cents' => 1000000,
        'remaining_cents' => 1000000,
        'monthly_deduction_cents' => 100000,
        'start_date' => '2026-01-01',
        'status' => 'active',
    ]);

    test()->putJson(configUrl($tenant->subdomain, "loans/{$loan->public_id}/cancel"), [
        'reason' => 'Settled off payroll',
    ])->assertOk()
        ->assertJsonPath('status', 'cancelled');

    $run = app(PayrollEngine::class)->process(
        $tenant->id,
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-06-30'),
        $user->id,
    )->run;

    $entry = PayrollEntry::where('payroll_run_id', $run->id)->firstOrFail();

    expect($entry->other_deductions_cents)->toBe(0);
    expect($loan->fresh()->remaining_cents)->toBe(1000000);
    expect(AuditLog::where('action', 'loan.cancelled')->exists())->toBeTrue();
});

test('a cancelled loan can no longer be modified', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $loan = EmployeeLoan::create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'amount_cents' => 1000000,
        'remaining_cents' => 1000000,
        'monthly_deduction_cents' => 100000,
        'start_date' => now(),
        'status' => 'cancelled',
    ]);

    test()->putJson(configUrl($tenant->subdomain, "loans/{$loan->public_id}"), [
        'monthly_deduction_cents' => 50000,
    ])->assertStatus(422);

    test()->putJson(configUrl($tenant->subdomain, "loans/{$loan->public_id}/cancel"), [
        'reason' => 'Again',
    ])->assertStatus(422);
});
