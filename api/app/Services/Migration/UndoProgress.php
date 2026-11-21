<?php

declare(strict_types=1);

namespace App\Services\Migration;

/**
 * How far an undo has got after one request (ListUndo::undoSlice).
 */
final readonly class UndoProgress
{
    /**
     * @param  int  $archived  archived since the last audit entry, or the count just audited when done
     * @param  int  $remaining  people still to be visited
     */
    public function __construct(
        public bool $done,
        public int $archived,
        public int $remaining,
        public int $kept,
        public int $outOfReach,
    ) {}
}
