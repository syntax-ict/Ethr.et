<?php

declare(strict_types=1);

namespace App\Services\Migration;

/**
 * Where a list stands after one slice of matching its people.
 */
final readonly class StageProgress
{
    public function __construct(
        public bool $done,
        public int $staged,
        public int $remaining,
    ) {}
}
