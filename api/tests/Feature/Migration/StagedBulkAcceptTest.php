<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\MigrationBatch;
use App\Models\MigrationStagingRow;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Migration\StagedChanges;
use App\Services\Migration\WorkforceMigrationService;
use Tests\Support\StagingFixtures as Staging;

/**
 * A corrected file shows each matched row's differences, and a difference was
 * written only where a reviewer accepted it on that row: three hundred
 * people, three hundred rows of clicks. One action accepts the named fields
 * for many rows, and is built so that a file whose numbers are shifted by a
 * line cannot rename three hundred people into each other.
 *
 * A slice here is two rows and has no time, so every request looks at one chunk.
 */
beforeEach(function () {
    app()->when(WorkforceMigrationService::class)->needs('$chunk')->give(2);
    app()->when(WorkforceMigrationService::class)->needs('$sliceSeconds')->give(0.0);
});

/** The reviewer's request, sent until the answer says it is done; the answers added up. */
function acceptAll(Tenant $tenant, MigrationBatch $batch, array $fields): array
{
    $sum = ['requests' => 0, 'rows' => 0, 'by_field' => [], 'out_of_reach' => 0, 'pay_refused' => 0, 'held' => 0];
    $cursor = 0;

    do {
        $answer = test()->postJson(Staging::url($tenant, "batches/{$batch->public_id}/accept-differences"), ['fields' => $fields, 'cursor' => $cursor])
            ->assertOk()
            ->json();

        $sum['requests']++;
        foreach (['rows', 'out_of_reach', 'pay_refused', 'held'] as $key) {
            $sum[$key] += $answer[$key];
        }
        foreach ($answer['by_field'] as $field => $count) {
            $sum['by_field'][$field] = ($sum['by_field'][$field] ?? 0) + $count;
        }
        $cursor = $answer['cursor'];
    } while (! $answer['done'] && $sum['requests'] < 20);

    ksort($sum['by_field']);

    return $sum;
}

/**
 * Two people, and a list that matches each by number and name and brings
 * another phone, another email and a longer name.
 *
 * @return array{0: Tenant, 1: Employee, 2: Employee, 3: MigrationBatch, 4: User}
 */
function correctedList(): array
{
    [$tenant, $user] = Staging::organisation();
    $abebe = Staging::employee($tenant, ['phone' => '+251911000001', 'email' => 'abebe@example.et']);
    $sara = Staging::employee($tenant, ['name' => 'Sara Tesfaye', 'employee_code' => 'E-2', 'phone' => '+251911000002', 'email' => 'sara@example.et']);

    $batch = Staging::stage($tenant, [
        ['name' => 'Abebe Kebede Tesfaye', 'employee_code' => 'E-1', 'phone' => '0922000001', 'email' => 'a.kebede@example.et'],
        ['name' => 'Sara Tesfaye Alemu', 'employee_code' => 'E-2', 'phone' => '0922000002', 'email' => 's.tesfaye@example.et'],
    ]);

    return [$tenant, $abebe, $sara, $batch, $user];
}

/** @return array<string, bool> field => accepted, as stored on the row */
function acceptedOn(MigrationBatch $batch, int $position = 0): array
{
    $row = $batch->rows()->orderBy('id')->skip($position)->firstOrFail();

    return collect($row->changes)->pluck('accepted', 'field')->all();
}

