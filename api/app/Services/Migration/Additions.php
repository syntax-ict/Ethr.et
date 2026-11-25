<?php

declare(strict_types=1);

namespace App\Services\Migration;

/**
 * Who a committed list made (ListAdditions).
 */
final readonly class Additions
{
    /** Every create row of the list says what it did. */
    public const RECORDED = 'recorded';

    /** At least one create row was committed before the list recorded what it did. */
    public const DERIVED = 'derived';

    /**
     * @param  list<int>  $created  people established as made by the list, whether or not they are still here
     * @param  list<int>  $unsure  people who may have been made by it or only linked, and cannot be told apart
     * @param  int  $matched  people the list pointed at that it did not make
     * @param  int|null  $declared  what the list's own totals say it created
     * @param  list<int>  $restored  archived people the list brought back, whose rows were committed
     */
    public function __construct(
        public array $created,
        public array $unsure,
        public int $matched,
        public string $origin,
        public ?int $declared,
        public array $restored = [],
    ) {}

    /** False only when some people cannot be told apart. */
    public function exact(): bool
    {
        return $this->unsure === [];
    }

    /** The number the screen reports as added. */
    public function added(): int
    {
        return $this->exact() ? count($this->created) : (int) $this->declared;
    }
}
