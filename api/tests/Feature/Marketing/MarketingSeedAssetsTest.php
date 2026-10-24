<?php

declare(strict_types=1);

use App\Models\MarketingItem;
use App\Models\MarketingSection;
use App\Rules\VerifyFileContent;
use App\Support\MarketingClaims;
use App\Support\MarketingContent;
use Database\Seeders\MarketingContentSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * The seeded illustrations: `database/seeders/assets/marketing/manifest.json`
 * names a file per row, the seeder attaches each to a row that has no
 * picture, and never replaces one an operator uploaded.
 */
function marketingManifest(): array
{
    $path = MarketingContentSeeder::ASSETS_DIR.'/manifest.json';
    if (! is_file($path)) {
        return ['images' => []];
    }

    return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

it('names only known rows, with both alt texts in house style, and files that are real images under 1 MB', function () {
    $seeder = new MarketingContentSeeder;
    $known = [];
    foreach ($seeder->sections() as $kind => $section) {
        foreach (array_keys($section['items'] ?? []) as $slug) {
            $known[] = "{$kind}/{$slug}";
        }
    }
    $known[] = 'hero/hero';

    foreach (marketingManifest()['images'] as $entry) {
        $where = ($entry['kind'] ?? '?').'/'.($entry['slug'] ?? '?');
        expect(in_array($where, $known, true))->toBeTrue("{$where} is not a seeded row");
        expect(MarketingContent::spec($entry['kind'])[$entry['kind'] === 'hero' ? 'section_image' : 'images'])->toBeTrue("{$where} cannot carry a picture");

        foreach (['alt', 'alt_am'] as $field) {
            $text = $entry[$field] ?? '';
            expect($text)->not->toBeEmpty("{$where} has no {$field}")
                ->and($text)->not->toContain('—')
                ->and(str_ends_with(trim($text), '!'))->toBeFalse()
                ->and(MarketingClaims::violation($text))->toBeNull("{$where} {$field} makes a forbidden claim");
        }

        $file = MarketingContentSeeder::ASSETS_DIR.'/'.basename($entry['file']);
        expect(is_file($file))->toBeTrue("{$where} names {$entry['file']}, which is not there")
            ->and(filesize($file))->toBeLessThanOrEqual(1024 * 1024, "{$where} is over 1 MB");

        $upload = new UploadedFile($file, basename($file), mime_content_type($file) ?: null, null, true);
        expect(Validator::make(['image' => $upload], ['image' => ['image', new VerifyFileContent]])->passes())->toBeTrue("{$where} is not a verified image");
    }
});

it('every prompt names a seeded row and makes no forbidden claim', function () {
    $path = MarketingContentSeeder::ASSETS_DIR.'/prompts.json';
    if (! is_file($path)) {
        $this->markTestSkipped('No prompt pack yet.');
    }
    $prompts = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $known = ['hero'];
    foreach ((new MarketingContentSeeder)->sections() as $section) {
        foreach (array_keys($section['items'] ?? []) as $slug) {
            $known[] = $slug;
        }
    }
    foreach ($prompts['scenes'] ?? [] as $scene) {
        expect(in_array($scene['slug'], $known, true))->toBeTrue("prompt {$scene['slug']} names no seeded row");
        foreach (['alt', 'alt_am'] as $field) {
            expect(MarketingClaims::violation($scene[$field] ?? ''))->toBeNull();
        }
    }
});

it('attaches a manifest picture to a row without one, skips a missing file, and never replaces an upload', function () {
    Storage::fake((string) config('filesystems.default'));
    $this->seed(MarketingContentSeeder::class);

    // A manifest of our own, in a temporary assets folder, read through the
    // same loader by pointing the seeder's directory constant at it is not
    // possible (a constant), so the loader is exercised through the real
    // manifest shape with a stubbed seeder subclass.
    $dir = sys_get_temp_dir().'/ethr-marketing-assets-'.uniqid();
    mkdir($dir);
    $jpeg = UploadedFile::fake()->image('records.jpg', 1600, 1000);
    copy($jpeg->getPathname(), "{$dir}/records.jpg");
    file_put_contents("{$dir}/manifest.json", json_encode(['images' => [
        ['file' => 'records.jpg', 'kind' => 'capabilities', 'slug' => 'records', 'alt' => 'A complete file', 'alt_am' => 'ሙሉ ፋይል'],
        ['file' => 'missing.jpg', 'kind' => 'capabilities', 'slug' => 'time', 'alt' => 'Missing', 'alt_am' => 'የለም'],
        ['file' => 'records.jpg', 'kind' => 'hero', 'slug' => 'hero', 'alt' => 'Hero', 'alt_am' => 'ዋና'],
    ]]));

    $seeder = new class($dir) extends MarketingContentSeeder
    {
        public function __construct(private readonly string $dir) {}

        protected function assetsDir(): string
        {
            return $this->dir;
        }
    };
    $seeder->run();

    $records = MarketingItem::query()->where('slug', 'records')->firstOrFail();
    $hero = MarketingSection::query()->where('kind', 'hero')->firstOrFail();
    expect($records->hasImage())->toBeTrue()
        ->and($records->image_alt_am)->toBe('ሙሉ ፋይል')
        ->and($records->image_width)->toBe(1600)
        ->and(MarketingItem::query()->where('slug', 'time')->firstOrFail()->hasImage())->toBeFalse()
        ->and($hero->hasImage())->toBeTrue();

    // An operator replaced the picture; the re-seed leaves theirs in place.
    $records->forceFill(['image_version' => 'ffffffffffffffff', 'image_path' => 'platform/marketing/theirs.jpg'])->save();
    $seeder->run();
    expect(MarketingItem::query()->where('slug', 'records')->value('image_version'))->toBe('ffffffffffffffff');

    array_map('unlink', glob("{$dir}/*") ?: []);
    rmdir($dir);
});
