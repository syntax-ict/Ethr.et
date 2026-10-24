<?php

declare(strict_types=1);

use App\Models\MarketingItem;
use App\Models\MarketingSection;
use App\Support\MarketingClaims;
use App\Support\MarketingContent;
use Database\Seeders\MarketingContentSeeder;

/**
 * The seeded landing-page copy: first-run content that never overwrites an
 * operator's edit, and that itself makes no claim the public site may not
 * make.
 */
function seededStrings(): array
{
    $strings = [];
    foreach ((new MarketingContentSeeder)->sections() as $kind => $section) {
        foreach (['heading', 'heading_am', 'subheading', 'subheading_am'] as $field) {
            if (isset($section[$field])) {
                $strings["{$kind}.{$field}"] = $section[$field];
            }
        }
        foreach ($section['items'] ?? [] as $slug => $item) {
            foreach ($item as $field => $value) {
                if (is_string($value) && $field !== 'icon' && $field !== 'link_path') {
                    $strings["{$kind}.{$slug}.{$field}"] = $value;
                }
            }
        }
    }

    return $strings;
}

it('seeds every section and row once, and leaves an edited row alone on a re-seed', function () {
    $this->seed(MarketingContentSeeder::class);
    $sections = MarketingSection::query()->count();
    $items = MarketingItem::query()->count();

    expect($sections)->toBe(7)->and($items)->toBe(6 + 5 + 3 + 8 + 4 + 3);

    MarketingSection::query()->where('kind', 'hero')->update(['heading' => 'Our own words']);
    MarketingItem::query()->where('slug', 'time')->update(['title' => 'Edited by the operator', 'is_published' => false]);
    MarketingItem::query()->where('slug', 'general')->delete();

    $this->seed(MarketingContentSeeder::class);

    expect(MarketingSection::query()->count())->toBe($sections)
        ->and(MarketingItem::query()->count())->toBe($items)
        ->and(MarketingSection::query()->where('kind', 'hero')->value('heading'))->toBe('Our own words')
        ->and(MarketingItem::query()->where('slug', 'time')->value('title'))->toBe('Edited by the operator')
        ->and((bool) MarketingItem::query()->where('slug', 'time')->value('is_published'))->toBeFalse()
        ->and(MarketingItem::query()->where('slug', 'general')->exists())->toBeTrue();
});

it('seeds within the registry: fields a kind does not use are absent, counts fit, icons are known', function () {
    foreach ((new MarketingContentSeeder)->sections() as $kind => $section) {
        $spec = MarketingContent::spec($kind);
        $items = $section['items'] ?? [];
        expect(count($items))->toBeLessThanOrEqual($spec['max_items']);
        foreach ($items as $slug => $item) {
            foreach (['icon', 'body', 'detail', 'link_path'] as $field) {
                if (array_key_exists($field, $item) && $item[$field] !== null) {
                    expect(in_array($field, $spec['fields'], true))->toBeTrue("{$kind}.{$slug}.{$field} is not a field of {$kind}");
                }
            }
            if (isset($item['icon'])) {
                expect(MarketingContent::ICONS)->toContain($item['icon']);
            }
        }
    }
});

it('makes no claim the public site may not make, in either language', function () {
    foreach (seededStrings() as $where => $text) {
        expect(MarketingClaims::violation($text))->toBeNull("{$where} makes a forbidden claim: {$text}");
    }
});

it('keeps the house style: no long dash, no emoji, no exclamation ending', function () {
    foreach (seededStrings() as $where => $text) {
        expect($text)->not->toContain('—', "{$where} has a long dash")
            ->and(preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $text))->toBe(0, "{$where} has an emoji")
            ->and(str_ends_with(trim($text), '!'))->toBeFalse("{$where} ends in an exclamation mark");
    }
});

it('gives every row both languages', function () {
    foreach ((new MarketingContentSeeder)->sections() as $kind => $section) {
        expect($section['heading_am'] ?? null)->not->toBeEmpty("{$kind} has no Amharic heading");
        foreach ($section['items'] ?? [] as $slug => $item) {
            expect($item['title_am'] ?? null)->not->toBeEmpty("{$kind}.{$slug} has no Amharic title");
            if (! empty($item['body'])) {
                expect($item['body_am'] ?? null)->not->toBeEmpty("{$kind}.{$slug} has no Amharic body");
            }
            if (! empty($item['detail'])) {
                expect($item['detail_am'] ?? null)->not->toBeEmpty("{$kind}.{$slug} has no Amharic detail");
            }
        }
    }
});