describe('only the fields named are accepted', function () {
    it('accepts the named field on every row, and nothing else', function () {
        [$tenant, , , $batch] = correctedList();

        $sum = acceptAll($tenant, $batch, ['phone']);

        expect($sum['rows'])->toBe(2)
            ->and($sum['by_field'])->toBe(['phone' => 2])
            ->and($sum['held'])->toBe(0)
            ->and(acceptedOn($batch, 0))->toMatchArray(['phone' => true, 'email' => false, 'name' => false])
            ->and(acceptedOn($batch, 1))->toMatchArray(['phone' => true, 'email' => false, 'name' => false]);
    });

    it('accepts an identity field only when it is named', function () {
        [$tenant, , , $batch] = correctedList();

        acceptAll($tenant, $batch, ['phone', 'email']);
        expect(acceptedOn($batch)['name'])->toBeFalse();

        $sum = acceptAll($tenant, $batch, ['name']);

        // Added to what was accepted, not instead of it.
        expect($sum['by_field'])->toBe(['name' => 2])
            ->and(acceptedOn($batch))->toMatchArray(['phone' => true, 'email' => true, 'name' => true]);
    });

    it('refuses a request that names nothing, or a field a reviewer may not accept', function () {
        [$tenant, , , $batch] = correctedList();
        $url = Staging::url($tenant, "batches/{$batch->public_id}/accept-differences");

        test()->postJson($url, [])->assertUnprocessable();
        test()->postJson($url, ['fields' => []])->assertUnprocessable();
        test()->postJson($url, ['fields' => ['position']])->assertUnprocessable();
        test()->postJson($url, ['fields' => ['phone', 'phone']])->assertUnprocessable();
        test()->postJson($url, ['fields' => ['phone'], 'cursor' => -1])->assertUnprocessable();

        expect(acceptedOn($batch))->each->toBeFalse();
    });

    it('refuses a department or a salary on a list that completes people', function () {
        [$tenant] = Staging::organisation();
        Staging::employee($tenant, ['salary_cents' => 100000]);
        $batch = Staging::stage($tenant, [['name' => 'Abebe Kebede', 'employee_code' => 'E-1', 'salary_cents' => 200000]], 'complete');

        test()->postJson(Staging::url($tenant, "batches/{$batch->public_id}/accept-differences"), ['fields' => ['salary_cents']])
            ->assertUnprocessable();
    });

    it('never accepts a difference somebody else holds', function () {
        [$tenant] = Staging::organisation();
        $abebe = Staging::employee($tenant, ['email' => 'abebe@example.et']);
        Staging::employee($tenant, ['name' => 'Sara Tesfaye', 'employee_code' => 'E-2', 'badge_number' => '500']);

        $batch = Staging::stage($tenant, [['name' => 'Abebe Kebede', 'email' => 'abebe@example.et', 'badge_number' => '500']]);
        Staging::service()->setAction(Staging::firstRow($batch), MigrationStagingRow::ACTION_MERGE, $abebe);

        $sum = acceptAll($tenant, $batch, ['badge_number']);

        expect(Staging::change(Staging::firstRow($batch)->changes, 'badge_number'))->toMatchArray(['taken' => true, 'accepted' => false])
            ->and($sum['rows'])->toBe(0);
    });

    it('never accepts a difference that is not a conflict', function () {
        [$tenant] = Staging::organisation();
        Staging::employee($tenant, ['phone' => null]);
        $batch = Staging::stage($tenant, [['name' => 'Abebe Kebede', 'employee_code' => 'E-1', 'phone' => '0922000001']]);

        $sum = acceptAll($tenant, $batch, ['phone']);

        // A blank is filled at commit without anyone accepting it.
        expect(Staging::change(Staging::firstRow($batch)->changes, 'phone'))->toMatchArray(['kind' => StagedChanges::FILL, 'accepted' => false])
            ->and($sum['rows'])->toBe(0);
    });
});

describe('a row that may be someone else is left for the reviewer', function () {
    it('holds a row set to merge into a person whose name shares no word with it', function () {
        [$tenant] = Staging::organisation();
        $abebe = Staging::employee($tenant, ['email' => 'abebe@example.et', 'phone' => '+251911000001']);
        $batch = Staging::stage($tenant, [
            ['name' => 'Dawit Haile', 'email' => 'abebe@example.et', 'phone' => '0922000001'],
            ['name' => 'Abebe Kebede', 'employee_code' => 'E-1', 'phone' => '0922000001'],
        ]);

        $first = $batch->rows()->orderBy('id')->firstOrFail();
        Staging::service()->setAction($first, MigrationStagingRow::ACTION_MERGE, $abebe);

        $warnings = collect($first->refresh()->raw['warnings'] ?? [])->pluck('code')->all();
        expect($warnings)->toBe(['maybe_other_person']);

        $sum = acceptAll($tenant, $batch, ['phone']);

        expect($sum['held'])->toBe(1)
            ->and(Staging::change($first->refresh()->changes, 'phone')['accepted'])->toBeFalse();
    });

    it('is decided on its own row, and the warning goes when the person it names changes', function () {
        [$tenant] = Staging::organisation();
        $abebe = Staging::employee($tenant, ['email' => 'abebe@example.et']);
        $dawit = Staging::employee($tenant, ['name' => 'Dawit Haile', 'employee_code' => 'E-9']);
        $batch = Staging::stage($tenant, [['name' => 'Dawit Haile', 'email' => 'abebe@example.et', 'phone' => '0922000001']]);
        $row = Staging::firstRow($batch);

        Staging::service()->setAction($row, MigrationStagingRow::ACTION_MERGE, $abebe);
        expect(collect($row->refresh()->raw['warnings'] ?? [])->pluck('code')->all())->toBe(['maybe_other_person']);

        // The reviewer picks the person the row is: the warning is recomputed.
        Staging::service()->setAction($row, MigrationStagingRow::ACTION_MERGE, $dawit);
        expect($row->refresh()->raw['warnings'] ?? [])->toBe([]);

        // And a row no longer set to merge carries none.
        Staging::service()->setAction($row, MigrationStagingRow::ACTION_MERGE, $abebe);
        Staging::service()->setAction($row, MigrationStagingRow::ACTION_SKIP);
        expect($row->refresh()->raw['warnings'] ?? [])->toBe([]);
    });

    it('shows the warning on its row, in the viewer\'s language', function () {
        [$tenant] = Staging::organisation();
        $abebe = Staging::employee($tenant, ['email' => 'abebe@example.et']);
        $batch = Staging::stage($tenant, [['name' => 'Dawit Haile', 'email' => 'abebe@example.et']]);
        Staging::service()->setAction(Staging::firstRow($batch), MigrationStagingRow::ACTION_MERGE, $abebe);

        $shown = test()->getJson(Staging::url($tenant, "batches/{$batch->public_id}"))->assertOk()->json('rows.0.warnings');

        expect($shown)->toHaveCount(1)
            ->and($shown[0]['code'])->toBe('maybe_other_person')
            ->and($shown[0]['message'])->toBe(__('onboarding.migration.maybe_other_person'));
    });
});

