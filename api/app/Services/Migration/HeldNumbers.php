<?php

declare(strict_types=1);

namespace App\Services\Migration;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which employee numbers and badge numbers some employee record of the
 * organisation already holds, archived records included.
 *
 * `(tenant_id, employee_code)` is unique over every row the table keeps, so an
 * archived person still holds their number, and a new person cannot be given
 * it. The Add Employee form refuses a badge number held by an archived record
 * as well (StoreEmployeeRequest). A list is asked the same, one query for a
 * chunk of rows.
 *
 * This is not the resolver's question. The resolver says who a row looks like;
 * this says whether a record could be made with the number the row brings. The
 * second is a fact about the index, whatever the first says.
 *
 * The organisation is stated on the query, and the tenant scope applies as
 * well. A number held in another organisation is not held here: the scope and
 * the statement both say so.
 */
class HeldNumbers
{
    /** Values per `IN` list, below the host's limit on bound parameters. */
    private const SLICE = 400;

    /**
     * @param  iterable<mixed>  $codes  employee numbers as a list gave them
     * @param  iterable<mixed>  $badges  badge numbers as a list gave them
     */
    public function among(int $tenantId, iterable $codes, iterable $badges = []): HeldSet
    {
        $codes = $this->distinct($codes);
        $badges = $this->distinct($badges);

        $byCode = [];
        $byBadge = [];

        if ($codes === [] && $badges === []) {
            return new HeldSet($byCode, $byBadge);
        }

        // One query for the chunk, not one per kind or per row: the codes and
        // the badges are alternatives of a single predicate, and the
        // organisation is stated outside it on the same chain.
        $sliced = array_map(null, array_chunk($codes, self::SLICE) ?: [[]], array_chunk($badges, self::SLICE) ?: [[]]);

        foreach ($sliced as [$codeSlice, $badgeSlice]) {
            $found = Employee::withTrashed()
                ->where('tenant_id', $tenantId)
                ->where(function (Builder $q) use ($codeSlice, $badgeSlice): void {
                    if ($codeSlice !== null && $codeSlice !== []) {
                        $q->orWhereIn('employee_code_normalized', $codeSlice);
                    }
                    if ($badgeSlice !== null && $badgeSlice !== []) {
                        $q->orWhereRaw('LOWER(badge_number) IN ('.implode(',', array_fill(0, count($badgeSlice), '?')).')', $badgeSlice);
                    }
                })
                ->orderBy('id')
                ->get(['id', 'employee_code', 'badge_number', 'deleted_at']);

            foreach ($found as $employee) {
                $holder = ['id' => (int) $employee->id, 'archived' => $employee->trashed()];
                $code = mb_strtolower((string) $employee->employee_code);
                $badge = mb_strtolower((string) $employee->badge_number);

                if ($code !== '') {
                    $byCode[$code] = $holder;
                }

                // A badge is not unique: someone current is named before
                // someone archived.
                if ($badge !== '' && (! isset($byBadge[$badge]) || ($byBadge[$badge]['archived'] && ! $holder['archived']))) {
                    $byBadge[$badge] = $holder;
                }
            }
        }

        return new HeldSet($byCode, $byBadge);
    }

    /**
     * @param  iterable<mixed>  $values
     * @return list<string>
     */
    private function distinct(iterable $values): array
    {
        $kept = [];

        foreach ($values as $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                $kept[mb_strtolower(trim((string) $value))] = true;
            }
        }

        return array_map('strval', array_keys($kept));
    }
}
