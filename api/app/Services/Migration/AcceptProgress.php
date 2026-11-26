<?php

declare(strict_types=1);

namespace App\Services\Migration;

/**
 * Where accepting differences for many rows stands after one slice
 * (WorkforceMigrationService::acceptDifferences). Every count is this slice's
 * alone: the caller adds them up across the requests it sends.
 */
final readonly class AcceptProgress
{
    /**
     * @param  int  $cursor  the last row looked at; sent back to carry on after it
     * @param  int  $rows  rows that had a difference accepted in this slice
     * @param  array<string, int>  $byField  field => rows that had it accepted
     * @param  int  $outOfReach  rows left alone because the actor may not edit the person
     * @param  int  $payRefused  rows that lost TIN or salary because the actor may not edit pay
     * @param  int  $held  rows left for the reviewer because they may be someone else
     */
    public function __construct(
        public bool $done,
        public int $cursor,
        public int $rows,
        public array $byField,
        public int $outOfReach,
        public int $payRefused,
        public int $held,
    ) {}
}
