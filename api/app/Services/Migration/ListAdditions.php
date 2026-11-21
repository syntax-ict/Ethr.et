<?php

declare(strict_types=1);

namespace App\Services\Migration;

use App\Models\Employee;
use App\Models\MigrationBatch;
use App\Models\MigrationStagingRow;
use App\Services\Identity\EmployeeIntake;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Who a committed list made, as opposed to who it only matched.
 *
 * A row chosen as "create" either made the person or, because they appeared
 * between the review and the commit, only linked to someone already here. A
 * commit now records which (`created_employee`). A list committed before that
 * was recorded is worked out instead: someone counts as made by the list when
 * they were made after the list was staged and before their row was processed,
 * by the list's own route (`created_via`) or by one that did not say.
 *
 * That derived set always holds the true one, and may hold more: a person who
 * was only linked, made some other way inside the same minutes. The list's own
 * `totals.created` settles it. When the people established as made number more
 * than it says, some of the derived ones were only linked and cannot be told
 * apart, so none of them is taken as the list's: they are `unsure` and are
 * kept. A person a commit recorded as made stays made either way.
 */
final class ListAdditions
{
    /** Ids read per query. */
    private const CHUNK = 500;

    public function of(MigrationBatch $batch): Additions
    {
        // Only the columns read: `raw` is encrypted and is not needed here.
        $rows = MigrationStagingRow::query()
            ->where('tenant_id', $batch->tenant_id)
            ->where('batch_id', $batch->id)
            ->whereNotNull('processed_at')
            ->whereNotNull('resolved_employee_id')
            ->get(['id', 'action', 'resolved_employee_id', 'created_employee', 'processed_at']);

        $recorded = [];
        $legacy = [];
        $pointedAt = [];

        foreach ($rows as $row) {
            $id = (int) $row->resolved_employee_id;
            $pointedAt[$id] = true;

            if ($row->action !== MigrationStagingRow::ACTION_CREATE) {
                continue;
            }

            if ($row->created_employee === true) {
                $recorded[$id] = true;
            } elseif ($row->created_employee === null) {
                $legacy[] = $row;
            }
        }

        $derived = $this->derive($batch, $legacy);
        $established = $recorded + $derived;

        $created = $recorded;
        $unsure = [];
        $total = $this->totalCreated($batch);

        if ($derived !== [] && ($total === null || count($established) > $total)) {
            $unsure = array_diff_key($derived, $recorded);
        } else {
            $created = $established;
        }

        $matched = count(array_diff_key($pointedAt, $created, $unsure));

        return new Additions(
            created: array_keys($created),
            unsure: array_keys($unsure),
            matched: $matched,
            origin: $legacy === [] ? Additions::RECORDED : Additions::DERIVED,
            declared: $total,
        );
    }

    /**
     * The people the legacy create rows point at who were made inside the
     * window of their own row.
     *
     * @param  list<MigrationStagingRow>  $rows
     * @return array<int, true>
     */
    private function derive(MigrationBatch $batch, array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $via = $batch->source_type === 'device' ? EmployeeIntake::VIA_DEVICE : EmployeeIntake::VIA_MIGRATION;
        $staged = $batch->getAttribute('created_at');

        $processed = [];
        foreach ($rows as $row) {
            $at = $row->getAttribute('processed_at');
            $id = (int) $row->resolved_employee_id;
            // Two rows for one person: the later time is the one that bounds them.
            if ($at instanceof Carbon && (! isset($processed[$id]) || $at->gt($processed[$id]))) {
                $processed[$id] = $at;
            }
        }

        $derived = [];

        foreach (array_chunk(array_keys($processed), self::CHUNK) as $ids) {
            $people = Employee::withTrashed()
                ->where('tenant_id', $batch->tenant_id)
                ->whereIn('id', $ids)
                ->get(['id', 'created_at', 'created_via']);

            foreach ($people as $person) {
                $madeAt = $person->created_at;
                $by = $person->created_via;

                if ($madeAt === null || ($staged instanceof DateTimeInterface && $madeAt->lt($staged)) || $madeAt->gt($processed[$person->id])) {
                    continue;
                }

                if ($by === null || $by === $via) {
                    $derived[$person->id] = true;
                }
            }
        }

        return $derived;
    }

    /** What the list said it created, or null when it said nothing a number can be read from. */
    private function totalCreated(MigrationBatch $batch): ?int
    {
        $totals = $batch->getAttribute('totals');

        return is_array($totals) && isset($totals['created']) && is_int($totals['created']) ? $totals['created'] : null;
    }
}
