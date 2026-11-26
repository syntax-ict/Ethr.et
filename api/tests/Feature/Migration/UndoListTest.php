<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AccountActivationCode;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\MigrationBatch;
use App\Models\MigrationStagingRow;
use App\Models\User;
use App\Services\Auth\ActivationCodeService;
use App\Services\Migration\ListUndo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\StagingFixtures as Staging;
use Tests\Support\UndoFixtures as Undo;

/**
 * Undo what a committed list added: a preview, then an explicit second call
 * that archives the people the list itself made, and nobody else. A person the
 * list only matched is never touched, and a person who has been used since is
 * kept, named, with the reason.
 */
describe('archiving what the list added', function () {
    it('archives the people the list made, audits each, and every one can be restored', function () {
        [$tenant, $user] = Undo::admin();
        $batch = Undo::committed($tenant, $user, Undo::people(['Abebe Kebede', 'Sara Tesfaye', 'Dawit Haile']));

        test()->postJson(Undo::url($tenant, $batch), ['confirm' => true])->assertOk()->assertExactJson([
            'done' => true,
            'archived' => 3,
            'remaining' => 0,
            'kept' => 0,
            'out_of_reach' => 0,
        ]);

        $people = Employee::withTrashed()->where('tenant_id', $tenant->id)->get();
        expect($people)->toHaveCount(3)
            ->and($people->every(fn (Employee $e): bool => $e->trashed()))->toBeTrue();

        $deleted = AuditLog::query()->where('action', 'employee.deleted')->get();
        expect($deleted)->toHaveCount(3)
            ->and($deleted->every(fn (AuditLog $e): bool => ($e->payload['via'] ?? null) === 'migration.undo' && ($e->payload['batch'] ?? null) === $batch->public_id))->toBeTrue();

        $entry = AuditLog::query()->where('action', 'migration.undone')->sole();
        expect($entry->tenant_id)->toBe($tenant->id)
            ->and($entry->payload)->toMatchArray([
                'batch' => $batch->public_id,
                'added' => 3,
                'archived' => 3,
                'kept' => 0,
                'out_of_reach' => 0,
                'origin' => 'recorded',
                'exact' => true,
            ]);
        expect($batch->refresh()->undone_at)->not->toBeNull()
            ->and($batch->undo_totals)->toEqual(['archived' => 3, 'pending' => 0]);

        foreach ($people as $person) {
            test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/employees/{$person->public_id}/restore")->assertOk();
        }

        expect(Employee::query()->where('tenant_id', $tenant->id)->count())->toBe(3);
    });

    it('leaves alone a person the list only merged into, and one a create row only linked to', function () {
        [$tenant, $user] = Undo::admin();
        $merged = Staging::employee($tenant, ['name' => 'Abebe Kebede', 'employee_code' => 'E-1']);

        $batch = Staging::stage($tenant, [
            ['name' => 'Abebe Kebede', 'employee_code' => 'E-1'],
            ['name' => 'Dawit Haile', 'employee_code' => 'D-9'],
            ['name' => 'Sara Tesfaye'],
        ]);
        expect(Staging::firstRow($batch)->action)->toBe('merge');

        // Someone adds Dawit between the review and the commit: the row that said
        // "create" finds them and links to them.
        $linked = Staging::employee($tenant, ['name' => 'Dawit Haile', 'employee_code' => 'D-9']);
        Staging::service()->commit($batch, $user);
        $batch->refresh();

        expect($batch->totals)->toMatchArray(['created' => 1, 'merged' => 2])
            ->and(MigrationStagingRow::query()->where('batch_id', $batch->id)->where('action', 'create')->orderBy('id')->pluck('created_employee')->all())
            ->toBe([false, true]);

        test()->getJson(Undo::url($tenant, $batch))->assertOk()
            ->assertJsonPath('archive_count', 1)
            ->assertJsonPath('matched', 2)
            ->assertJsonPath('added', 1)
            ->assertJsonPath('archive.0.name', 'Sara Tesfaye');

        test()->postJson(Undo::url($tenant, $batch), ['confirm' => true])->assertOk()->assertJsonPath('archived', 1);

        expect($merged->refresh()->trashed())->toBeFalse()
            ->and($merged->name)->toBe('Abebe Kebede')
            ->and($linked->refresh()->trashed())->toBeFalse()
            ->and(Undo::who($tenant, 'Sara Tesfaye')->trashed())->toBeTrue();
    });

    it('counts someone a later row linked to, who an earlier row made, as made by the list', function () {
        [$tenant, $user] = Undo::admin();

        $batch = Undo::committed($tenant, $user, [
            ['name' => 'Hana Girma', 'employee_code' => 'H-1'],
            ['name' => 'Hana Girma', 'employee_code' => 'H-1'],
        ]);

        expect($batch->totals)->toMatchArray(['created' => 1, 'merged' => 1]);

        test()->getJson(Undo::url($tenant, $batch))->assertOk()
            ->assertJsonPath('added', 1)
            ->assertJsonPath('matched', 0)
            ->assertJsonPath('archive_count', 1);
    });

    it('names and keeps everyone who has been used since, each with the reason', function () {
        [$tenant, $user] = Undo::admin();
        $names = ['Abebe Kebede' => 'attendance', 'Sara Tesfaye' => 'signed_in', 'Dawit Haile' => 'payroll', 'Hana Girma' => 'leave', 'Yonas Bekele' => 'token', 'Meron Alemu' => null];
        $batch = Undo::committed($tenant, $user, Undo::people(array_keys($names)));

        foreach ($names as $name => $reason) {
            if ($reason !== null) {
                Undo::use($tenant, Undo::who($tenant, $name), $reason);
            }
        }

        $preview = test()->getJson(Undo::url($tenant, $batch))->assertOk()
            ->assertJsonPath('archive_count', 1)
            ->assertJsonPath('keep_count', 5)
            ->assertJsonPath('archive.0.name', 'Meron Alemu')
            ->json();

        $reasons = collect($preview['keep'])->mapWithKeys(fn (array $one): array => [$one['name'] => $one['reasons']])->all();
        expect($reasons)->toBe([
            'Abebe Kebede' => ['attendance'],
            'Dawit Haile' => ['payroll'],
            'Hana Girma' => ['leave'],
            'Sara Tesfaye' => ['signed_in'],
            'Yonas Bekele' => ['signed_in'],
        ]);

        test()->postJson(Undo::url($tenant, $batch), ['confirm' => true])->assertOk()
            ->assertJsonPath('archived', 1)
            ->assertJsonPath('kept', 5);

        expect(Employee::query()->where('tenant_id', $tenant->id)->count())->toBe(5)
            ->and(Undo::who($tenant, 'Meron Alemu')->trashed())->toBeTrue();
    });

    it('treats every other kind of work on a person as use', function (string $reason) {
        [$tenant, $user] = Undo::admin();
        $batch = Undo::committed($tenant, $user, Undo::people(['Abebe Kebede', 'Sara Tesfaye']));
        Undo::use($tenant, Undo::who($tenant, 'Abebe Kebede'), $reason);

        test()->getJson(Undo::url($tenant, $batch))->assertOk()
            ->assertJsonPath('archive_count', 1)
            ->assertJsonPath('keep.0.name', 'Abebe Kebede')
            ->assertJsonPath('keep.0.reasons.0', match ($reason) {
                'leave_balance' => 'leave',
                default => $reason,
            });
    })->with(['leave_balance', 'documents', 'hr_records', 'in_charge', 'assigned']);

    it('counts only a current report or department as being in charge', function () {
        [$tenant, $user] = Undo::admin();
        $batch = Undo::committed($tenant, $user, Undo::people(['Abebe Kebede', 'Sara Tesfaye']));

        $archivedReport = Employee::factory()->create(['tenant_id' => $tenant->id, 'supervisor_id' => Undo::who($tenant, 'Abebe Kebede')->id]);
        $archivedReport->delete();
        Employee::factory()->create(['tenant_id' => $tenant->id, 'supervisor_id' => Undo::who($tenant, 'Sara Tesfaye')->id]);

        $preview = test()->getJson(Undo::url($tenant, $batch))->assertOk()
            ->assertJsonPath('archive_count', 1)
            ->assertJsonPath('archive.0.name', 'Abebe Kebede')
            ->assertJsonPath('keep_count', 1)
            ->assertJsonPath('keep.0.name', 'Sara Tesfaye')
            ->json();

        expect($preview['keep'][0]['reasons'])->toBe(['in_charge']);
    });

    it('archives a person whose login was only given an activation code, and closes the login', function () {
        [$tenant, $admin] = Undo::admin();
        $tenant->update(['settings' => ['login_identifiers' => ['employee_code', 'email']]]);
        $batch = Undo::committed($tenant, $admin, Undo::people(['Abebe Kebede', 'Sara Tesfaye']));
        $person = Undo::who($tenant, 'Abebe Kebede');

        $issued = app(ActivationCodeService::class)->issue($person, $admin, 'national_id', null);
        $account = User::query()->where('employee_id', $person->id)->sole();
        expect($account->status)->toBe('invited')
            ->and($issued['record']->revoked_at)->toBeNull();

        test()->postJson(Undo::url($tenant, $batch), ['confirm' => true])->assertOk()->assertJsonPath('archived', 2);

        expect($person->refresh()->trashed())->toBeTrue()
            ->and($account->refresh()->status)->toBe('inactive')
            ->and($account->trashed())->toBeFalse()
            ->and(AccountActivationCode::query()->where('user_id', $account->id)->whereNotNull('revoked_at')->count())->toBe(1);

        $entry = AuditLog::query()->where('action', 'user.deactivated')->sole();
        expect($entry->payload)->toMatchArray(['reason' => 'migration.undo', 'batch' => $batch->public_id]);
    });

    it('does not count what the list wrote, or what the system writes, as use', function () {
        [$tenant, $user] = Undo::admin();
        $people = Undo::people(['Abebe Kebede', 'Sara Tesfaye']);
        // Each person's bank account and external id differ by position. A random
        // value could land two people on the same id, and the import then reads
        // them as one person, so the undo archives one and not two.
        $rows = array_map(fn (array $person, int $i): array => [
            ...$person,
            'hire_date' => '2026-01-15',
            'bank_name' => 'Commercial Bank',
            'bank_account' => '1000112'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
            'contract_type' => 'permanent',
            'emergency_name' => 'Hana Bekele',
            'emergency_relationship' => 'Sister',
            'emergency_phone' => '0911223344',
            'external_id' => 'HR-'.(101 + $i),
        ], $people, array_keys($people));

        $batch = Undo::committed($tenant, $user, $rows);
        $person = Undo::who($tenant, 'Abebe Kebede');

        LeaveBalance::factory()->create([
            'tenant_id' => $tenant->id,
            'employee_id' => $person->id,
            'leave_type_id' => LeaveType::factory()->create(['tenant_id' => $tenant->id])->id,
        ]);

        expect(DB::table('employee_contracts')->where('employee_id', $person->id)->count())->toBe(1)
            ->and(DB::table('employee_bank_details')->where('employee_id', $person->id)->count())->toBe(1)
            ->and(DB::table('employee_external_identities')->where('employee_id', $person->id)->count())->toBeGreaterThan(0);

        test()->postJson(Undo::url($tenant, $batch), ['confirm' => true])->assertOk()
            ->assertJsonPath('archived', 2)
            ->assertJsonPath('kept', 0);
    });

    it('offers a person again once they are restored, and archives them again', function () {
        [$tenant, $user] = Undo::admin();
        $batch = Undo::committed($tenant, $user, Undo::people(['Abebe Kebede', 'Sara Tesfaye']));
        $url = Undo::url($tenant, $batch);

        test()->postJson($url, ['confirm' => true])->assertOk()->assertJsonPath('archived', 2);

        $person = Undo::who($tenant, 'Sara Tesfaye');
        test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/employees/{$person->public_id}/restore")->assertOk();

        test()->getJson($url)->assertOk()
            ->assertJsonPath('archive_count', 1)
            ->assertJsonPath('archive.0.name', 'Sara Tesfaye')
            ->assertJsonPath('already_archived', 1);

        test()->postJson($url, ['confirm' => true])->assertOk()->assertJsonPath('archived', 1);

        expect(AuditLog::query()->where('action', 'migration.undone')->count())->toBe(2);
    });
});

