<?php

declare(strict_types=1);

namespace App\Services\Migration;

use App\Models\Employee;

/**
 * What undoing a list would do, for one person asking (ListUndo::preview).
 */
final readonly class UndoPlan
{
    /**
     * @param  list<Employee>  $archive  by name
     * @param  list<array{employee: Employee, reasons: list<string>}>  $keep  by name
     * @param  int  $alreadyArchived  made by the list and already deleted
     * @param  int  $outOfReach  the person asking may not archive them: counted, never named
     */
    public function __construct(
        public Additions $additions,
        public array $archive,
        public array $keep,
        public int $alreadyArchived,
        public int $outOfReach,
    ) {}

    /** @return list<int> */
    public function archiveIds(): array
    {
        return array_map(fn (Employee $employee): int => $employee->id, $this->archive);
    }
}
