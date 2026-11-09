<?php

declare(strict_types=1);

use App\Services\Backup\DatabaseDumper;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Review of 2026-11-09. Each table was paged 500 rows at a time ordered by its
 * first column alone. Rows tied on that column could come back in a different
 * order from one page's query to the next, so a page could repeat a row
 * another page wrote, and the restore stopped at the duplicate key after
 * every table had been dropped. Pages are now ordered by the primary key,
 * and on MariaDB read from one consistent snapshot (exercised by the CI
 * restore rehearsal on MariaDB, not here: inside the test's transaction the
 * dumper keeps the caller's).
 */
test('a dump pages a table by its whole primary key, writing each row once', function () {
    Schema::create('dump_order_probe', function (Blueprint $table) {
        $table->unsignedInteger('group');
        $table->unsignedInteger('member');
        $table->primary(['group', 'member']);
    });

    // Three groups, 1,200 rows, more than two pages, inserted out of order
    // so storage order is not key order.
    $rows = [];
    foreach (range(1, 400) as $member) {
        foreach ([3, 1, 2] as $group) {
            $rows[] = ['group' => $group, 'member' => 401 - $member];
        }
    }
    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table('dump_order_probe')->insert($chunk);
    }

    $path = tempnam(sys_get_temp_dir(), 'dump-order-');
    app(DatabaseDumper::class)->dumpTo($path);

    $written = collect(file($path, FILE_IGNORE_NEW_LINES))
        ->filter(fn (string $line) => str_starts_with($line, 'INSERT INTO "dump_order_probe"'))
        ->map(fn (string $line) => preg_match("/VALUES \('?(\d+)'?, '?(\d+)'?\)/", $line, $m) ? [(int) $m[1], (int) $m[2]] : null)
        ->values();
    unlink($path);

    $expected = collect($rows)->map(fn (array $r) => [$r['group'], $r['member']])->sort()->values();

    expect($written->count())->toBe(1200)
        ->and($written->map(fn (array $r) => $r[0].'-'.$r[1])->unique()->count())->toBe(1200)
        ->and($written->all())->toBe($expected->all());
});