describe('what the committed screen says afterwards', function () {
    it('stops counting hire dates and sign-ins for people the undo archived', function () {
        [$tenant, $user] = Undo::admin();
        $batch = Undo::committed($tenant, $user, Undo::people(['Abebe Kebede', 'Sara Tesfaye', 'Dawit Haile']));
        $logins = Staging::url($tenant, "batches/{$batch->public_id}/logins");

        test()->getJson($logins)->assertOk()
            ->assertJsonPath('hire_dates_defaulted', 3)
            ->assertJsonCount(3, 'without_login');

        test()->postJson(Undo::url($tenant, $batch), ['confirm' => true])->assertOk()->assertJsonPath('archived', 3);

        test()->getJson($logins)->assertOk()
            ->assertJsonPath('hire_dates_defaulted', 0)
            ->assertJsonCount(0, 'without_login');
    });

    it('still counts the person the undo kept', function () {
        [$tenant, $user] = Undo::admin();
        $batch = Undo::committed($tenant, $user, Undo::people(['Abebe Kebede', 'Sara Tesfaye', 'Dawit Haile']));
        Undo::use($tenant, Undo::who($tenant, 'Abebe Kebede'), 'attendance');

        test()->postJson(Undo::url($tenant, $batch), ['confirm' => true])->assertOk()
            ->assertJsonPath('archived', 2)
            ->assertJsonPath('kept', 1);

        test()->getJson(Staging::url($tenant, "batches/{$batch->public_id}/logins"))->assertOk()
            ->assertJsonPath('hire_dates_defaulted', 1)
            ->assertJsonCount(1, 'without_login');
    });
});

