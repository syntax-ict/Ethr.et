<?php

declare(strict_types=1);

namespace App\Support\Reference;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * The places and banks the forms offer as pick lists, read from the files in
 * `database/data/reference/` and written to `reference_items`.
 *
 * One loader serves the migration that makes the first load (so a deploy that
 * ships a form checking against these rows can never meet an empty table
 * because a seed call came late) and the seeder that re-syncs after a data
 * file changes. Because both go through `sync()`, they cannot drift.
 *
 * `sync()` never rewrites what it has already written beyond what changed:
 *
 *  - a row is found by (`kind`, `code`); the code is the stable key and never
 *    changes once shipped;
 *  - `public_id` is set on insert only, so anything holding one keeps working
 *    across every later run (PermissionSeeder and TaxBracketSeeder rewrite
 *    theirs on every run, which is the pattern this avoids);
 *  - a row that leaves the files is deactivated, never deleted, so a record
 *    that stored its code still resolves to a name.
 */
final class ReferenceCatalogue
{
    public const REGION = 'region';

    public const SUB_CITY = 'sub_city';

    public const TOWN = 'town';

    public const BANK = 'bank';

    /** @var list<string> */
    public const KINDS = [self::REGION, self::SUB_CITY, self::TOWN, self::BANK];

    public const CACHE_KEY = 'reference.lookups.v1';

    /**
     * Every row the data files describe, in display order.
     *
     * @return list<array{kind: string, code: string, parent_code: string|null, name: string, name_am: string, name_om: string, sort_order: int, meta: array<string, mixed>}>
     */
    public static function rows(): array
    {
        $rows = [];

        foreach (self::load('regions') as $i => [$code, $name, $am, $om, $type]) {
            $rows[] = self::row(self::REGION, $code, null, $name, $am, $om, $i, ['type' => $type]);
        }

        foreach (['sub_cities' => self::SUB_CITY, 'towns' => self::TOWN] as $file => $kind) {
            $order = 0;

            foreach (self::load($file) as $parent => $children) {
                foreach ($children as [$code, $name, $am, $om]) {
                    $rows[] = self::row($kind, $code, $parent, $name, $am, $om, $order++, []);
                }
            }
        }

        foreach (self::load('banks') as $i => [$code, $name, $am, $om, $short]) {
            $rows[] = self::row(self::BANK, $code, null, $name, $am, $om, $i, ['short_name' => $short]);
        }

        self::assertConsistent($rows);

        return $rows;
    }

    /**
     * Make `reference_items` match the files, keeping every existing id.
     *
     * @return array{inserted: int, updated: int, deactivated: int}
     */
    public static function sync(): array
    {
        $rows = self::rows();
        $counts = ['inserted' => 0, 'updated' => 0, 'deactivated' => 0];

        DB::transaction(function () use ($rows, &$counts): void {
            $now = now();

            foreach (self::KINDS as $kind) {
                $existing = DB::table('reference_items')->where('kind', $kind)->get()->keyBy('code');
                $kept = [];

                foreach (array_filter($rows, static fn (array $row): bool => $row['kind'] === $kind) as $row) {
                    $kept[] = $row['code'];
                    $values = [
                        'parent_code' => $row['parent_code'],
                        'name' => $row['name'],
                        'name_am' => $row['name_am'],
                        'name_om' => $row['name_om'],
                        'sort_order' => $row['sort_order'],
                        'is_active' => true,
                        'meta' => json_encode($row['meta'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    ];

                    $current = $existing->get($row['code']);

                    if ($current === null) {
                        DB::table('reference_items')->insert([
                            'public_id' => (string) Str::ulid(),
                            'kind' => $kind,
                            'code' => $row['code'],
                            ...$values,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                        $counts['inserted']++;

                        continue;
                    }

                    if (self::differs($current, $values)) {
                        DB::table('reference_items')
                            ->where('kind', $kind)
                            ->where('code', $row['code'])
                            ->update([...$values, 'updated_at' => $now]);
                        $counts['updated']++;
                    }
                }

                $counts['deactivated'] += DB::table('reference_items')
                    ->where('kind', $kind)
                    ->where('is_active', true)
                    ->whereNotIn('code', $kept)
                    ->update(['is_active' => false, 'updated_at' => $now]);
            }
        });

        return $counts;
    }

    /** Drop the cached public payload, after a sync that changed something. */
    public static function forgetCached(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<int|string, mixed>
     */
    private static function load(string $file): array
    {
        $path = database_path("data/reference/{$file}.php");

        if (! is_file($path)) {
            throw new LogicException("Reference data file is missing: {$path}");
        }

        $data = require $path;

        if (! is_array($data)) {
            throw new LogicException("Reference data file does not return an array: {$path}");
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{kind: string, code: string, parent_code: string|null, name: string, name_am: string, name_om: string, sort_order: int, meta: array<string, mixed>}
     */
    private static function row(string $kind, string $code, ?string $parent, string $name, string $am, string $om, int $order, array $meta): array
    {
        return [
            'kind' => $kind,
            'code' => $code,
            'parent_code' => $parent,
            'name' => $name,
            'name_am' => $am,
            'name_om' => $om,
            'sort_order' => $order,
            'meta' => $meta,
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function differs(object $current, array $values): bool
    {
        foreach ($values as $column => $value) {
            $stored = $current->{$column};

            if ($column === 'is_active') {
                $stored = (bool) $stored;
            } elseif ($column === 'sort_order') {
                $stored = (int) $stored;
            } elseif ($column === 'meta') {
                // Compared as decoded values: MySQL's JSON type does not keep
                // the order a document was written in.
                if (json_decode((string) $stored, true) != json_decode((string) $value, true)) {
                    return true;
                }

                continue;
            }

            if ($stored !== $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * A data file that repeats a code, or names a parent that is not there,
     * would load without a word and then misbehave in a form.
     *
     * @param  list<array{kind: string, code: string, parent_code: string|null, name: string, name_am: string, name_om: string, sort_order: int, meta: array<string, mixed>}>  $rows
     */
    private static function assertConsistent(array $rows): void
    {
        $seen = [];

        foreach ($rows as $row) {
            $key = $row['kind'].':'.$row['code'];

            if (isset($seen[$key])) {
                throw new LogicException("Reference data repeats {$key}.");
            }

            $seen[$key] = true;
        }

        foreach ($rows as $row) {
            if ($row['parent_code'] !== null && ! isset($seen[self::REGION.':'.$row['parent_code']])) {
                throw new LogicException("Reference data: {$row['kind']}:{$row['code']} names a region that does not exist: {$row['parent_code']}.");
            }
        }
    }
}
