<?php

declare(strict_types=1);

namespace App\Support;

/**
 * What EmployeeImportGrid made of a list of people: who is ready to be
 * staged, and every line that is not, with why.
 */
final readonly class PeopleRead
{
    /**
     * @param  list<array<string, mixed>>  $rows  the people ready to stage, as a staged list keeps them
     * @param  list<array{line: int, message: string}>  $problems  lines that cannot be staged; `line` counts as the reader sees it, the header included
     * @param  bool  $header  whether the first line was column names
     * @param  list<string>  $columns  the columns read, in the order they were found
     * @param  list<string>  $ignored  column names that mean nothing here, as written
     * @param  'gregorian'|'ethiopian'  $calendar  the calendar hire dates were read in
     * @param  bool  $calendarFromHeader  whether the column name said so, over the choice
     * @param  int  $withoutHireDate  people ready who bring no hire date
     */
    public function __construct(
        public array $rows,
        public array $problems,
        public bool $header,
        public array $columns,
        public array $ignored,
        public string $calendar,
        public bool $calendarFromHeader,
        public int $withoutHireDate,
    ) {}
}
