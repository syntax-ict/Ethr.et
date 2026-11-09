<?php

declare(strict_types=1);

use App\Services\Backup\DatabaseDumper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Review of 2026-11-09. The dump paged each table 500 rows at a time with no
 * transaction, so each page read its own moment, and a row written during
 * the 01:00 backup into a table with a random key (`cache`, `sessions`,
 * `notifications`) could land in two pages. The restore stopped at the
 * duplicate key after every table had been dropped. Pages are now read from
 * one consistent snapshot on MariaDB and ordered by the primary key.
 *
 * What this pins is the paging: a random-keyed table larger than a page
 * comes out whole, each row exactly once. The snapshot itself needs a
 * second writer and no surrounding transaction, so the CI restore rehearsal
 * on MariaDB covers it, not this suite, whose transaction the dumper keeps.
 */
test('a dump pages a random-keyed table larger than a page, writing each row once', function () {
    $keys = collect(range(1, 1200))->map(fn () => 'probe:'.Str::uuid())->all();
    foreach (array_chunk($keys, 200) as $chunk) {
        DB::table('cache')->insert(array_map(fn (string $key) => ['key' => $key, 'value' => 'x', 'expiration' => 2_000_000_000], $chunk));
    }

    $path = tempnam(sys_get_temp_dir(), 'dump-order-');
    app(DatabaseDumper::class)->dumpTo($path);

    $written = collect(file($path, FILE_IGNORE_NEW_LINES))
        ->filter(fn (string $line) => preg_match('/^INSERT INTO [`"]cache[`"] /', $line) === 1)
        ->map(fn (string $line) => preg_match("/VALUES \\('(probe:[0-9a-f-]+)'/", $line, $m) === 1 ? $m[1] : null)
        ->filter()
        ->values();
    unlink($path);

    // Compared as sets: the engine's collation, not PHP's strcmp, decides
    // the order the rows come out in.
    $sorted = fn ($list) => collect($list)->sort(fn (string $a, string $b) => strcmp($a, $b))->values()->all();

    expect($written->count())->toBe(1200)
        ->and($written->unique()->count())->toBe(1200)
        ->and($sorted($written))->toBe($sorted($keys));
});
