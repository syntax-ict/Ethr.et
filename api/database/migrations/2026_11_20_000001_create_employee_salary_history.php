<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What each employee's salary was, and from which day.
 *
 * Payroll used to read `employees.salary_cents` as it was when the job ran, so
 * voiding and reprocessing October in December paid October at December's
 * salary, and a raise back-dated into a month already paid was never paid for
 * it. A run now pays the row in force on its period's last day.
 *
 * A row a later-recorded, earlier-dated change replaced carries `superseded_by`
 * (App\Services\Payroll\SalaryHistoryWriter) and is never read. A personnel
 * action or a contract may supersede any row; an edit, import, hire or backfill
 * row never supersedes an action or a contract.
 *
 * The backfill replays what the database remembers, in the order it was
 * recorded, under the same supersede rule, with "today" being the day each
 * event was recorded in the organisation's own zone: applied and pending
 * personnel actions, direct edits and contracts as the audit log has them. An
 * event dated before the hire is dated at the hire date, and a tie goes to the
 * later-recorded event. It uses the query builder and plain PHP, never an
 * Eloquent model: the tenant scope fails closed with no tenant resolved and a
 * model would write nothing. It runs the same on SQLite and MariaDB.
 *
 * Each chunk of employees is worked out in memory and written with one
 * multi-row insert and a few grouped updates, because production runs it
 * inside one web request.
 *
 * What it cannot see: a salary edited directly in the database, or edited
 * before the audit log recorded the change, was never dated. A month
 * reprocessed later can read a salary that was edited without a trace; the one
 * reconciliation row (dated today, or the day before a due pending action)
 * makes the current column the figure in force from then on.
 *
 * Safe to interrupt and to run again: the table is created only when it is
 * missing; the opening row, source `backfill`, is the marker that an employee
 * is done, and is written for everyone with a salary or a salary event (a
 * figure of 0 means no salary from the hire); a row whose source and source
 * id already exist is not written twice; each chunk is one transaction.
 */