describe('staging says when a merge may be someone else', function () {
    /** @return list<string> */
    function warningCodes(MigrationBatch $batch): array
    {
        return collect(Staging::firstRow($batch)->raw['warnings'] ?? [])->pluck('code')->all();
    }

    it('flags a row merged by number into a person whose name shares nothing with it', function () {
        [$tenant] = Staging::organisation();
        Staging::employee($tenant);

        $batch = Staging::stage($tenant, [['name' => 'Dawit Haile', 'employee_code' => 'E-1', 'phone' => '0922000001']]);

        expect(Staging::firstRow($batch)->action)->toBe(MigrationStagingRow::ACTION_DEFER)
            ->and(Staging::firstRow($batch)->resolved_employee_id)->not->toBeNull()
            ->and(warningCodes($batch))->toBe(['maybe_other_person']);
    });

    it('waits for a reviewer: committing the list leaves the matched person untouched', function () {
        [$tenant, $user] = Staging::organisation();
        $abebe = Staging::employee($tenant, ['phone' => null]);
        $batch = Staging::stage($tenant, [['name' => 'Dawit Haile', 'employee_code' => 'E-1', 'phone' => '0922000001']]);

        $totals = Staging::service()->commit($batch, $user);

        expect($totals['deferred'])->toBe(1)
            ->and($totals['merged'])->toBe(0)
            ->and($abebe->refresh()->phone)->toBeNull()
            ->and($abebe->name)->toBe('Abebe Kebede');
    });

    it('fills the blanks once the reviewer chooses merge', function () {
        [$tenant, $user] = Staging::organisation();
        $abebe = Staging::employee($tenant, ['phone' => null]);
        $batch = Staging::stage($tenant, [['name' => 'Dawit Haile', 'employee_code' => 'E-1', 'phone' => '0922000001']]);

        Staging::service()->setAction(Staging::firstRow($batch), MigrationStagingRow::ACTION_MERGE);
        expect(warningCodes($batch))->toBe(['maybe_other_person']);
        Staging::service()->commit($batch->refresh(), $user);

        expect($abebe->refresh()->phone)->not->toBeNull();
    });

    it('keeps the reason on a deferred row and drops it for skip or create', function () {
        [$tenant] = Staging::organisation();
        Staging::employee($tenant);
        $batch = Staging::stage($tenant, [['name' => 'Dawit Haile', 'employee_code' => 'E-1']]);
        $row = Staging::firstRow($batch);

        Staging::service()->setAction($row, MigrationStagingRow::ACTION_DEFER);
        expect(warningCodes($batch))->toBe(['maybe_other_person']);

        Staging::service()->setAction($row, MigrationStagingRow::ACTION_MERGE);
        expect(warningCodes($batch))->toBe(['maybe_other_person']);

        Staging::service()->setAction($row, MigrationStagingRow::ACTION_SKIP);
        expect(warningCodes($batch))->toBe([]);

        Staging::service()->setAction($row, MigrationStagingRow::ACTION_DEFER);
        Staging::service()->setAction($row, MigrationStagingRow::ACTION_CREATE);
        expect(warningCodes($batch))->toBe([]);
    });

    it('waits for a reviewer on a list that completes people too', function () {
        [$tenant] = Staging::organisation();
        Staging::employee($tenant);
        $batch = Staging::stage($tenant, [['name' => 'Dawit Haile', 'employee_code' => 'E-1']], MigrationBatch::MODE_COMPLETE);

        expect(Staging::firstRow($batch)->action)->toBe(MigrationStagingRow::ACTION_DEFER)
            ->and(warningCodes($batch))->toBe(['maybe_other_person']);
    });

    it('does not flag a person ETHR named with a placeholder', function () {
        [$tenant] = Staging::organisation();
        Staging::employee($tenant, ['name' => 'Device user E-1', 'name_am' => null]);
        $batch = Staging::stage($tenant, [['name' => 'Dawit Haile', 'employee_code' => 'E-1']]);

        expect(Staging::firstRow($batch)->action)->toBe(MigrationStagingRow::ACTION_MERGE)
            ->and(warningCodes($batch))->toBe([]);
    });

    it('still flags a placeholder-named person whose Amharic name shares nothing', function () {
        [$tenant] = Staging::organisation();
        Staging::employee($tenant, ['name' => 'Device user E-1', 'name_am' => 'አበበ ከበደ']);
        $batch = Staging::stage($tenant, [['name' => 'Dawit Haile', 'name_am' => 'ዳዊት ኃይሌ', 'employee_code' => 'E-1']]);

        expect(Staging::firstRow($batch)->action)->toBe(MigrationStagingRow::ACTION_DEFER)
            ->and(warningCodes($batch))->toBe(['maybe_other_person']);
    });

    it('still merges a number match whose name agrees', function () {
        [$tenant] = Staging::organisation();
        Staging::employee($tenant);
        $batch = Staging::stage($tenant, [['name' => 'Abebe Kebede', 'employee_code' => 'E-1']]);

        expect(Staging::firstRow($batch)->action)->toBe(MigrationStagingRow::ACTION_MERGE)
            ->and(warningCodes($batch))->toBe([]);
    });

    it('does not tell the reviewer the number is held by another record', function () {
        [$tenant] = Staging::organisation();
        Staging::employee($tenant);
        $batch = Staging::stage($tenant, [['name' => 'Dawit Haile', 'employee_code' => 'E-1']]);

        expect(warningCodes($batch))->not->toContain('number_held');
    });

    it('does not flag a row that shares a word, or an initial, with the person', function () {
        [$tenant] = Staging::organisation();
        Staging::employee($tenant);

        foreach (['Abebe', 'Abebe K.', 'Kebede Abebe Tesfaye'] as $name) {
            $batch = Staging::stage($tenant, [['name' => $name, 'employee_code' => 'E-1']]);

            expect(warningCodes($batch))->toBe([], $name);
        }
    });

    it('does not flag a Latin name against a record that has only an Amharic one', function () {
        [$tenant] = Staging::organisation();
        Staging::employee($tenant, ['name' => 'አበበ ከበደ', 'name_am' => null]);

        $batch = Staging::stage($tenant, [['name' => 'Dawit Haile', 'employee_code' => 'E-1']]);

        expect(Staging::firstRow($batch)->action)->toBe(MigrationStagingRow::ACTION_MERGE)
            ->and(warningCodes($batch))->toBe([]);
    });

    it('compares the Amharic name with the Amharic name', function () {
        [$tenant] = Staging::organisation();
        Staging::employee($tenant, ['name_am' => 'አበበ ከበደ']);

        $other = Staging::stage($tenant, [['name' => 'Abebe Kebede', 'name_am' => 'ዳዊት ኃይሌ', 'employee_code' => 'E-1']]);
        $same = Staging::stage($tenant, [['name' => 'Abebe Kebede', 'name_am' => 'አበበ ከበደ', 'employee_code' => 'E-1']]);

        // The Latin names agree, so the Amharic one alone does not clear or condemn the row.
        expect(warningCodes($other))->toBe([])
            ->and(warningCodes($same))->toBe([]);

        Staging::employee($tenant, ['name' => 'Sara Tesfaye', 'employee_code' => 'E-5', 'name_am' => 'አበበ ከበደ']);
        $amharicOnly = Staging::stage($tenant, [['name_am' => 'ዳዊት ኃይሌ', 'employee_code' => 'E-5']]);

        expect(warningCodes($amharicOnly))->toBe(['maybe_other_person'])
            ->and(Staging::firstRow($amharicOnly)->action)->toBe(MigrationStagingRow::ACTION_DEFER);
    });

    it('keeps the other warnings a row carries', function () {
        [$tenant] = Staging::organisation();
        Staging::employee($tenant);
        $batch = Staging::stage($tenant, [['name' => 'Dawit Haile', 'employee_code' => 'E-1', 'warnings' => [['code' => 'something_else', 'message' => 'Kept.']]]]);

        expect(warningCodes($batch))->toBe(['something_else', 'maybe_other_person']);
    });
});

