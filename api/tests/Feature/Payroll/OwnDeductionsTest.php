<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\EmployeeCostSharing;
use App\Models\EmployeeLoan;
use App\Models\Tenant;

/*
 * K-47 (owner decision 2026-11-08). A loan or cost-sharing instalment is pay:
 * lowering your own raises your own net pay. #490 refused your own salary,
 * TIN, bank details, contracts and personnel actions below the organisation
 * admin, and the deduction endpoints checked the permission only.
 */

function deductionsUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/payroll/{$path}";
}

/** @return array{0: Tenant, 1: Employee} */
function financeOnOwnRecord(UserRole $role = UserRole::FINANCE_ADMIN): array
{
    $tenant = createTenant();
    $self = Employee::factory()->create(['tenant_id' => $tenant->id]);
    actingAsUser(['role' => $role, 'employee_id' => $self->id, 'mfa_enabled' => true], $tenant);

    return [$tenant, $self];
}

describe('a finance admin and their own deductions', function () {
    it('cannot give themself a loan', function () {
        [$tenant, $self] = financeOnOwnRecord();

        test()->postJson(deductionsUrl($tenant, 'loans'), [
            'employee_public_id' => $self->public_id,
            'amount_cents' => 100_000,
            'monthly_deduction_cents' => 10_000,
        ])->assertForbidden()
            ->assertJsonPath('detail', __('employee.cannot_change_own_deductions'));

        expect(EmployeeLoan::withoutGlobalScopes()->where('employee_id', $self->id)->exists())->toBeFalse();
    });

    it('cannot lower or cancel their own loan', function () {
        [$tenant, $self] = financeOnOwnRecord();
        $loan = EmployeeLoan::factory()->create([
            'tenant_id' => $tenant->id, 'employee_id' => $self->id, 'monthly_deduction_cents' => 10_000,
        ]);

        test()->putJson(deductionsUrl($tenant, "loans/{$loan->public_id}"), ['monthly_deduction_cents' => 100])
            ->assertForbidden();
        test()->putJson(deductionsUrl($tenant, "loans/{$loan->public_id}/cancel"), ['reason' => 'Mine'])
            ->assertForbidden();

        $loan->refresh();
        expect($loan->monthly_deduction_cents)->toBe(10_000)
            ->and($loan->status)->toBe('active');
    });

    it('cannot record or change their own cost-sharing obligation', function () {
        [$tenant, $self] = financeOnOwnRecord();

        test()->postJson(deductionsUrl($tenant, 'cost-sharing'), [
            'employee_public_id' => $self->public_id,
            'total_obligation_cents' => 100_000,
            'deduction_rate_percent' => 1,
            'started_on' => '2026-01-01',
        ])->assertForbidden();

        $obligation = EmployeeCostSharing::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $self->id]);
        $rate = (float) $obligation->deduction_rate_percent;

        test()->putJson(deductionsUrl($tenant, "cost-sharing/{$obligation->public_id}"), ['deduction_rate_percent' => 0.5])
            ->assertForbidden();
        test()->putJson(deductionsUrl($tenant, "cost-sharing/{$obligation->public_id}"), ['status' => 'cancelled'])
            ->assertForbidden();

        expect((float) $obligation->fresh()->deduction_rate_percent)->toBe($rate);
    });

    it('still manages everyone else\'s deductions', function () {
        [$tenant] = financeOnOwnRecord();
        $colleague = Employee::factory()->create(['tenant_id' => $tenant->id]);

        test()->postJson(deductionsUrl($tenant, 'loans'), [
            'employee_public_id' => $colleague->public_id,
            'amount_cents' => 100_000,
            'monthly_deduction_cents' => 10_000,
        ])->assertCreated();

        $loan = EmployeeLoan::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $colleague->id]);
        test()->putJson(deductionsUrl($tenant, "loans/{$loan->public_id}"), ['monthly_deduction_cents' => 5_000])
            ->assertOk();
    });

    it('leaves the organisation admin to manage their own', function () {
        [$tenant, $self] = financeOnOwnRecord(UserRole::TENANT_ADMIN);

        test()->postJson(deductionsUrl($tenant, 'loans'), [
            'employee_public_id' => $self->public_id,
            'amount_cents' => 100_000,
            'monthly_deduction_cents' => 10_000,
        ])->assertCreated();
    });
});