describe('a list committed before the list recorded what it made', function () {
    it('is read from when each person was made, and archives the same people', function () {
        [$tenant, $user] = Undo::admin();
        $batch = Undo::committed($tenant, $user, Undo::people(['Abebe Kebede', 'Sara Tesfaye', 'Dawit Haile']));
        Undo::legacy($batch);

        test()->getJson(Undo::url($tenant, $batch))->assertOk()
            ->assertJsonPath('origin', 'derived')
            ->assertJsonPath('exact', true)
            ->assertJsonPath('added', 3)
            ->assertJsonPath('archive_count', 3);

        test()->postJson(Undo::url($tenant, $batch), ['confirm' => true])->assertOk()->assertJsonPath('archived', 3);

        expect(AuditLog::query()->where('action', 'migration.undone')->sole()->payload)->toMatchArray(['origin' => 'derived', 'exact' => true]);
    });

    it('archives nobody when a person who was only linked cannot be told from one who was made', function () {
        [$tenant, $user] = Undo::admin();
        $batch = Staging::stage($tenant, [
            ['name' => 'Dawit Haile', 'employee_code' => 'D-9'],
            ['name' => 'Sara Tesfaye'],
        ]);
        Staging::employee($tenant, ['name' => 'Dawit Haile', 'employee_code' => 'D-9']);
        Staging::service()->commit($batch, $user);
        Undo::legacy($batch->refresh());

        expect($batch->totals)->toMatchArray(['created' => 1, 'merged' => 1]);

        $preview = test()->getJson(Undo::url($tenant, $batch))->assertOk()
            ->assertJsonPath('origin', 'derived')
            ->assertJsonPath('exact', false)
            ->assertJsonPath('added', 1)
            ->assertJsonPath('archive_count', 0)
            ->assertJsonPath('keep_count', 2)
            ->json();

        expect(collect($preview['keep'])->pluck('reasons')->all())->toBe([['unsure_origin'], ['unsure_origin']]);

        test()->postJson(Undo::url($tenant, $batch), ['confirm' => true])->assertOk()
            ->assertJsonPath('archived', 0)
            ->assertJsonPath('kept', 2);

        expect(Employee::query()->where('tenant_id', $tenant->id)->count())->toBe(2)
            ->and(AuditLog::query()->where('action', 'migration.undone')->count())->toBe(0);
    });

    it('does not take for the list a person who was here before it, or was made some other way', function () {
        [$tenant, $user] = Undo::admin();
        $before = Staging::employee($tenant, ['name' => 'Dawit Haile', 'employee_code' => 'D-9']);
        Undo::olderThanTheList($before);

        $batch = Staging::stage($tenant, [
            ['name' => 'Dawit Haile', 'employee_code' => 'D-9'],
            ['name' => 'Sara Tesfaye'],
            ['name' => 'Hana Girma', 'employee_code' => 'H-1'],
        ]);
        $manual = Staging::employee($tenant, ['name' => 'Hana Girma', 'employee_code' => 'H-1', 'created_via' => 'manual']);
        Staging::service()->commit($batch, $user);
        Undo::legacy($batch->refresh());

        test()->getJson(Undo::url($tenant, $batch))->assertOk()
            ->assertJsonPath('exact', true)
            ->assertJsonPath('archive_count', 1)
            ->assertJsonPath('archive.0.name', 'Sara Tesfaye')
            ->assertJsonPath('matched', 2);

        test()->postJson(Undo::url($tenant, $batch), ['confirm' => true])->assertOk()->assertJsonPath('archived', 1);

        expect($before->refresh()->trashed())->toBeFalse()
            ->and($manual->refresh()->trashed())->toBeFalse();
    });
});