describe('who may accept, and for whom', function () {
    it('counts a person the reviewer may not edit and changes nothing for them', function () {
        [$tenant, $abebe, , $batch] = correctedList();
        $own = createScopedUser($tenant, $abebe->id, 'self', ['employee.create', 'employee.viewAny', 'employee.view', 'employee.update']);
        test()->actingAs($own);

        $sum = acceptAll($tenant, $batch, ['phone']);

        // Their own record, and nobody else's.
        expect($sum['rows'])->toBe(1)
            ->and($sum['out_of_reach'])->toBe(1)
            ->and(acceptedOn($batch, 0)['phone'])->toBeTrue()
            ->and(acceptedOn($batch, 1)['phone'])->toBeFalse();
    });

    it('accepts for nobody when the reviewer may edit no one in the list', function () {
        [$tenant, , , $batch] = correctedList();
        test()->actingAs(createScopedUser($tenant, null, 'self', ['employee.create', 'employee.viewAny', 'employee.update']));

        $sum = acceptAll($tenant, $batch, ['phone']);

        expect($sum['rows'])->toBe(0)
            ->and($sum['out_of_reach'])->toBe(2)
            ->and(acceptedOn($batch))->each->toBeFalse();
    });

    it('drops salary and TIN without the pay permission, and keeps the rest of the row', function () {
        [$tenant] = Staging::organisation();
        Staging::employee($tenant, ['tin' => '0011223344', 'salary_cents' => 100000, 'phone' => '+251911000001']);
        $batch = Staging::stage($tenant, [[
            'name' => 'Abebe Kebede', 'employee_code' => 'E-1', 'phone' => '0922000001', 'tin' => '0099887766', 'salary_cents' => 200000,
        ]]);
        test()->actingAs(createScopedUser($tenant, null, 'all', ['employee.create', 'employee.viewAny', 'employee.view', 'employee.update']));

        $sum = acceptAll($tenant, $batch, ['phone', 'tin', 'salary_cents']);

        expect($sum['rows'])->toBe(1)
            ->and($sum['by_field'])->toBe(['phone' => 1])
            ->and($sum['pay_refused'])->toBe(1)
            ->and(acceptedOn($batch))->toMatchArray(['phone' => true, 'tin' => false, 'salary_cents' => false]);
    });

    it('accepts salary and TIN for someone who holds the pay permission', function () {
        [$tenant] = Staging::organisation();
        Staging::employee($tenant, ['tin' => '0011223344', 'salary_cents' => 100000]);
        $batch = Staging::stage($tenant, [['name' => 'Abebe Kebede', 'employee_code' => 'E-1', 'tin' => '0099887766', 'salary_cents' => 200000]]);
        test()->actingAs(createScopedUser($tenant, null, 'all', ['employee.create', 'employee.viewAny', 'employee.view', 'employee.update', 'employee.updateFinancial']));

        $sum = acceptAll($tenant, $batch, ['tin', 'salary_cents']);

        expect($sum['by_field'])->toBe(['salary_cents' => 1, 'tin' => 1])
            ->and($sum['pay_refused'])->toBe(0);
    });

    it('is for people who add employees', function () {
        [$tenant, , , $batch] = correctedList();
        test()->actingAs(createScopedUser($tenant, null, 'all', ['employee.viewAny', 'employee.update']));

        test()->postJson(Staging::url($tenant, "batches/{$batch->public_id}/accept-differences"), ['fields' => ['phone']])->assertForbidden();
        expect(acceptedOn($batch))->each->toBeFalse();
    });
});

