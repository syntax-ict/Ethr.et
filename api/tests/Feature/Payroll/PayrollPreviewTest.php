<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Services\Payroll\PayrollEngine;
use Illuminate\Support\Carbon;

/**
 * POST /payroll/preview (2026-11-09): a dry run of a period by the same engine
 * a run uses. It writes no run and no payslip, its figures are the ones the
 * run then writes, it pages under the request budget, and it says which rules
 * and published batch of the law each figure was worked out under.
 */
function previewUrl(object $tenant, string $path = 'preview'): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/payroll/{$path}";
}

it('works out every payslip and writes nothing', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    Employee::factory()->count(3)->create(['tenant_id' => $tenant->id, 'salary_cents' => 1_200_000]);

    $response = test()->postJson(previewUrl($tenant), [
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-30',
    ]);

    $response->assertOk()
        ->assertJsonCount(3, 'payslips')
        ->assertJsonPath('next_after', null)
        ->assertJsonPath('period_start', '2026-06-01');

    expect(PayrollRun::query()->where('tenant_id', $tenant->id)->count())->toBe(0)
        ->and(PayrollEntry::query()->where('tenant_id', $tenant->id)->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'payroll.previewed')->count())->toBe(1);
});

it('gives the figures the run then writes', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 800_000]);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 2_500_000]);

    $preview = test()->postJson(previewUrl($tenant), [
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-30',
    ])->assertOk()->json('payslips');

    test()->postJson(previewUrl($tenant, 'process'), [
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-30',
        'idempotency_key' => 'preview-then-run',
    ])->assertSuccessful();

    $written = PayrollEntry::query()
        ->where('tenant_id', $tenant->id)
        ->with('employee:id,public_id')
        ->get()
        ->mapWithKeys(fn (PayrollEntry $entry) => [$entry->employee->public_id => $entry]);

    expect($written)->toHaveCount(2);
    foreach ($preview as $payslip) {
        $entry = $written[$payslip['employee']['public_id']];
        expect($payslip['gross_cents'])->toBe((int) $entry->gross_cents)
            ->and($payslip['income_tax_cents'])->toBe((int) $entry->income_tax_cents)
            ->and($payslip['employee_pension_cents'])->toBe((int) $entry->employee_pension_cents)
            ->and($payslip['employer_pension_cents'])->toBe((int) $entry->employer_pension_cents)
            ->and($payslip['net_cents'])->toBe((int) $entry->net_cents);
    }
});

it('says which rules, law and published batch it applied', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 1_000_000]);

    $response = test()->postJson(previewUrl($tenant), [
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-30',
    ])->assertOk();

    $rules = collect($response->json('rules'))->keyBy('code');
    expect($rules['payroll.hours_per_day']['value'])->toBe(8)
        ->and($rules['payroll.hours_per_day']['version'])->toBe('2026.11.1')
        ->and($rules['payroll.hours_per_day']['legal_reference'])->toContain('1156/2019')
        ->and($response->json('pension.employee_rate'))->not->toBeNull()
        ->and($response->json('income_tax.source'))->not->toBe('');
});

it('pages through the people, picking up after the last one', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    Employee::factory()->count(3)->create(['tenant_id' => $tenant->id, 'salary_cents' => 900_000]);

    $engine = app(PayrollEngine::class);
    $first = $engine->preview($tenant->id, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'), null, null, 2);
    expect($first['entries'])->toHaveCount(2)
        ->and($first['next_after'])->toBe($first['entries'][1]['employee']->id);

    $after = Employee::query()->whereKey($first['next_after'])->value('public_id');
    test()->postJson(previewUrl($tenant), [
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-30',
        'after' => $after,
    ])->assertOk()
        ->assertJsonCount(1, 'payslips')
        ->assertJsonPath('next_after', null);
});

it('stops at the request deadline and hands back where to resume', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    Employee::factory()->count(2)->create(['tenant_id' => $tenant->id, 'salary_cents' => 900_000]);

    $preview = app(PayrollEngine::class)->preview($tenant->id, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'), null, microtime(true) - 1);

    expect($preview['entries'])->toHaveCount(1)
        ->and($preview['next_after'])->toBe($preview['entries'][0]['employee']->id);
});

it('is refused to someone who may not process payroll', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

    test()->postJson(previewUrl($tenant), [
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-30',
    ])->assertForbidden();
});

it('does not resume after another organisation\'s employee', function () {
    $tenant = createTenant();
    $other = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $theirs = Employee::factory()->create(['tenant_id' => $other->id]);

    test()->postJson(previewUrl($tenant), [
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-30',
        'after' => $theirs->public_id,
    ])->assertNotFound();
});

it('asks for a period that ends after it starts', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    test()->postJson(previewUrl($tenant), [
        'period_start' => '2026-06-30',
        'period_end' => '2026-06-01',
    ])->assertUnprocessable()->assertJsonValidationErrors(['period_end']);
});
