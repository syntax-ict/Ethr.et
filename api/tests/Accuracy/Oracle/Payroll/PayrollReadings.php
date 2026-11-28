<?php

declare(strict_types=1);

namespace Tests\Accuracy\Oracle\Payroll;

/** The alternative readings of the register's Waiting-for-a-decision list. */
final class PayrollReadings
{
    /** @param array<string, mixed> $flags */
    public function __construct(private readonly array $flags = []) {}

    /** @return list<array{0: string, 1: self}> */
    public static function alternatives(): array
    {
        return [
            ['PYR-PAG-06', new self(['pagume_only_period_pays_zero' => true])],
            ['PYR-PAG-05', new self(['pagume_after_proration' => true])],
            ['PYR-LATE-06', new self(['ceiling_base' => 'prorated_basic'])],
            ['PYR-LATE-06', new self(['ceiling_base' => 'monthly_basic'])],
            ['PYR-ROUND-04', new self(['round_deductions_once' => true])],
            ['PYR-ROUND-05', new self(['lateness_rounds_down' => true])],
            ['PYR-ALW-07', new self(['fixed_allowance_follows_pagume' => true])],
            ['PYR-PEN-11', new self(['p9_kind_exempt' => true])],
            ['PYR-PEN-14', new self(['domestic_worker_exempt' => true])],
        ];
    }

    public function flag(string $name): mixed
    {
        return $this->flags[$name] ?? false;
    }
}