describe('who may undo, and what', function () {
    it('answers 404 for another organisation\'s list, on both calls', function () {
        [$tenant] = Undo::admin();
        $foreign = createTenant();
        $theirs = Undo::batch($foreign, 'committed', ['totals' => ['created' => 1]]);
        test()->actingAs(createUser(['role' => UserRole::TENANT_ADMIN], $tenant));

        test()->getJson(Undo::url($tenant, $theirs))->assertNotFound();
        test()->postJson(Undo::url($tenant, $theirs), ['confirm' => true])->assertNotFound();
    });

    it('is refused for someone without the permission to delete employees, on both calls', function () {
        [$tenant, $admin] = Undo::admin();
        $batch = Undo::committed($tenant, $admin, Undo::people(['Abebe Kebede']));
        test()->actingAs(createUser(['role' => UserRole::HR_ADMIN], $tenant));

        test()->getJson(Undo::url($tenant, $batch))->assertForbidden();
        test()->postJson(Undo::url($tenant, $batch), ['confirm' => true])->assertForbidden();

        expect(Employee::query()->where('tenant_id', $tenant->id)->count())->toBe(1);
    });

    it('archives only the people within reach and counts the rest without naming them', function () {
        [$tenant, $admin] = Undo::admin();
        $finance = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Finance', 'code' => 'FIN']);
        Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Sales', 'code' => 'SAL']);

        $batch = Undo::committed($tenant, $admin, [
            ['name' => 'Abebe Kebede', 'department' => 'Finance'],
            ['name' => 'Sara Tesfaye', 'department' => 'Finance'],
            ['name' => 'Dawit Haile', 'department' => 'Sales'],
            ['name' => 'Hana Girma', 'department' => 'Sales'],
        ]);

        $head = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $finance->id]);
        test()->actingAs(createScopedUser($tenant, $head->id, 'department', ['employee.delete', 'employee.viewAny']));

        $preview = test()->getJson(Undo::url($tenant, $batch))->assertOk()
            ->assertJsonPath('archive_count', 2)
            ->assertJsonPath('out_of_reach', 2)
            ->json();

        expect(array_column($preview['archive'], 'name'))->toBe(['Abebe Kebede', 'Sara Tesfaye'])
            ->and(json_encode($preview))->not->toContain('Dawit Haile')->not->toContain('Hana Girma');

        test()->postJson(Undo::url($tenant, $batch), ['confirm' => true])->assertOk()
            ->assertJsonPath('archived', 2)
            ->assertJsonPath('out_of_reach', 2);

        expect(Undo::who($tenant, 'Dawit Haile')->trashed())->toBeFalse()
            ->and(Undo::who($tenant, 'Abebe Kebede')->trashed())->toBeTrue();
    });

    it('refuses a list that is not committed', function (string $status) {
        [$tenant] = Undo::admin();
        $batch = Undo::batch($tenant, $status);

        test()->getJson(Undo::url($tenant, $batch))->assertStatus(409)
            ->assertJsonPath('detail', 'Only a list that has been added can be undone.');
        test()->postJson(Undo::url($tenant, $batch), ['confirm' => true])->assertStatus(409);
    })->with(['reviewing', 'committing', 'staging']);

    it('needs the confirmation, as the second and explicit call', function () {
        [$tenant, $user] = Undo::admin();
        $batch = Undo::committed($tenant, $user, Undo::people(['Abebe Kebede']));

        test()->postJson(Undo::url($tenant, $batch))->assertStatus(422)->assertJsonValidationErrors('confirm');
        test()->postJson(Undo::url($tenant, $batch), ['confirm' => false])->assertStatus(422);

        expect(Employee::query()->where('tenant_id', $tenant->id)->count())->toBe(1);
    });

    it('gives the undo a rate limit of its own', function () {
        $route = Route::getRoutes()->getByAction('App\Http\Controllers\Api\V1\Onboarding\MigrationController@undo');

        expect($route)->not->toBeNull()
            ->and($route->gatherMiddleware())->toContain('throttle:migration-commit');
    });
});

