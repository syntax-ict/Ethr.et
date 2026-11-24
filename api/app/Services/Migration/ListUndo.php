<?php

declare(strict_types=1);

namespace App\Services\Migration;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\MigrationBatch;
use App\Models\User;
use App\Services\Employees\EmployeeArchiver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Undo what a committed list added.
 *
 * A preview says who would be archived and who would be kept, and an explicit
 * second call archives them, a slice at a time as a commit adds them. Archived
 * is the ordinary delete (`Employee::delete()`), so everyone can be restored
 * with `POST /employees/{employee}/restore`, and the list's own record is not touched.
 *
 * Only people the list itself made are candidates (ListAdditions). Someone the
 * list only matched is never touched, and a field a merge changed is never
 * reverted. Someone who has been used since is kept (AdditionUse), and
 * someone the person asking has no reach over is neither archived nor named.
 *
 * The audit entry for the whole undo is written once, by the request that
 * finishes it, and only when something was archived since the last one: a
 * second press, or a second tab finishing later, archives nobody and writes
 * nothing.
 */
final class ListUndo
{
    public const UNSURE = 'unsure_origin';

    /** People named in each list of a preview. */
    public const SHOWN = 200;

    /** Ids read per query. */
    private const CHUNK = 500;

    public function __construct(
        private readonly ListAdditions $additions,
        private readonly AdditionUse $use,
        private readonly EmployeeArchiver $archiver,
        private readonly float $sliceSeconds = WorkforceMigrationService::SLICE_SECONDS,
        private readonly int $chunk = WorkforceMigrationService::CHUNK,
    ) {}

    public function preview(MigrationBatch $batch, User $actor): UndoPlan
    {
        $additions = $this->additions->of($batch);
        $ids = [...$additions->created, ...$additions->unsure];

        $people = new Collection;
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $people = $people->concat(
                Employee::withTrashed()->where('tenant_id', $batch->tenant_id)->whereIn('id', $chunk)->get(),
            );
        }
        $people = $people->keyBy('id');

        $live = $people->filter(fn (Employee $employee): bool => ! $employee->trashed())->keys()->map(fn ($id): int => (int) $id)->all();
        $reasons = $this->use->reasons($batch->tenant_id, $live);
        $gate = Gate::forUser($actor);

        $archive = [];
        $keep = [];
        $already = 0;
        $outOfReach = 0;

        foreach ($additions->created as $id) {
            $person = $people->get($id);
            if (! $person instanceof Employee) {
                continue;
            }

            if ($person->trashed()) {
                $already++;
            } elseif (! $gate->allows('delete', $person)) {
                $outOfReach++;
            } elseif (isset($reasons[$id])) {
                $keep[] = ['employee' => $person, 'reasons' => $reasons[$id]];
            } else {
                $archive[] = $person;
            }
        }

        foreach ($additions->unsure as $id) {
            $person = $people->get($id);
            if (! $person instanceof Employee || $person->trashed()) {
                continue;
            }

            if (! $gate->allows('delete', $person)) {
                $outOfReach++;
            } else {
                $keep[] = ['employee' => $person, 'reasons' => [self::UNSURE, ...($reasons[$id] ?? [])]];
            }
        }

        usort($archive, fn (Employee $a, Employee $b): int => $this->byName($a, $b));
        usort($keep, fn (array $a, array $b): int => $this->byName($a['employee'], $b['employee']));

        return new UndoPlan($additions, $archive, $keep, $already, $outOfReach);
    }

    /**
     * Archive as many of the list's people as fit in one request.
     *
     * Each chunk is its own transaction, under the list's own lock, and asks
     * again whether each person in it is still unused and still within reach:
     * the preview was a moment ago and a slice is a request later. At least
     * one chunk is done by every request, so one that starts late still moves
     * the undo on.
     */
    public function undoSlice(MigrationBatch $batch, User $actor): UndoProgress
    {
        $plan = $this->preview($batch, $actor);
        $ids = $plan->archiveIds();
        $started = microtime(true);
        $visited = 0;

        foreach (array_chunk($ids, $this->chunk) as $chunk) {
            if ($visited > 0 && microtime(true) - $started >= $this->sliceSeconds) {
                break;
            }

            DB::transaction(fn () => $this->archiveChunk($batch, $actor, $chunk));
            $visited += count($chunk);
        }

        $remaining = count($ids) - $visited;
        $kept = count($plan->keep);

        if ($remaining > 0) {
            return new UndoProgress(false, $this->totals($this->fresh($batch))['pending'], $remaining, $kept, $plan->outOfReach);
        }

        return new UndoProgress(true, $this->finish($batch, $plan), 0, $kept, $plan->outOfReach);
    }

    /**
     * @param  list<int>  $ids
     */
    private function archiveChunk(MigrationBatch $batch, User $actor, array $ids): void
    {
        $locked = MigrationBatch::query()
            ->where('tenant_id', $batch->tenant_id)
            ->whereKey($batch->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        if (! $locked->isCommitted()) {
            return;
        }

        $people = Employee::query()->where('tenant_id', $batch->tenant_id)->whereIn('id', $ids)->get();

        $archived = $this->archiver->archive(
            $people,
            $actor,
            ['via' => 'migration.undo', 'batch' => $locked->public_id],
            ['reason' => 'migration.undo', 'batch' => $locked->public_id],
        );

        if ($archived === []) {
            return;
        }

        $totals = $this->totals($locked);
        $locked->update(['undo_totals' => [...$totals, 'pending' => $totals['pending'] + count($archived)]]);
    }

    /**
     * Close the undo: one audit entry for everything archived since the last,
     * when anything was. Returns how many that was.
     */
    private function finish(MigrationBatch $batch, UndoPlan $plan): int
    {
        return DB::transaction(function () use ($batch, $plan): int {
            $locked = MigrationBatch::query()
                ->where('tenant_id', $batch->tenant_id)
                ->whereKey($batch->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $totals = $this->totals($locked);
            if ($totals['pending'] === 0) {
                return 0;
            }

            AuditLog::record('migration.undone', $locked, [
                'batch' => $locked->public_id,
                'added' => $plan->additions->added(),
                'archived' => $totals['pending'],
                'kept' => count($plan->keep),
                'out_of_reach' => $plan->outOfReach,
                'origin' => $plan->additions->origin,
                'exact' => $plan->additions->exact(),
            ]);

            $locked->update([
                'undone_at' => now(),
                'undo_totals' => ['archived' => $totals['archived'] + $totals['pending'], 'pending' => 0],
            ]);

            return $totals['pending'];
        });
    }

    /** @return array{archived: int, pending: int} */
    private function totals(MigrationBatch $batch): array
    {
        $stored = $batch->getAttribute('undo_totals');
        $stored = is_array($stored) ? $stored : [];

        return [
            'archived' => is_int($stored['archived'] ?? null) ? $stored['archived'] : 0,
            'pending' => is_int($stored['pending'] ?? null) ? $stored['pending'] : 0,
        ];
    }

    private function fresh(MigrationBatch $batch): MigrationBatch
    {
        return MigrationBatch::query()->where('tenant_id', $batch->tenant_id)->whereKey($batch->getKey())->firstOrFail();
    }

    private function byName(Employee $a, Employee $b): int
    {
        return [mb_strtolower($a->name), $a->id] <=> [mb_strtolower($b->name), $b->id];
    }
}
