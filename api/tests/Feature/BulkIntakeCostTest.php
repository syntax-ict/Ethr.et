<?php

declare(strict_types=1);

use App\Jobs\CleanupExpiredDataJob;
use App\Models\Employee;
use App\Models\MigrationBatch;
use App\Models\MigrationStagingRow;
use App\Models\User;
use App\Services\Invitations\EmployeeLogins;
use App\Services\Migration\WorkforceMigrationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;

/*
 * What bringing a few hundred people in costs, on a host that stops a request
 * at 30 seconds and has no worker. Three things grew with the size of the list
 * in ways the list's author never sees: every person without a number re-read
 * every employee number; every login hashed its own throwaway password; and a
 * list still being reviewed was deleted a week after it was pasted.
 */

describe('numbering a staged list', function () {
    it('reads the organisation\'s numbers once for the list, not once for each person', function () {
        $tenant = createTenant();
        $service = app(WorkforceMigrationService::class);

        $batch = $service->stageFromRows(
            array_map(fn (int $i): array => ['name' => "Person Number{$i}", 'hire_date' => '2026-01-01'], range(1, 12)),
            $tenant->id,
        );

        $reads = 0;
        DB::listen(function ($query) use (&$reads): void {
            $sql = mb_strtolower($query->sql);
            if (str_contains($sql, 'employee_code') && str_contains($sql, ' like ')) {
                $reads++;
            }
        });

        $totals = $service->commit($batch);

        expect($totals['created'])->toBe(12)
            ->and($reads)->toBe(1)
            ->and(Employee::where('tenant_id', $tenant->id)->orderBy('id')->pluck('employee_code')->all())
            ->toBe(array_map(fn (int $i): string => sprintf('EMP-%04d', $i), range(1, 12)));
    });

    it('gives the next free number when a row in the list already holds the one set aside', function () {
        $tenant = createTenant();
        $service = app(WorkforceMigrationService::class);

        $batch = $service->stageFromRows([
            ['name' => 'First Unnumbered', 'hire_date' => '2026-01-01'],
            ['name' => 'Brings Their Own', 'employee_code' => 'EMP-0002', 'hire_date' => '2026-01-01'],
            ['name' => 'Second Unnumbered', 'hire_date' => '2026-01-01'],
        ], $tenant->id);

        $service->commit($batch);

        expect(Employee::where('tenant_id', $tenant->id)->orderBy('id')->pluck('employee_code', 'name')->all())->toBe([
            'First Unnumbered' => 'EMP-0001',
            'Brings Their Own' => 'EMP-0002',
            'Second Unnumbered' => 'EMP-0003',
        ]);
    });
});

describe('logins for a list', function () {
    it('hashes one throwaway password for the whole list', function () {
        Queue::fake();
        $tenant = createTenant();
        $employees = Employee::factory()->count(4)->sequence(
            fn ($sequence): array => ['email' => "person{$sequence->index}@acme.test"],
        )->create(['tenant_id' => $tenant->id]);

        $result = app(EmployeeLogins::class)->forEmployees($employees);

        $hashes = User::where('tenant_id', $tenant->id)->whereIn('employee_id', $employees->pluck('id'))->pluck('password');

        expect($result['created'])->toBe(4)
            ->and($hashes)->toHaveCount(4)
            ->and($hashes->unique())->toHaveCount(1)
            ->and(Hash::check('', $hashes->first()))->toBeFalse();
    });

    it('does not carry that password into the next list', function () {
        Queue::fake();
        $tenant = createTenant();
        $first = Employee::factory()->create(['tenant_id' => $tenant->id, 'email' => 'first@acme.test']);
        $second = Employee::factory()->create(['tenant_id' => $tenant->id, 'email' => 'second@acme.test']);

        app(EmployeeLogins::class)->forEmployees([$first]);
        app(EmployeeLogins::class)->forEmployees([$second]);

        expect(User::where('employee_id', $first->id)->value('password'))
            ->not->toBe(User::where('employee_id', $second->id)->value('password'));
    });
});

describe('how long a staged list is kept', function () {
    /** A batch with one row, both last touched `$days` days ago. */
    $staged = function (int $tenantId, int $days, ?int $rowTouchedDaysAgo = null): MigrationBatch {
        $batch = MigrationBatch::create(['tenant_id' => $tenantId, 'source_type' => 'csv', 'status' => 'reviewing']);
        $row = MigrationStagingRow::create([
            'tenant_id' => $tenantId, 'batch_id' => $batch->id, 'raw' => ['name' => 'Someone'], 'action' => 'pending',
        ]);

        $then = now()->subDays($days);
        DB::table('migration_batches')->where('id', $batch->id)->update(['created_at' => $then, 'updated_at' => $then]);
        DB::table('migration_staging_rows')->where('id', $row->id)->update([
            'created_at' => $then,
            'updated_at' => now()->subDays($rowTouchedDaysAgo ?? $days),
        ]);

        return $batch;
    };

    $exists = fn (MigrationBatch $batch): bool => DB::table('migration_batches')->where('id', $batch->id)->exists();

    it('deletes a list nobody has touched for more than thirty days, rows and all', function () use ($staged, $exists) {
        $tenant = createTenant();
        $old = $staged($tenant->id, 31);

        (new CleanupExpiredDataJob)->handle();

        expect($exists($old))->toBeFalse()
            ->and(DB::table('migration_staging_rows')->where('batch_id', $old->id)->exists())->toBeFalse();
    });

    it('keeps a list past the old seven days', function () use ($staged, $exists) {
        $tenant = createTenant();
        $week = $staged($tenant->id, 8);

        (new CleanupExpiredDataJob)->handle();

        expect($exists($week))->toBeTrue();
    });

    it('keeps an old list someone is still deciding rows on', function () use ($staged, $exists) {
        $tenant = createTenant();
        $inReview = $staged($tenant->id, 45, rowTouchedDaysAgo: 3);

        (new CleanupExpiredDataJob)->handle();

        expect($exists($inReview))->toBeTrue();
    });

    it('keeps a list on its thirtieth day and deletes it the moment after', function () use ($staged, $exists) {
        // Frozen: the job reads now() after the test does, and on a live clock
        // the boundary row is a fraction of a second too old (DataRetentionSweepTest).
        Carbon::setTestNow('2026-11-10 09:00:00');
        $tenant = createTenant();
        $onTheDay = $staged($tenant->id, 30);

        (new CleanupExpiredDataJob)->handle();
        expect($exists($onTheDay))->toBeTrue();

        Carbon::setTestNow('2026-11-10 09:00:01');
        (new CleanupExpiredDataJob)->handle();
        expect($exists($onTheDay))->toBeFalse();

        Carbon::setTestNow();
    });
});