describe('pressing it more than once, and a slice at a time', function () {
    it('archives nobody and writes nothing the second time', function () {
        [$tenant, $user] = Undo::admin();
        $batch = Undo::committed($tenant, $user, Undo::people(['Abebe Kebede', 'Sara Tesfaye', 'Dawit Haile']));
        Undo::use($tenant, Undo::who($tenant, 'Dawit Haile'), 'attendance');
        $url = Undo::url($tenant, $batch);

        test()->postJson($url, ['confirm' => true])->assertOk()->assertExactJson([
            'done' => true, 'archived' => 2, 'remaining' => 0, 'kept' => 1, 'out_of_reach' => 0,
        ]);
        test()->postJson($url, ['confirm' => true])->assertOk()->assertExactJson([
            'done' => true, 'archived' => 0, 'remaining' => 0, 'kept' => 1, 'out_of_reach' => 0,
        ]);

        expect(AuditLog::query()->where('action', 'migration.undone')->count())->toBe(1)
            ->and(AuditLog::query()->where('action', 'employee.deleted')->count())->toBe(2);
    });

    it('works a chunk at a time, and records the whole once at the end', function () {
        [$tenant, $user] = Undo::admin();
        $batch = Undo::committed($tenant, $user, Undo::people(array_slice(Undo::NAMES, 0, 5)));

        app()->when(ListUndo::class)->needs('$chunk')->give(2);
        app()->when(ListUndo::class)->needs('$sliceSeconds')->give(0.0);
        $url = Undo::url($tenant, $batch);

        test()->postJson($url, ['confirm' => true])->assertOk()
            ->assertJsonPath('done', false)
            ->assertJsonPath('archived', 2)
            ->assertJsonPath('remaining', 3);
        expect(AuditLog::query()->where('action', 'migration.undone')->count())->toBe(0)
            ->and($batch->refresh()->undone_at)->toBeNull();

        test()->postJson($url, ['confirm' => true])->assertOk()
            ->assertJsonPath('done', false)
            ->assertJsonPath('archived', 4)
            ->assertJsonPath('remaining', 1);

        test()->postJson($url, ['confirm' => true])->assertOk()
            ->assertJsonPath('done', true)
            ->assertJsonPath('archived', 5)
            ->assertJsonPath('remaining', 0);

        expect(AuditLog::query()->where('action', 'migration.undone')->sole()->payload)->toMatchArray(['added' => 5, 'archived' => 5])
            ->and(AuditLog::query()->where('action', 'employee.deleted')->count())->toBe(5)
            ->and($batch->refresh()->undo_totals)->toEqual(['archived' => 5, 'pending' => 0]);
    });
});

