<?php

declare(strict_types=1);

use App\Models\MigrationStagingRow;
use Illuminate\Support\Facades\DB;
use Tests\Support\StagingFixtures as Staging;

/**
 * A staged row is a person's details as a file or a device gave them, and it
 * is kept for as long as the list is. It sat in a JSON column in clear text
 * (One employee identity, PR 5).
 *
 * This runs on SQLite with the suite and on MariaDB with `gates.sh mysql`,
 * which is where it matters: MariaDB's JSON column is LONGTEXT with a
 * validity check, and ciphertext fails that check unless the migration
 * changed the column.
 */
it('stores what a list said about a person encrypted', function () {
    [$tenant] = Staging::organisation();
    Staging::employee($tenant, ['phone' => '+251911000001']);

    $batch = Staging::stage($tenant, [[
        'name' => 'Abebe Kebede',
        'employee_code' => 'E-1',
        'phone' => '0922000002',
        'national_id' => '1111222233334444',
    ]]);

    $stored = DB::table('migration_staging_rows')->where('batch_id', $batch->id)->first();

    foreach (['raw', 'changes'] as $column) {
        expect($stored->{$column})
            ->not->toContain('Abebe')
            ->not->toContain('922000002')
            ->not->toContain('911000001')
            ->not->toContain('1111222233334444')
            ->not->toStartWith('{')
            ->not->toStartWith('[');
    }

    $row = Staging::firstRow($batch);
    expect($row->raw['national_id'])->toBe('1111222233334444')
        ->and(Staging::change($row->changes, 'phone'))->toMatchArray(['current' => '+251911000001', 'incoming' => '+251922000002']);
});

it('still reads a row staged before the column was encrypted', function () {
    [$tenant] = Staging::organisation();
    $batch = Staging::stage($tenant, [['name' => 'Abebe Kebede']]);
    $row = Staging::firstRow($batch);

    DB::table('migration_staging_rows')->where('id', $row->id)->update([
        'raw' => json_encode(['name' => 'Sara Tesfaye', 'employee_code' => 'E-9']),
    ]);

    $read = MigrationStagingRow::query()->findOrFail($row->id);
    expect($read->raw)->toBe(['name' => 'Sara Tesfaye', 'employee_code' => 'E-9']);

    // And writes it back encrypted the next time it is saved.
    $read->update(['raw' => [...$read->raw, 'phone' => '0911223344']]);

    expect(DB::table('migration_staging_rows')->where('id', $row->id)->value('raw'))->not->toContain('Sara');
});