describe('the commit still decides what is written', function () {
    it('writes the accepted field, and refuses a value that moved after the accept', function () {
        [$tenant, $abebe, $sara, $batch, $user] = correctedList();

        acceptAll($tenant, $batch, ['phone']);

        // Someone corrects Sara's phone by hand after the reviewer said yes.
        $sara->forceFill(['phone' => '+251933000009'])->save();

        $totals = Staging::service()->commit($batch->refresh(), $user);

        expect($abebe->refresh()->phone)->toBe('+251922000001')
            ->and($abebe->email)->toBe('abebe@example.et')
            ->and($abebe->name)->toBe('Abebe Kebede')
            ->and($sara->refresh()->phone)->toBe('+251933000009')
            ->and($totals['updated'])->toBe(1);
    });
});

describe('a long list is accepted a slice at a time', function () {
    it('walks the rows in order from the cursor and says when it is done', function () {
        [$tenant] = Staging::organisation();
        $rows = [];
        foreach (['Abebe Kebede', 'Sara Tesfaye', 'Dawit Haile', 'Hana Girma', 'Yonas Bekele'] as $i => $name) {
            $n = $i + 1;
            Staging::employee($tenant, ['name' => $name, 'employee_code' => "E-{$n}", 'phone' => "+25191100000{$n}"]);
            $rows[] = ['name' => $name, 'employee_code' => "E-{$n}", 'phone' => "09220000{$n}0"];
        }
        $batch = Staging::stage($tenant, $rows);
        $url = Staging::url($tenant, "batches/{$batch->public_id}/accept-differences");
        $ids = $batch->rows()->orderBy('id')->pluck('id')->all();

        $first = test()->postJson($url, ['fields' => ['phone']])->assertOk()->json();
        expect($first)->toMatchArray(['done' => false, 'cursor' => $ids[1], 'rows' => 2]);

        $second = test()->postJson($url, ['fields' => ['phone'], 'cursor' => $first['cursor']])->assertOk()->json();
        expect($second)->toMatchArray(['done' => false, 'cursor' => $ids[3], 'rows' => 2]);

        $third = test()->postJson($url, ['fields' => ['phone'], 'cursor' => $second['cursor']])->assertOk()->json();
        expect($third)->toMatchArray(['done' => true, 'cursor' => $ids[4], 'rows' => 1])
            ->and($batch->rows()->get()->every(fn ($row) => Staging::change($row->changes, 'phone')['accepted'] === true))->toBeTrue();

        // Asked again, it has nothing left to do.
        $again = test()->postJson($url, ['fields' => ['phone']])->assertOk();
        expect(array_sum(array_column([$again->json()], 'rows')))->toBe(0);
    });

    it('records each slice that accepted something, with its fields and row count', function () {
        [$tenant, , , $batch] = correctedList();

        acceptAll($tenant, $batch, ['phone', 'email']);

        $entries = AuditLog::query()->where('action', 'migration.differences_accepted')->get();
        expect($entries)->toHaveCount(1);

        $payload = $entries[0]->payload;
        $expected = ['fields' => ['email', 'phone'], 'rows' => 2];
        ksort($payload);
        ksort($expected);

        expect($payload)->toBe($expected)
            ->and($entries[0]->auditable_id)->toBe($batch->id);
    });

    it('records nothing for a slice that accepted nothing', function () {
        [$tenant, , , $batch] = correctedList();

        acceptAll($tenant, $batch, ['gender']);

        expect(AuditLog::query()->where('action', 'migration.differences_accepted')->count())->toBe(0);
    });
});

describe('the list must be in review, and be the organisation\'s own', function () {
    it('answers 409 for a list still being matched, being added, or added', function () {
        [$tenant, , , $batch] = correctedList();
        $url = Staging::url($tenant, "batches/{$batch->public_id}/accept-differences");

        foreach (['staging', 'committing', 'committed'] as $status) {
            $batch->update(['status' => $status]);
            test()->postJson($url, ['fields' => ['phone']])->assertStatus(409);
        }

        expect(acceptedOn($batch))->each->toBeFalse();
    });

    it('answers 404 for another organisation\'s list', function () {
        [, , , $batch] = correctedList();

        app()->forgetScopedInstances();
        [$other] = Staging::organisation();

        test()->postJson(Staging::url($other, "batches/{$batch->public_id}/accept-differences"), ['fields' => ['phone']])->assertNotFound();

        $stored = MigrationStagingRow::withoutGlobalScopes()->where('batch_id', $batch->id)->get();
        expect($stored)->toHaveCount(2)
            ->and($stored->every(fn (MigrationStagingRow $row): bool => collect($row->changes)->every(fn (array $change): bool => $change['accepted'] === false)))->toBeTrue();
    });
});