describe('what it reads', function () {
    it('asks the database the same number of questions for three people as for thirty', function () {
        [$tenant, $user] = Undo::admin();
        $small = Undo::committed($tenant, $user, Undo::people(array_map(fn (int $n): string => "Small Person {$n}", range(1, 3))));
        $large = Undo::committed($tenant, $user, Undo::people(array_map(fn (int $n): string => "Large Person {$n}", range(1, 30))));

        $measure = function (MigrationBatch $batch) use ($tenant): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            test()->getJson(Undo::url($tenant, $batch))->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        // The first request fills what is read once per process (permissions).
        $measure($small);

        expect($measure($large))->toBe($measure($small))
            ->and(test()->getJson(Undo::url($tenant, $large))->json('archive_count'))->toBe(30);
    });

    it('gives the shape the screen reads, and no numeric id', function () {
        [$tenant, $user] = Undo::admin();
        $batch = Undo::committed($tenant, $user, [['name' => 'Abebe Kebede', 'employee_code' => 'E-77']]);
        Undo::use($tenant, Undo::who($tenant, 'Abebe Kebede'), 'payroll');
        $response = test()->getJson(Undo::url($tenant, $batch))->assertOk();

        expect(array_keys($response->json()))->toBe([
            'origin', 'exact', 'added', 'already_archived', 'matched', 'archive_count', 'archive',
            'keep_count', 'keep', 'out_of_reach', 'undone_at',
        ])->and(array_keys($response->json('keep.0')))->toBe(['employee_public_id', 'name', 'employee_code', 'reasons']);

        $response->assertJsonPath('keep.0.employee_code', 'E-77')
            ->assertJsonPath('undone_at', null)
            ->assertJsonMissingPath('keep.0.id')
            ->assertJsonMissingPath('archive.0.id');
    });

    it('shows the first two hundred of each, and counts them all', function () {
        [$tenant, $user] = Undo::admin();
        $batch = Undo::batch($tenant, 'committed', ['created_by' => $user->id, 'committed_at' => now(), 'totals' => ['created' => 205]]);

        foreach (range(1, 205) as $n) {
            $person = Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => sprintf('Person %03d', $n), 'created_via' => 'migration']);
            MigrationStagingRow::query()->create([
                'tenant_id' => $tenant->id,
                'batch_id' => $batch->id,
                'raw' => ['name' => $person->name],
                'action' => 'create',
                'resolved_employee_id' => $person->id,
                'processed_at' => now(),
                'created_employee' => true,
            ]);
        }

        $preview = test()->getJson(Undo::url($tenant, $batch))->assertOk()->json();

        expect($preview['archive_count'])->toBe(205)
            ->and($preview['archive'])->toHaveCount(200)
            ->and($preview['archive'][0]['name'])->toBe('Person 001')
            ->and($preview['archive'][199]['name'])->toBe('Person 200');
    });
});

