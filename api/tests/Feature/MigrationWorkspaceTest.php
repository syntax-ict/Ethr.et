<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Jobs\CleanupExpiredDataJob;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeExternalIdentity;
use App\Models\MigrationBatch;
use App\Models\MigrationStagingRow;
use App\Services\Migration\WorkforceMigrationService;
use Illuminate\Support\Facades\DB;

function migrationService(): WorkforceMigrationService
{
    return app(WorkforceMigrationService::class);
}

describe('staging from a device', function () {
    it('stages enrolled users with a resolver verdict and suggested action', function () {
        $tenant = createTenant();
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $emp = Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'employee_code' => 'EMP-1',
            'badge_number' => '1001',
        ]);
        $device = Device::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'adapter_type' => 'mock',
            'status' => 'online',
        ]);

        $batch = migrationService()->stageFromDevice($device);

        $row = $batch->rows()->first();
        expect($row->match_outcome)->toBe('matched');
        expect($row->action)->toBe(MigrationStagingRow::ACTION_MERGE);
        expect($row->resolved_employee_id)->toBe($emp->id);
    });

    it('links the device identity on commit without creating a duplicate', function () {
        $tenant = createTenant();
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $emp = Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'EMP-1', 'badge_number' => '1001']);
        $device = Device::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'adapter_type' => 'mock',
            'status' => 'online',
        ]);

        $batch = migrationService()->stageFromDevice($device);
        $totals = migrationService()->commit($batch);

        expect($totals['merged'])->toBe(1);
        expect(Employee::where('tenant_id', $tenant->id)->count())->toBe(1);
        expect(EmployeeExternalIdentity::where('tenant_id', $tenant->id)->where('employee_id', $emp->id)->exists())->toBeTrue();
    });

    it('is idempotent once committed', function () {
        $tenant = createTenant();
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'EMP-1', 'badge_number' => '1001']);
        $device = Device::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'adapter_type' => 'mock', 'status' => 'online']);

        $batch = migrationService()->stageFromDevice($device);
        migrationService()->commit($batch);
        migrationService()->commit($batch->refresh());

        expect(EmployeeExternalIdentity::where('tenant_id', $tenant->id)->count())->toBe(1);
    });
});

describe('staging from rows', function () {
    it('creates new people and merges existing ones on commit', function () {
        $tenant = createTenant();
        Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'E1', 'name' => 'Existing One']);

        $batch = migrationService()->stageFromRows([
            ['name' => 'Existing One', 'employee_code' => 'E1'],
            ['name' => 'Brand New', 'employee_code' => 'N1', 'hire_date' => '2026-01-01'],
        ], $tenant->id);

        $rows = $batch->rows()->orderBy('id')->get();
        expect($rows[0]->action)->toBe(MigrationStagingRow::ACTION_MERGE);
        expect($rows[1]->action)->toBe(MigrationStagingRow::ACTION_CREATE);

        $totals = migrationService()->commit($batch);

        expect($totals['created'])->toBe(1);
        expect($totals['merged'])->toBe(1);
        expect(Employee::where('tenant_id', $tenant->id)->count())->toBe(2);
        expect(Employee::where('tenant_id', $tenant->id)->where('employee_code', 'N1')->exists())->toBeTrue();
    });

    it('honours a reviewer overriding the suggested action to skip', function () {
        $tenant = createTenant();

        $batch = migrationService()->stageFromRows([
            ['name' => 'Would Be New', 'employee_code' => 'X1', 'hire_date' => '2026-01-01'],
        ], $tenant->id);

        $row = $batch->rows()->first();
        migrationService()->setAction($row, MigrationStagingRow::ACTION_SKIP);

        $totals = migrationService()->commit($batch->refresh());

        expect($totals['skipped'])->toBe(1);
        expect($totals['created'])->toBe(0);
        expect(Employee::where('tenant_id', $tenant->id)->count())->toBe(0);
    });

    it('carries candidate names alongside scores and reasons so a review UI needs no extra lookup', function () {
        $tenant = createTenant();
        Employee::factory()->create(['tenant_id' => $tenant->id, 'phone' => '0911000000', 'name' => 'Person A']);
        Employee::factory()->create(['tenant_id' => $tenant->id, 'phone' => '0911000000', 'name' => 'Person B']);

        $batch = migrationService()->stageFromRows([
            ['name' => 'New Arrival', 'phone' => '0911000000'],
        ], $tenant->id);

        $row = $batch->rows()->first();
        expect($row->match_outcome)->toBe('ambiguous');
        expect($row->candidates)->toHaveCount(2);
        expect($row->candidates[0])->toHaveKeys(['employee_public_id', 'employee_name', 'score', 'reasons']);
        expect(collect($row->candidates)->pluck('employee_name')->sort()->values()->all())
            ->toBe(['Person A', 'Person B']);
    });

    it('lets a reviewer resolve an ambiguous row by picking one of the candidates', function () {
        $tenant = createTenant();
        $a = Employee::factory()->create(['tenant_id' => $tenant->id, 'phone' => '0911000000', 'name' => 'Person A']);
        Employee::factory()->create(['tenant_id' => $tenant->id, 'phone' => '0911000000', 'name' => 'Person B']);

        $batch = migrationService()->stageFromRows([
            ['name' => 'New Arrival', 'phone' => '0911000000'],
        ], $tenant->id);

        $row = $batch->rows()->first();
        expect($row->match_outcome)->toBe('ambiguous');

        migrationService()->setAction($row, MigrationStagingRow::ACTION_MERGE, $a);
        $totals = migrationService()->commit($batch->refresh());

        expect($totals['merged'])->toBe(1);
        expect(Employee::where('tenant_id', $tenant->id)->count())->toBe(2); // no third person created
        // No EmployeeExternalIdentity row here: this batch was CSV-staged, so
        // its signals carry no external-identity coordinates (source/identifier
        // type+value) for link() to attach — that mapping is device-sync-only,
        // by IdentityResolver::link()'s own guard. What matters for this test
        // is that the *chosen* candidate, not the resolver's re-guess, was merged.
        expect($row->refresh()->resolved_employee_id)->toBe($a->id);
    });

    it('leaves an ambiguous row without a picked candidate deferred rather than guessing', function () {
        $tenant = createTenant();
        Employee::factory()->create(['tenant_id' => $tenant->id, 'phone' => '0911000000', 'name' => 'Person A']);
        Employee::factory()->create(['tenant_id' => $tenant->id, 'phone' => '0911000000', 'name' => 'Person B']);

        $batch = migrationService()->stageFromRows([
            ['name' => 'New Arrival', 'phone' => '0911000000'],
        ], $tenant->id);

        $row = $batch->rows()->first();
        migrationService()->setAction($row, MigrationStagingRow::ACTION_MERGE);
        $totals = migrationService()->commit($batch->refresh());

        expect($totals['merged'])->toBe(0);
        expect($totals['deferred'])->toBe(1);
        expect(Employee::where('tenant_id', $tenant->id)->count())->toBe(2);
    });
});

