<?php

declare(strict_types=1);

use App\Enums\CostSharingStatus;
use App\Models\Employee;
use App\Models\EmployeeCostSharing;
use App\Services\Payroll\CostSharingService;

/**
 * Ethiopian higher-education cost sharing — the deduction arithmetic and the
 * balance lifecycle. The payroll-engine integration is covered separately in
 * PayrollCostSharingTest.
 */
beforeEach(function () {
    $this->service = app(CostSharingService::class);
});

function costSharingFor(array $attributes = []): EmployeeCostSharing
{
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    return EmployeeCostSharing::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        ...$attributes,
    ]);
}

describe('deduction arithmetic', function () {
    it('withholds the configured percentage of gross', function () {
        $obligation = costSharingFor([
            'deduction_rate_percent' => 10,
            'outstanding_cents' => 50000_00,
        ]);

        // 10% of 8,000.00 ETB = 800.00 ETB
        expect($this->service->calculateDeduction($obligation, 8000_00))->toBe(800_00);
    });

    it('honours a fractional rate without floating-point drift', function () {
        $obligation = costSharingFor([
            'deduction_rate_percent' => 7.5,
            'outstanding_cents' => 50000_00,
        ]);

        // 7.5% of 3,333.33 ETB = 249.99975 → floors to 249.99
        expect($this->service->calculateDeduction($obligation, 3333_33))->toBe(249_99);
    });

    it('scales with prorated gross, so a part month withholds proportionally', function () {
        $obligation = costSharingFor([
            'deduction_rate_percent' => 10,
            'outstanding_cents' => 50000_00,
        ]);

        $full = $this->service->calculateDeduction($obligation, 8000_00);
        $half = $this->service->calculateDeduction($obligation, 4000_00);

        expect($half)->toBe((int) ($full / 2));
    });

    it('never withholds more than the outstanding balance', function () {
        // The final month: 10% of gross would be 800.00 but only 120.50 is owed.
        $obligation = costSharingFor([
            'deduction_rate_percent' => 10,
            'outstanding_cents' => 120_50,
        ]);

        expect($this->service->calculateDeduction($obligation, 8000_00))->toBe(120_50);
    });

    it('returns zero for a zero or negative gross', function () {
        $obligation = costSharingFor(['outstanding_cents' => 50000_00]);

        expect($this->service->calculateDeduction($obligation, 0))->toBe(0);
        expect($this->service->calculateDeduction($obligation, -100))->toBe(0);
    });

    it('returns zero when there is no obligation at all', function () {
        expect($this->service->calculateDeduction(null, 8000_00))->toBe(0);
    });

    it('does not withhold against a suspended obligation', function () {
        $obligation = costSharingFor([
            'status' => CostSharingStatus::SUSPENDED,
            'outstanding_cents' => 50000_00,
        ]);

        expect($this->service->calculateDeduction($obligation, 8000_00))->toBe(0);
    });

    it('does not withhold against an already-completed obligation', function () {
        $obligation = costSharingFor([
            'status' => CostSharingStatus::COMPLETED,
            'outstanding_cents' => 0,
        ]);

        expect($this->service->calculateDeduction($obligation, 8000_00))->toBe(0);
    });
});

describe('balance lifecycle', function () {
    it('reduces the outstanding balance by the amount withheld', function () {
        $obligation = costSharingFor(['outstanding_cents' => 50000_00]);

        $this->service->applyDeduction($obligation, 800_00);

        expect($obligation->fresh()->outstanding_cents)->toBe(49200_00);
        expect($obligation->fresh()->status)->toBe(CostSharingStatus::ACTIVE);
    });

    it('completes the obligation the moment the balance reaches zero', function () {
        $obligation = costSharingFor(['outstanding_cents' => 800_00]);

        $this->service->applyDeduction($obligation, 800_00);

        $fresh = $obligation->fresh();
        expect($fresh->outstanding_cents)->toBe(0);
        expect($fresh->status)->toBe(CostSharingStatus::COMPLETED);
        expect($fresh->completed_at)->not->toBeNull();
    });

    it('never drives the balance negative', function () {
        $obligation = costSharingFor(['outstanding_cents' => 100_00]);

        // Defensive: calculateDeduction already clamps, but applyDeduction must
        // not rely on its caller having done so.
        $this->service->applyDeduction($obligation, 999_00);

        expect($obligation->fresh()->outstanding_cents)->toBe(0);
    });

    it('ignores a zero deduction rather than touching the row', function () {
        $obligation = costSharingFor(['outstanding_cents' => 50000_00]);

        $this->service->applyDeduction($obligation, 0);

        expect($obligation->fresh()->outstanding_cents)->toBe(50000_00);
        expect($obligation->fresh()->completed_at)->toBeNull();
    });

    it('repays exactly to zero over the full schedule, never over-withholding', function () {
        // 1,000.00 owed at 10% of a 300.00 gross = 30.00/month. The last
        // instalment must be the remainder, not another full 30.00.
        $obligation = costSharingFor([
            'deduction_rate_percent' => 10,
            'outstanding_cents' => 1000_00,
        ]);

        $withheld = 0;
        for ($month = 0; $month < 100; $month++) {
            $obligation = $obligation->fresh();
            $amount = $this->service->calculateDeduction($obligation, 300_00);

            if ($amount === 0) {
                break;
            }

            $this->service->applyDeduction($obligation, $amount);
            $withheld += $amount;
        }

        expect($withheld)->toBe(1000_00);
        expect($obligation->fresh()->status)->toBe(CostSharingStatus::COMPLETED);
    });
});

describe('obligation selection', function () {
    it('ignores another employee\'s obligation', function () {
        $tenant = createTenant();

        $mine = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $theirs = Employee::factory()->create(['tenant_id' => $tenant->id]);

        EmployeeCostSharing::factory()->create([
            'tenant_id' => $tenant->id,
            'employee_id' => $theirs->id,
        ]);

        expect($this->service->getActiveObligation($mine))->toBeNull();
    });

    it('picks the earliest when a data-entry error leaves two active rows', function () {
        $tenant = createTenant();
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $older = EmployeeCostSharing::factory()->create([
            'tenant_id' => $tenant->id,
            'employee_id' => $employee->id,
            'started_on' => '2024-01-01',
        ]);
        EmployeeCostSharing::factory()->create([
            'tenant_id' => $tenant->id,
            'employee_id' => $employee->id,
            'started_on' => '2025-01-01',
        ]);

        // Summing them would silently withhold twice; taking the earliest keeps
        // the behaviour defined so the duplicate can be found and corrected.
        expect($this->service->getActiveObligation($employee)->id)->toBe($older->id);
    });
});

describe('status transitions', function () {
    it('allows an active obligation to be suspended and resumed', function () {
        expect(CostSharingStatus::ACTIVE->canTransitionTo(CostSharingStatus::SUSPENDED))->toBeTrue();
        expect(CostSharingStatus::SUSPENDED->canTransitionTo(CostSharingStatus::ACTIVE))->toBeTrue();
    });

    it('treats completed and cancelled as terminal', function () {
        expect(CostSharingStatus::COMPLETED->allowedTransitions())->toBe([]);
        expect(CostSharingStatus::CANCELLED->allowedTransitions())->toBe([]);
    });

    it('only deducts against an active obligation', function () {
        expect(CostSharingStatus::ACTIVE->isDeductible())->toBeTrue();
        expect(CostSharingStatus::SUSPENDED->isDeductible())->toBeFalse();
        expect(CostSharingStatus::COMPLETED->isDeductible())->toBeFalse();
        expect(CostSharingStatus::CANCELLED->isDeductible())->toBeFalse();
    });
});
