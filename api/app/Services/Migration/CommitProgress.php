<?php

declare(strict_types=1);

namespace App\Services\Migration;

/**
 * Where a staged list stands after one slice of its commit.
 */
final readonly class CommitProgress
{
    /**
     * @param  array<string, int>  $totals  the whole list so far, not this slice alone
     */
    public function __construct(
        public bool $done,
        public array $totals,
        public int $remaining,
    ) {}
}