return new class extends Migration
{
    private const DEFAULT_ZONE = 'Africa/Addis_Ababa';

    public function up(): void
    {
        if (! Schema::hasTable('employee_salary_history')) {
            $this->createTable();
        }

        $this->backfill();
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_salary_history');
    }

    private function createTable(): void
    {
        Schema::create('employee_salary_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('salary_cents');
            $table->date('effective_date');
            $table->string('source', 20);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->unsignedBigInteger('superseded_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'effective_date', 'id'], 'salary_hist_employee_date_index');
            $table->index(['tenant_id', 'source', 'source_id'], 'salary_hist_source_index');
        });
    }

    private function zone(?string $zone): string
    {
        if (! is_string($zone) || $zone === '') {
            return self::DEFAULT_ZONE;
        }

        try {
            new DateTimeZone($zone);
        } catch (Throwable) {
            return self::DEFAULT_ZONE;
        }

        return $zone;
    }

    /** The day a UTC timestamp falls on in a zone. */
    private function dayIn(string $utc, string $zone): string
    {
        return Carbon::parse($utc, 'UTC')->setTimezone($zone)->toDateString();
    }

    private function backfill(): void
    {
        $zones = DB::table('tenants')->pluck('timezone', 'id')->map(fn ($zone): string => $this->zone($zone))->all();
        $nowUtc = now('UTC');

        DB::table('employees')
            ->select(['id', 'tenant_id', 'salary_cents', 'hire_date', 'created_at'])
            ->orderBy('id')
            ->chunkById(200, function ($employees) use ($zones, $nowUtc): void {
                $done = DB::table('employee_salary_history')
                    ->whereIn('employee_id', $employees->pluck('id')->all())
                    ->where('source', 'backfill')
                    ->distinct()
                    ->pluck('employee_id')
                    ->flip();
                $employees = $employees->reject(fn ($employee): bool => $done->has($employee->id))->values();

                if ($employees->isEmpty()) {
                    return;
                }

                DB::transaction(fn () => $this->backfillChunk($employees->all(), $zones, $nowUtc));
            });
    }

    /** @param  list<object>  $employees */
    private function backfillChunk(array $employees, array $zones, Carbon $nowUtc): void
    {
        $ids = array_map(fn ($employee): int => $employee->id, $employees);
        $tenants = [];
        foreach ($employees as $employee) {
            $tenants[$employee->id] = $employee->tenant_id;
        }

        // Rows live traffic already wrote for these employees: not written twice.
        $existing = [];
        foreach (DB::table('employee_salary_history')->whereIn('employee_id', $ids)->whereNotNull('source_id')->get(['id', 'employee_id', 'source', 'source_id']) as $row) {
            $existing[$row->employee_id.'|'.$row->source.'|'.$row->source_id] = $row->id;
        }

        $events = $this->events($ids, $tenants, $zones);

        $plans = [];
        $inserts = [];
        foreach ($employees as $employee) {
            $zone = $zones[$employee->tenant_id] ?? self::DEFAULT_ZONE;
            $rows = $this->replay($employee, $events[$employee->id] ?? [], $zone, $nowUtc);

            foreach ($rows as $i => $row) {
                $key = $employee->id.'|'.$row['source'].'|'.$row['source_id'];
                if ($row['source_id'] !== null && isset($existing[$key])) {
                    $rows[$i]['id'] = $existing[$key];

                    continue;
                }
                $rows[$i]['id'] = null;
                $inserts[] = ['employee' => $employee->id, 'index' => $i, 'record' => $this->record($employee, $row)];
            }

            $plans[$employee->id] = $rows;
        }

        if ($inserts === []) {
            return;
        }

        $before = (int) (DB::table('employee_salary_history')->max('id') ?? 0);

        foreach (array_chunk(array_column($inserts, 'record'), 500) as $slice) {
            DB::table('employee_salary_history')->insert($slice);
        }

        // The ids just written, in the order they were inserted.
        $written = [];
        foreach (DB::table('employee_salary_history')->whereIn('employee_id', $ids)->where('id', '>', $before)->orderBy('id')->get(['id', 'employee_id']) as $row) {
            $written[$row->employee_id][] = $row->id;
        }

        $taken = [];
        foreach ($inserts as $insert) {
            $taken[$insert['employee']] = ($taken[$insert['employee']] ?? 0) + 1;
        }
        foreach ($taken as $employeeId => $count) {
            if (count($written[$employeeId] ?? []) !== $count) {
                throw new RuntimeException('The salary history of employee '.$employeeId.' changed while it was being rebuilt.');
            }
        }

        $cursor = [];
        foreach ($inserts as $insert) {
            $employeeId = $insert['employee'];
            $plans[$employeeId][$insert['index']]['id'] = $written[$employeeId][$cursor[$employeeId] ?? 0];
            $cursor[$employeeId] = ($cursor[$employeeId] ?? 0) + 1;
        }

        $links = [];
        foreach ($plans as $rows) {
            foreach ($rows as $row) {
                if ($row['sup'] !== null && $row['id'] !== null && $rows[$row['sup']]['id'] !== null) {
                    $links[$row['id']] = $rows[$row['sup']]['id'];
                }
            }
        }

        foreach (array_chunk($links, 400, true) as $slice) {
            $cases = '';
            $bindings = [];
            foreach ($slice as $id => $by) {
                $cases .= ' WHEN ? THEN ?';
                array_push($bindings, $id, $by);
            }
            $marks = implode(',', array_fill(0, count($slice), '?'));
            DB::update('UPDATE employee_salary_history SET superseded_by = CASE id'.$cases.' END WHERE id IN ('.$marks.')', [...$bindings, ...array_keys($slice)]);
        }
    }

    /**
     * Every salary event the database remembers for these employees.
     *
     * @param  list<int>  $ids
     * @param  array<int, int>  $tenants
     * @param  array<int, string>  $zones  each organisation's zone
     * @return array<int, list<array{rec: string, seq: int, eff: string, cents: int, from: int|null, source: string, source_id: int|null, pending: bool}>>
     */
    private function events(array $ids, array $tenants, array $zones): array
    {
        $events = [];
        $seq = 0;

        $actions = DB::table('personnel_actions')
            ->whereIn('employee_id', $ids)
            ->where('is_temporary', false)
            ->orderBy('id')
            ->get(['id', 'employee_id', 'effective_date', 'changes', 'pending_values', 'applied_at', 'created_at']);

        foreach ($actions as $action) {
            $applied = $action->applied_at !== null;
            $changes = json_decode((string) $action->changes, true);
            $from = is_array($changes) && is_numeric($changes['salary']['from'] ?? null) ? (int) $changes['salary']['from'] : null;

            if ($applied) {
                $to = is_array($changes) ? ($changes['salary']['to'] ?? null) : null;
            } else {
                $values = json_decode((string) $action->pending_values, true);
                $to = is_array($values) ? ($values['salary_cents'] ?? null) : null;
            }

            if (! is_numeric($to)) {
                continue;
            }

            $events[$action->employee_id][] = [
                'rec' => (string) $action->created_at, 'seq' => $seq++,
                'eff' => substr((string) $action->effective_date, 0, 10),
                'cents' => (int) $to, 'from' => $from,
                'source' => 'personnel_action', 'source_id' => (int) $action->id, 'pending' => ! $applied,
            ];
        }

        // A contract that starts after today and states a salary never reached
        // payroll before this release: its future row is written here. One
        // that was ended before its start never takes effect.
        $now = now('UTC');
        $future = DB::table('employee_contracts')
            ->whereIn('employee_id', $ids)
            ->where('salary_cents', '>', 0)
            ->where('start_date', '>', $now->copy()->subDay()->toDateString())
            ->orderBy('id')
            ->get(['id', 'employee_id', 'start_date', 'ended_at', 'salary_cents', 'created_at']);

        foreach ($future as $contract) {
            $start = substr((string) $contract->start_date, 0, 10);
            $zone = $zones[$tenants[$contract->employee_id] ?? 0] ?? self::DEFAULT_ZONE;

            if ($start <= $now->copy()->setTimezone($zone)->toDateString()
                || ($contract->ended_at !== null && substr((string) $contract->ended_at, 0, 10) < $start)) {
                continue;
            }

            $events[$contract->employee_id][] = [
                'rec' => (string) $contract->created_at, 'seq' => $seq++,
                'eff' => $start, 'cents' => (int) $contract->salary_cents, 'from' => null,
                'source' => 'contract', 'source_id' => (int) $contract->id, 'pending' => true,
            ];
        }

        // Each payload is decoded once.
        $audits = [];
        $publicIds = [];
        $query = DB::table('audit_log')
            ->where('auditable_type', 'App\\Models\\Employee')
            ->whereIn('auditable_id', $ids)
            ->whereIn('action', ['employee.updated', 'employee.contract_created', 'employee.contract_renewed'])
            ->orderBy('id');

        foreach ($query->get(['id', 'action', 'auditable_id', 'payload', 'created_at']) as $audit) {
            $payload = json_decode((string) $audit->payload, true);
            if (! is_array($payload)) {
                continue;
            }

            $public = null;
            if ($audit->action !== 'employee.updated') {
                $public = $audit->action === 'employee.contract_renewed' ? ($payload['new_contract_id'] ?? null) : ($payload['contract_id'] ?? null);
                if (is_string($public)) {
                    $publicIds[] = $public;
                }
            }

            $audits[] = [$audit, $payload, $public];
        }

        $contracts = $publicIds === [] ? collect() : DB::table('employee_contracts')
            ->whereIn('public_id', $publicIds)
            ->get(['id', 'public_id', 'start_date'])
            ->keyBy('public_id');

        foreach ($audits as [$audit, $payload, $public]) {
            $employeeId = (int) $audit->auditable_id;
            $zone = $zones[$tenants[$employeeId] ?? 0] ?? self::DEFAULT_ZONE;

            if ($audit->action === 'employee.updated') {
                $before = is_array($payload['before'] ?? null) ? $payload['before'] : [];
                $after = is_array($payload['after'] ?? null) ? $payload['after'] : [];

                if (! is_numeric($after['salary_cents'] ?? null) || (int) $after['salary_cents'] === (int) ($before['salary_cents'] ?? -1)) {
                    continue;
                }

                $events[$employeeId][] = [
                    'rec' => (string) $audit->created_at, 'seq' => $seq++,
                    'eff' => $this->dayIn((string) $audit->created_at, $zone),
                    'cents' => (int) $after['salary_cents'],
                    'from' => is_numeric($before['salary_cents'] ?? null) ? (int) $before['salary_cents'] : null,
                    'source' => 'edit', 'source_id' => (int) $audit->id, 'pending' => false,
                ];

                continue;
            }

            $contract = is_string($public) ? $contracts->get($public) : null;

            if ($contract === null || ! is_numeric($payload['salary_cents_to'] ?? null)) {
                continue;
            }

            $events[$employeeId][] = [
                'rec' => (string) $audit->created_at, 'seq' => $seq++,
                'eff' => substr((string) $contract->start_date, 0, 10),
                'cents' => (int) $payload['salary_cents_to'],
                'from' => is_numeric($payload['salary_cents_from'] ?? null) ? (int) $payload['salary_cents_from'] : null,
                'source' => 'contract', 'source_id' => (int) $contract->id, 'pending' => false,
            ];
        }

        foreach ($events as &$list) {
            usort($list, fn (array $a, array $b): int => [$a['rec'], $a['seq']] <=> [$b['rec'], $b['seq']]);
        }

        return $events;
    }

    /**
     * Replay one employee's events in the order they were recorded, in memory.
     * Each row's `sup` is the index of the row that superseded it.
     *
     * @param  list<array{rec: string, seq: int, eff: string, cents: int, from: int|null, source: string, source_id: int|null, pending: bool}>  $events
     * @return list<array<string, mixed>>
     */
    private function replay(object $employee, array $events, string $zone, Carbon $nowUtc): array
    {
        $current = (int) $employee->salary_cents;

        /** @var list<array<string, mixed>> $rows */
        $rows = [];

        if ($events === [] && $current <= 0) {
            return $rows;
        }

        $hired = $employee->hire_date !== null
            ? substr((string) $employee->hire_date, 0, 10)
            : substr((string) $employee->created_at, 0, 10);

        // The first recorded event's "from" is what the salary was before any
        // of them; a 0 is true and means no salary from the hire.
        $opening = max(0, ($events[0]['from'] ?? null) ?? $current);
        $rows[] = ['cents' => $opening, 'eff' => $hired, 'source' => 'backfill', 'source_id' => null, 'pending' => false, 'at' => (string) $employee->created_at, 'sup' => null];

        foreach ($events as $event) {
            $today = $this->dayIn($event['rec'], $zone);
            $eff = max($event['eff'], $hired);
            $index = count($rows);
            $rows[] = ['cents' => $event['cents'], 'eff' => $eff, 'source' => $event['source'], 'source_id' => $event['source_id'], 'pending' => $event['pending'], 'at' => $event['rec'], 'sup' => null];

            // Formality: only an action or a contract may supersede an action or
            // a contract. Duplicated from SalaryHistoryWriter::FORMAL_SOURCES on
            // purpose, since a migration must not depend on an app class.
            $formal = in_array($event['source'], ['personnel_action', 'contract'], true);

            if ($eff <= $today) {
                foreach ($rows as $i => $row) {
                    if ($i === $index || $row['sup'] !== null || ! ($row['eff'] > $eff && $row['eff'] <= $today)) {
                        continue;
                    }
                    if (! $formal && in_array($row['source'], ['personnel_action', 'contract'], true)) {
                        continue;
                    }

                    $rows[$i]['sup'] = $index;
                }
            }
        }

        $today = $nowUtc->copy()->setTimezone($zone)->toDateString();

        // The figure in force today from what has been applied, against the
        // record. A due but unapplied action is left to applyDue(), so the
        // reconciliation is dated the day before it.
        $inForce = null;
        $latestDate = null;
        $earliestDue = null;
        foreach ($rows as $row) {
            if ($row['sup'] !== null) {
                continue;
            }
            if ($row['pending']) {
                if ($row['eff'] <= $today && ($earliestDue === null || $row['eff'] < $earliestDue)) {
                    $earliestDue = $row['eff'];
                }

                continue;
            }
            if ($row['eff'] <= $today && ($inForce === null || $row['eff'] >= $inForce['eff'])) {
                $inForce = $row;
            }
            if ($latestDate === null || $row['eff'] > $latestDate) {
                $latestDate = $row['eff'];
            }
        }

        if ($current > 0 && ($inForce === null || (int) $inForce['cents'] !== $current)) {
            $date = max($today, $hired);
            if ($earliestDue !== null) {
                $date = Carbon::parse($earliestDue)->subDay()->toDateString();
                if ($latestDate !== null && $date <= $latestDate) {
                    $date = null;
                }
            }
            if ($date !== null) {
                $rows[] = ['cents' => $current, 'eff' => $date, 'source' => 'backfill', 'source_id' => null, 'pending' => false, 'at' => $nowUtc->toDateTimeString(), 'sup' => null];
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function record(object $employee, array $row): array
    {
        return [
            'tenant_id' => $employee->tenant_id,
            'employee_id' => $employee->id,
            'salary_cents' => $row['cents'],
            'effective_date' => $row['eff'],
            'source' => $row['source'],
            'source_id' => $row['source_id'],
            'recorded_by' => null,
            'superseded_by' => null,
            'created_at' => $row['at'],
            'updated_at' => $row['at'],
        ];
    }
};
