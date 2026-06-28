<?php

declare(strict_types=1);

namespace App\Services\Payroll;

final class PensionCalculator
{
    public function __construct(
        private readonly float $employeeRate = 7.0,
        private readonly float $employerRate = 11.0,
    ) {}

    public function calculate(int $basicSalaryCents): array
    {
        return [
            'employee_cents' => (int) round($basicSalaryCents * $this->employeeRate / 100),
            'employer_cents' => (int) round($basicSalaryCents * $this->employerRate / 100),
        ];
    }
}
