<?php

declare(strict_types=1);

use App\Models\ReferenceItem;
use App\Support\Reference\ReferenceCatalogue;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

describe('the catalogue files', function () {
    it('give every row a name in all three languages', function () {
        foreach (ReferenceCatalogue::rows() as $row) {
            expect($row['name'])->not->toBe('', "{$row['kind']}:{$row['code']} has no English name");
            expect($row['name_am'])->not->toBe('', "{$row['kind']}:{$row['code']} has no Amharic name");
            expect($row['name_om'])->not->toBe('', "{$row['kind']}:{$row['code']} has no Afaan Oromoo name");
        }
    });

    it('name a region for every sub-city and town', function () {
        $regions = collect(ReferenceCatalogue::rows())
            ->where('kind', ReferenceCatalogue::REGION)
            ->pluck('code')
            ->all();

        $orphans = collect(ReferenceCatalogue::rows())
            ->whereIn('kind', [ReferenceCatalogue::SUB_CITY, ReferenceCatalogue::TOWN])
            ->reject(fn (array $row): bool => in_array($row['parent_code'], $regions, true))
            ->pluck('code')
            ->all();

        expect($orphans)->toBe([]);
    });

    it('hold the regions as organised since 2023', function () {
        $regions = collect(ReferenceCatalogue::rows())->where('kind', ReferenceCatalogue::REGION);

        expect($regions)->toHaveCount(14);
        expect($regions->where('meta.type', 'regional_state'))->toHaveCount(12);
        expect($regions->where('meta.type', 'city_administration')->pluck('code')->all())
            ->toBe(['addis_ababa', 'dire_dawa']);
    });

    it('call Addis Ababa Finfinnee in Afaan Oromoo and give it eleven sub-cities', function () {
        $rows = collect(ReferenceCatalogue::rows());

        expect($rows->firstWhere('code', 'addis_ababa')['name_om'])->toBe('Finfinnee');
        expect($rows->where('kind', ReferenceCatalogue::SUB_CITY)->where('parent_code', 'addis_ababa'))->toHaveCount(11);
    });

    it('refuse a code that is repeated within a list', function () {
        $codes = collect(ReferenceCatalogue::rows())->map(fn (array $row): string => $row['kind'].':'.$row['code']);

        expect($codes->duplicates()->all())->toBe([]);
    });
});

describe('loading the catalogue', function () {
    it('is already loaded by the migration, so no seed call is needed', function () {
        $expected = collect(ReferenceCatalogue::rows())->groupBy('kind')->map->count()->all();

        foreach ($expected as $kind => $count) {
            expect(ReferenceItem::query()->ofKind($kind)->active()->count())->toBe($count);
        }
    });

    it('changes nothing when run again, and keeps every public id', function () {
        $before = ReferenceItem::query()->orderBy('id')->pluck('public_id', 'code')->all();
        $count = ReferenceItem::query()->count();

        $counts = ReferenceCatalogue::sync();

        expect($counts)->toBe(['inserted' => 0, 'updated' => 0, 'deactivated' => 0]);
        expect(ReferenceItem::query()->count())->toBe($count);
        expect(ReferenceItem::query()->orderBy('id')->pluck('public_id', 'code')->all())->toBe($before);
    });

    it('keeps public ids when the seeder runs twice', function () {
        $before = ReferenceItem::query()->orderBy('id')->pluck('public_id', 'id')->all();

        (new ReferenceDataSeeder)->run();
        (new ReferenceDataSeeder)->run();

        expect(ReferenceItem::query()->orderBy('id')->pluck('public_id', 'id')->all())->toBe($before);
    });

    it('puts a changed name back from the files and keeps the row and its id', function () {
        $item = ReferenceItem::query()->ofKind('town')->where('code', 'adama')->firstOrFail();
        $publicId = $item->public_id;

        DB::table('reference_items')->where('id', $item->id)->update(['name' => 'Nazret']);

        $counts = ReferenceCatalogue::sync();

        expect($counts['updated'])->toBe(1);
        expect($item->fresh()->name)->toBe('Adama');
        expect($item->fresh()->public_id)->toBe($publicId);
    });

    it('retires a row that has left the files instead of deleting it', function () {
        DB::table('reference_items')->insert([
            'public_id' => (string) Str::ulid(),
            'kind' => 'town',
            'code' => 'retired_town',
            'parent_code' => 'oromia',
            'name' => 'Retired',
            'name_am' => 'Retired',
            'name_om' => 'Retired',
            'sort_order' => 999,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $counts = ReferenceCatalogue::sync();

        expect($counts['deactivated'])->toBe(1);

        $row = ReferenceItem::query()->where('code', 'retired_town')->first();
        expect($row)->not->toBeNull();
        expect($row->is_active)->toBeFalse();
    });

    it('brings a retired row back when the files list it again', function () {
        $item = ReferenceItem::query()->ofKind('bank')->where('code', 'awash')->firstOrFail();
        $item->update(['is_active' => false]);

        ReferenceCatalogue::sync();

        expect($item->fresh()->is_active)->toBeTrue();
    });
});

describe('GET /lookups', function () {
    beforeEach(fn () => Cache::forget(ReferenceCatalogue::CACHE_KEY));

    it('answers without signing in', function () {
        $this->getJson('/api/v1/lookups')
            ->assertOk()
            ->assertJsonStructure([
                'version',
                'items' => [
                    'region' => [['code', 'parent_code', 'name', 'name_am', 'name_om', 'meta']],
                    'sub_city',
                    'town',
                    'bank',
                ],
            ]);
    });

    it('lists every kind in order, regions first the two city administrations', function () {
        $items = $this->getJson('/api/v1/lookups')->json('items');

        expect(array_keys($items))->toBe(['region', 'sub_city', 'town', 'bank']);
        expect(array_slice(array_column($items['region'], 'code'), 0, 2))->toBe(['addis_ababa', 'dire_dawa']);
    });

    it('sends no numeric or public ids', function () {
        $body = $this->getJson('/api/v1/lookups')->getContent();

        expect($body)->not->toContain('"id"');
        expect($body)->not->toContain('public_id');
    });

    it('leaves out a retired row', function () {
        ReferenceItem::query()->ofKind('bank')->where('code', 'awash')->update(['is_active' => false]);
        Cache::forget(ReferenceCatalogue::CACHE_KEY);

        $codes = array_column($this->getJson('/api/v1/lookups')->json('items.bank'), 'code');

        expect($codes)->not->toContain('awash');
        expect($codes)->toContain('dashen');
    });

    it('keeps its version while the content is the same and changes it when a name changes', function () {
        $first = $this->getJson('/api/v1/lookups')->json('version');
        Cache::forget(ReferenceCatalogue::CACHE_KEY);

        expect($this->getJson('/api/v1/lookups')->json('version'))->toBe($first);

        ReferenceItem::query()->ofKind('region')->where('code', 'tigray')->update(['name' => 'Tigray Region']);
        Cache::forget(ReferenceCatalogue::CACHE_KEY);

        expect($this->getJson('/api/v1/lookups')->json('version'))->not->toBe($first);
    });
});