describe('the lists it names', function () {
    it('lists the added lists beside the unfinished ones, not among them', function () {
        [$tenant, $user] = Undo::admin();
        $first = Undo::batch($tenant, 'committed', ['created_by' => $user->id, 'committed_at' => now()->subDays(2), 'totals' => ['created' => 7, 'merged' => 1]]);
        $second = Undo::batch($tenant, 'committed', ['committed_at' => now()->subDay(), 'undone_at' => now(), 'options' => ['mode' => 'complete']]);
        $open = Undo::batch($tenant, 'reviewing');

        $foreign = createTenant();
        Undo::batch($foreign, 'committed', ['committed_at' => now()]);
        test()->actingAs($user);

        $response = test()->getJson(Staging::url($tenant, 'batches'))->assertOk();

        expect(array_column($response->json('data'), 'public_id'))->toBe([$open->public_id])
            ->and(array_column($response->json('added'), 'public_id'))->toBe([$second->public_id, $first->public_id]);

        $added = $response->json('added');
        expect(array_keys($added[1]))->toBe(['public_id', 'mode', 'source_type', 'rows', 'created', 'committed_at', 'created_by_name', 'mine', 'undone_at'])
            ->and($added[1]['created'])->toBe(7)
            ->and($added[1]['mine'])->toBeTrue()
            ->and($added[1]['undone_at'])->toBeNull()
            ->and($added[0]['created'])->toBe(0)
            ->and($added[0]['mode'])->toBe('complete')
            ->and($added[0]['undone_at'])->not->toBeNull();
    });

    it('says whether a list was undone, and how many it archived, in its own payload', function () {
        [$tenant, $user] = Undo::admin();
        $batch = Undo::committed($tenant, $user, Undo::people(['Abebe Kebede']));

        test()->getJson(Staging::url($tenant, "batches/{$batch->public_id}"))->assertOk()
            ->assertJsonPath('undone_at', null)
            ->assertJsonPath('undo_archived', 0);

        test()->postJson(Undo::url($tenant, $batch), ['confirm' => true])->assertOk();

        test()->getJson(Staging::url($tenant, "batches/{$batch->public_id}"))->assertOk()
            ->assertJsonPath('undo_archived', 1)
            ->assertJsonPath('undone_at', $batch->refresh()->undone_at->format('c'));
    });
});

describe('recording what a commit does', function () {
    it('marks the row that made a person and the row that only linked to one', function () {
        [$tenant, $user] = Undo::admin();
        $batch = Staging::stage($tenant, [
            ['name' => 'Dawit Haile', 'employee_code' => 'D-9'],
            ['name' => 'Sara Tesfaye'],
            ['name' => 'Selam Mengistu', 'employee_code' => 'S-1'],
        ]);
        Staging::employee($tenant, ['name' => 'Selam Mengistu', 'employee_code' => 'S-1']);
        Staging::service()->commit($batch, $user);

        $rows = MigrationStagingRow::query()->where('batch_id', $batch->id)->orderBy('id')->get();
        $made = $rows->mapWithKeys(fn (MigrationStagingRow $row): array => [$row->display_name => $row->created_employee])->all();

        expect($made)->toBe(['Dawit Haile' => true, 'Sara Tesfaye' => true, 'Selam Mengistu' => false]);
    });

    it('leaves a merge row, and a row nobody processed, unmarked', function () {
        [$tenant, $user] = Undo::admin();
        Staging::employee($tenant, ['name' => 'Abebe Kebede', 'employee_code' => 'E-1']);
        $batch = Staging::stage($tenant, [
            ['name' => 'Abebe Kebede', 'employee_code' => 'E-1'],
            ['name' => 'Sara Tesfaye'],
        ]);
        $skipped = $batch->rows()->orderByDesc('id')->first();
        $skipped->update(['action' => 'skip']);
        Staging::service()->commit($batch, $user);

        expect(MigrationStagingRow::query()->where('batch_id', $batch->id)->orderBy('id')->pluck('created_employee')->all())->toBe([null, null]);
    });
});

