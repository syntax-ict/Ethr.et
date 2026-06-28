<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;

it('validates employee status transitions', function () {
    expect(EmployeeStatus::HIRED->canTransitionTo(EmployeeStatus::PROBATION))->toBeTrue();
    expect(EmployeeStatus::HIRED->canTransitionTo(EmployeeStatus::CONFIRMED))->toBeTrue();
    expect(EmployeeStatus::HIRED->canTransitionTo(EmployeeStatus::RETIRED))->toBeFalse();
    expect(EmployeeStatus::RETIRED->canTransitionTo(EmployeeStatus::CONFIRMED))->toBeFalse();
});

it('validates user role hierarchy', function () {
    expect(UserRole::SUPER_ADMIN->isAtLeast(UserRole::EMPLOYEE))->toBeTrue();
    expect(UserRole::EMPLOYEE->isAtLeast(UserRole::SUPER_ADMIN))->toBeFalse();
    expect(UserRole::HR_ADMIN->isAtLeast(UserRole::SUPERVISOR))->toBeTrue();
    expect(UserRole::HR_ADMIN->level())->toBe(UserRole::FINANCE_ADMIN->level());
});