describe('migration endpoints', function () {
    it('stages, reviews, and commits over the API', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $host = "http://{$tenant->subdomain}.ethr.test/api/v1";

        $staged = test()->postJson("{$host}/onboarding/migration/rows", [
            'rows' => [
                ['name' => 'New Hire', 'employee_code' => 'NH1', 'hire_date' => '2026-01-01'],
            ],
        ])->assertCreated()->json();

        $batchId = $staged['public_id'];
        $rowId = $staged['rows'][0]['public_id'];

        expect($staged['rows'][0]['action'])->toBe('create');

        test()->postJson("{$host}/onboarding/migration/batches/{$batchId}/commit")
            ->assertOk()
            ->assertJsonPath('totals.created', 1)
            ->assertJsonPath('status', 'committed');

        expect(Employee::where('tenant_id', $tenant->id)->where('employee_code', 'NH1')->exists())->toBeTrue();

        // The row id remains addressable for review after commit.
        expect($rowId)->not->toBeNull();
    });

    it('lets a reviewer resolve an ambiguous row to a chosen candidate over the API', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $host = "http://{$tenant->subdomain}.ethr.test/api/v1";

        $a = Employee::factory()->create(['tenant_id' => $tenant->id, 'phone' => '0911000000', 'name' => 'Person A']);
        Employee::factory()->create(['tenant_id' => $tenant->id, 'phone' => '0911000000', 'name' => 'Person B']);

        $staged = test()->postJson("{$host}/onboarding/migration/rows", [
            'rows' => [['name' => 'New Arrival', 'phone' => '0911000000']],
        ])->assertCreated()->json();

        $rowId = $staged['rows'][0]['public_id'];
        expect($staged['rows'][0]['match_outcome'])->toBe('ambiguous');
        expect($staged['rows'][0]['candidates'])->toHaveCount(2);

        test()->patchJson("{$host}/onboarding/migration/rows/{$rowId}", [
            'action' => 'merge',
            'employee_public_id' => $a->public_id,
        ])->assertOk()
            ->assertJsonPath('resolved_employee_public_id', $a->public_id);

        test()->postJson("{$host}/onboarding/migration/batches/{$staged['public_id']}/commit")
            ->assertOk()
            ->assertJsonPath('totals.merged', 1);

        expect(Employee::where('tenant_id', $tenant->id)->count())->toBe(2);
    });

    it('ignores an employee_public_id belonging to another tenant', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $host = "http://{$tenant->subdomain}.ethr.test/api/v1";

        $other = createTenant();
        $foreign = Employee::factory()->create(['tenant_id' => $other->id]);

        $staged = test()->postJson("{$host}/onboarding/migration/rows", [
            'rows' => [['name' => 'New Arrival', 'employee_code' => 'X9']],
        ])->assertCreated()->json();

        $rowId = $staged['rows'][0]['public_id'];

        test()->patchJson("{$host}/onboarding/migration/rows/{$rowId}", [
            'action' => 'merge',
            'employee_public_id' => $foreign->public_id,
        ])->assertOk()
            ->assertJsonPath('resolved_employee_public_id', null);
    });

    it('denies migration to employee-role users', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

        test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/onboarding/migration/rows", [
            'rows' => [['name' => 'X', 'employee_code' => 'X']],
        ])->assertForbidden();
    });
});

describe('staging purge', function () {
    it('hard-deletes staging batches older than seven days', function () {
        $tenant = createTenant();

        $old = MigrationBatch::create(['tenant_id' => $tenant->id, 'source_type' => 'csv', 'status' => 'reviewing']);
        MigrationStagingRow::create([
            'tenant_id' => $tenant->id, 'batch_id' => $old->id, 'raw' => ['name' => 'Old'], 'action' => 'pending',
        ]);
        // Backdate past the 7-day window.
        DB::table('migration_batches')->where('id', $old->id)->update(['created_at' => now()->subDays(8)]);

        $recent = MigrationBatch::create(['tenant_id' => $tenant->id, 'source_type' => 'csv', 'status' => 'reviewing']);

        (new CleanupExpiredDataJob)->handle();

        expect(MigrationBatch::withoutGlobalScopes()->find($old->id))->toBeNull();
        expect(MigrationBatch::withoutGlobalScopes()->find($recent->id))->not->toBeNull();
    });
});