/*
 * The commonest correction: a wrong file is added, its additions are undone,
 * and the corrected file is added. The people the undo archived still hold
 * their employee numbers (the unique index keeps soft-deleted rows), and the
 * corrected file reuses them.
 */
describe('adding the corrected list after an undo', function () {
    // People used since cannot be brought back from a list (RestoreFromListTest
    // is the unused case, which is brought back), so their rows wait.
    it('commits the list with the rows that reuse a used archived person\'s number left for review, and never throws', function () {
        [$tenant, $user] = Undo::admin();
        $first = Undo::committed($tenant, $user, [
            ['name' => 'Abebe Kebede', 'employee_code' => 'E-1'],
            ['name' => 'Sara Tesfaye', 'employee_code' => 'E-2'],
            ['name' => 'Dawit Haile', 'employee_code' => 'E-3'],
        ]);
        test()->postJson(Undo::url($tenant, $first), ['confirm' => true])->assertOk()->assertJsonPath('archived', 3);
        foreach (['Abebe Kebede', 'Sara Tesfaye', 'Dawit Haile'] as $name) {
            Undo::use($tenant, Undo::who($tenant, $name), 'attendance');
        }

        $corrected = Staging::stage($tenant, [
            ['name' => 'Abebe Kebede', 'employee_code' => 'E-1'],
            ['name' => 'Sara Tesfaye', 'employee_code' => 'E-2'],
            ['name' => 'Dawit Haile', 'employee_code' => 'E-3'],
            ['name' => 'Hana Girma', 'employee_code' => 'E-4'],
        ]);

        $rows = test()->getJson(Staging::url($tenant, "batches/{$corrected->public_id}"))->assertOk()->json('rows');
        expect(array_column($rows, 'action'))->toBe(['defer', 'defer', 'defer', 'create']);

        // The reviewer cannot say "add as new" to a number an archived person holds.
        foreach (array_slice($rows, 0, 3) as $row) {
            expect($row['warnings'][0]['code'])->toBe('number_held')
                ->and($row['warnings'][0]['message'])->toContain('archived');

            test()->patchJson(Staging::url($tenant, "rows/{$row['public_id']}"), ['action' => 'create'])->assertStatus(422);
        }

        test()->postJson(Staging::url($tenant, "batches/{$corrected->public_id}/commit"))->assertOk()
            ->assertJsonPath('status', 'committed')
            ->assertJsonPath('totals.created', 1)
            ->assertJsonPath('totals.deferred', 3);

        expect(Employee::query()->where('tenant_id', $tenant->id)->pluck('employee_code')->all())->toBe(['E-4'])
            ->and(Employee::withTrashed()->where('tenant_id', $tenant->id)->count())->toBe(4);
    });

    it('does not let the commit fail on a row forced to add as new, however it got that choice', function () {
        [$tenant, $user] = Undo::admin();
        $first = Undo::committed($tenant, $user, [['name' => 'Abebe Kebede', 'employee_code' => 'E-1']]);
        test()->postJson(Undo::url($tenant, $first), ['confirm' => true])->assertOk();

        $corrected = Staging::stage($tenant, [['name' => 'Abebe Kebede', 'employee_code' => 'E-1']]);
        $corrected->rows()->update(['action' => 'create']);

        test()->postJson(Staging::url($tenant, "batches/{$corrected->public_id}/commit"))->assertOk()
            ->assertJsonPath('status', 'committed')
            ->assertJsonPath('totals.created', 0)
            ->assertJsonPath('totals.deferred', 1);
    });
});
