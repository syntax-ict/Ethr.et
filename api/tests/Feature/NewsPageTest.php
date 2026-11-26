<?php

declare(strict_types=1);

use App\Models\Announcement;
use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/**
 * The organisation's news page and articles as served on its own host
 * (NewsPageController, .htaccess group 1a): the exported page with the
 * organisation's head. These pin that an article's head says what the article
 * shows and nothing else, that only a public, published article has one, that
 * the host alone decides the organisation, and that listing stays opt-in.
 */
beforeEach(function () {
    Storage::fake((string) config('filesystems.default'));
    config(['app.frontend_url' => 'https://www.ethr.et', 'app.domain' => 'ethr.et', 'tenancy.subdomains' => true]);
    app()->detectEnvironment(fn () => 'production');
});

afterEach(function () {
    app()->detectEnvironment(fn () => 'testing');
});

/** The exported page at `$file`, served for `$url` with a stand-in file. */
function serveNewsPage(string $url, string $file): TestResponse
{
    $page = public_path($file);
    $existed = is_file($page);
    $original = $existed ? file_get_contents($page) : null;
    if (! is_dir(dirname($page))) {
        mkdir(dirname($page), 0777, true);
    }
    file_put_contents($page, '<!doctype html><html><head><meta charset="utf-8"><title>News | ETHR</title><meta name="robots" content="noindex, nofollow"><meta name="description" content="News."></head><body><main id="news"></main>'.portalPayload('noindex, nofollow', 'News and announcements.').'</body></html>');

    try {
        return test()->get($url);
    } finally {
        $existed ? file_put_contents($page, $original) : unlink($page);
    }
}

function newsTenant(string $slug, string $name, array $landing = []): Tenant
{
    return Tenant::factory()->create([
        'subdomain' => $slug,
        'name' => $name,
        'address_mode' => 'subdomain',
        'landing' => ['background' => 'brand', ...$landing],
    ]);
}

function publicArticle(Tenant $tenant, array $attributes = []): Announcement
{
    return Announcement::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'published_by' => createUser([], $tenant)->id,
        'title' => 'Plant reopens Monday',
        'body' => 'All shifts resume on Monday at 08:00.',
        'priority' => 'normal',
        'target_type' => 'all',
        'published_at' => now()->subHour(),
        'show_on_landing' => true,
        ...$attributes,
    ]);
}

it('renders an article head with its own title, words, canonical and NewsArticle', function () {
    $tenant = newsTenant('acme', 'Acme Manufacturing', ['listed' => true]);
    $article = publicArticle($tenant);

    $html = serveNewsPage("http://acme.ethr.et/news/{$article->public_id}", 'news/__id__.html')
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'index, follow')
        ->getContent();

    expect($html)
        ->toContain('<title>Plant reopens Monday · Acme Manufacturing</title>')
        ->toContain('<meta name="description" content="All shifts resume on Monday at 08:00.">')
        ->toContain("<link rel=\"canonical\" href=\"https://acme.ethr.et/news/{$article->public_id}\">")
        ->toContain('<meta property="og:type" content="article">')
        ->toContain('"@type":"NewsArticle"')
        ->toContain('<meta name="robots" content="index, follow">')
        // The export's own noindex line gave way to the organisation's choice.
        ->not->toContain('content="noindex, nofollow"')
        ->toContain('<main id="news">');
});

it('shares an article with its own cover photo as the preview image', function () {
    $tenant = newsTenant('acme', 'Acme Manufacturing', ['listed' => true]);
    $article = publicArticle($tenant);
    $article->forceFill(['cover' => [
        'path' => "tenants/{$tenant->public_id}/news/{$article->public_id}-0123456789abcdef.jpg",
        'version' => '0123456789abcdef',
        'width' => 1600,
        'height' => 900,
        'variants' => [],
    ]])->save();
    $cover = "https://www.ethr.et/api/v1/news-covers/{$tenant->public_id}/{$article->public_id}/cover/0123456789abcdef";

    $html = serveNewsPage("http://acme.ethr.et/news/{$article->public_id}", 'news/__id__.html')->assertOk()->getContent();

    expect($html)
        ->toContain('<meta property="og:image" content="'.$cover.'">')
        ->toContain('<meta property="og:image:width" content="1600">')
        ->toContain('<meta property="og:image:alt" content="Plant reopens Monday">')
        ->toContain('<meta name="twitter:image" content="'.$cover.'">')
        ->toContain('"image":"'.$cover.'"');
});

it('serves an unpublished, private or unknown article as a 404 kept out of search', function (string $kind) {
    $tenant = newsTenant('acme', 'Acme Manufacturing', ['listed' => true]);
    $article = match ($kind) {
        'draft' => publicArticle($tenant, ['published_at' => null]),
        'private' => publicArticle($tenant, ['show_on_landing' => false]),
        'scheduled' => publicArticle($tenant, ['published_at' => now()->addDay()]),
        'archived' => publicArticle($tenant, ['expires_at' => now()->subMinute()]),
    };

    $html = serveNewsPage("http://acme.ethr.et/news/{$article->public_id}", 'news/__id__.html')
        ->assertNotFound()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->getContent();

    expect($html)->not->toContain($article->title)->not->toContain('NewsArticle');
})->with(['draft', 'private', 'scheduled', 'archived']);

it('decides the organisation from the host alone', function () {
    $acme = newsTenant('acme', 'Acme Manufacturing');
    $habru = newsTenant('habru', 'Habru Coffee');
    $theirs = publicArticle($habru, ['title' => 'Habru harvest']);

    serveNewsPage("http://acme.ethr.et/news/{$theirs->public_id}", 'news/__id__.html')->assertNotFound();

    $html = serveNewsPage('http://habru.ethr.et/news', 'news.html')->assertOk()->getContent();
    expect($html)->toContain('<title>News · Habru Coffee</title>')
        ->toContain('<link rel="canonical" href="https://habru.ethr.et/news">')
        ->not->toContain('Acme');
});

it('lists the news page by default, keeps it out once listing is off, and has none with the section off', function () {
    publicArticle(newsTenant('acme', 'Acme Manufacturing'));
    $html = serveNewsPage('http://acme.ethr.et/news', 'news.html')
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'index, follow')
        ->getContent();

    // The page payload as well as the head: the export ships `noindex` for
    // the shared host, and React renders it back into the DOM at hydration.
    expect($html)->not->toContain('noindex');

    newsTenant('hidden', 'Hidden Ltd', ['listed' => false]);
    serveNewsPage('http://hidden.ethr.et/news', 'news.html')
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

    newsTenant('quiet', 'Quiet Ltd', ['sections' => ['announcements' => false]]);
    serveNewsPage('http://quiet.ethr.et/news', 'news.html')->assertNotFound();
});

it('keeps an empty news page out of search, and lets one back in once something is published', function () {
    $tenant = newsTenant('acme', 'Acme Manufacturing');

    // Nothing published: the same empty page every organisation would have.
    // Links on it are still followed, so the portal itself is reached.
    $html = serveNewsPage('http://acme.ethr.et/news', 'news.html')
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, follow')
        ->getContent();
    expect($html)->toContain('<meta name="robots" content="noindex, follow">')
        ->toContain('\\"name\\":\\"robots\\",\\"content\\":\\"noindex, follow\\"');

    publicArticle($tenant);

    serveNewsPage('http://acme.ethr.et/news', 'news.html')
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'index, follow');
});

it('serves the page as exported on the apex and an unknown host', function (string $url) {
    newsTenant('acme', 'Acme Manufacturing', ['listed' => true]);

    $html = serveNewsPage($url, 'news.html')
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->getContent();

    expect($html)->toContain('<title>News | ETHR</title>')->not->toContain('Acme');
})->with(['http://ethr.et/news', 'http://www.ethr.et/news', 'http://evil.example/news']);

it('lists a listed organisation\'s news page and articles in the sitemap, on its own host only', function () {
    $tenant = newsTenant('acme', 'Acme Manufacturing', ['listed' => true]);
    $article = publicArticle($tenant);
    publicArticle($tenant, ['title' => 'Secret', 'show_on_landing' => false]);
    $shared = Tenant::factory()->create(['subdomain' => 'onpath', 'address_mode' => 'path', 'landing' => ['background' => 'brand', 'listed' => true]]);
    publicArticle($shared, ['title' => 'Path story']);
    newsTenant('quietco', 'Quiet Co', ['listed' => true]);

    $xml = (string) test()->get('http://ethr.et/sitemap-organisations.xml')->assertOk()->getContent();

    expect($xml)
        ->toContain('<loc>https://acme.ethr.et/news</loc>')
        ->toContain("<loc>https://acme.ethr.et/news/{$article->public_id}</loc>")
        // One article entry: the private one is absent.
        ->and(substr_count($xml, '<loc>https://acme.ethr.et/news/'))->toBe(1)
        // The shared-host organisation keeps its sign-in entry, and no news.
        ->and($xml)->toContain('https://www.ethr.et/onpath')
        ->not->toContain('news?org=onpath')
        // An organisation that has published nothing keeps its sign-in entry
        // and no news page: that page says `noindex` for the same reason.
        ->and($xml)->toContain('<loc>https://quietco.ethr.et/login</loc>')
        ->not->toContain('quietco.ethr.et/news');
});

/** The JSON-LD records of a page's head, decoded, keyed by their `@type`. */
function headRecords(string $html): array
{
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $found);

    $records = [];
    foreach ($found[1] as $json) {
        $record = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $records[$record['@type']] = $record;
    }

    return $records;
}

it('gives an article its modified time, its text, its author and a breadcrumb', function () {
    $tenant = newsTenant('acme', 'Acme Manufacturing', ['listed' => true]);
    $article = publicArticle($tenant, ['body' => '<p>All shifts resume on Monday at 08:00.</p><p>Bring your badge.</p>']);
    $article->timestamps = false;
    $article->forceFill(['updated_at' => '2026-11-20 09:30:00'])->save();

    $html = serveNewsPage("http://acme.ethr.et/news/{$article->public_id}", 'news/__id__.html')->assertOk()->getContent();
    $records = headRecords($html);
    $url = "https://acme.ethr.et/news/{$article->public_id}";

    expect($html)->toContain('<meta property="article:modified_time" content="2026-11-20T09:30:00+00:00">')
        ->and($records['NewsArticle']['articleBody'])->toBe('All shifts resume on Monday at 08:00.Bring your badge.')
        ->and($records['NewsArticle']['author'])->toBe(['@type' => 'Organization', 'name' => 'Acme Manufacturing', 'url' => 'https://acme.ethr.et/login'])
        ->and($records['NewsArticle']['inLanguage'])->toBe('en')
        ->and(array_map(fn (array $crumb) => [$crumb['position'], $crumb['name'], $crumb['item']], $records['BreadcrumbList']['itemListElement']))->toBe([
            [1, 'Acme Manufacturing', 'https://acme.ethr.et/login'],
            [2, 'News', 'https://acme.ethr.et/news'],
            [3, 'Plant reopens Monday', $url],
        ]);
});

it('escapes a title and a body that hold a less-than sign and quotes, in every tag and record', function () {
    $tenant = newsTenant('acme', 'Acme Manufacturing', ['listed' => true]);
    $article = publicArticle($tenant, ['title' => 'Q&A: a<b "quoted"', 'body' => 'Budget<2000 birr and "quoted" <b>bold</b>']);

    $html = serveNewsPage("http://acme.ethr.et/news/{$article->public_id}", 'news/__id__.html')->assertOk()->getContent();
    $records = headRecords($html);

    expect($html)->toContain('<title>Q&amp;A: a&lt;b &quot;quoted&quot; · Acme Manufacturing</title>')
        ->and($html)->toContain('<meta name="description" content="Budget&lt;2000 birr and &quot;quoted&quot; bold">')
        ->and($html)->toContain('<meta property="og:title" content="Q&amp;A: a&lt;b &quot;quoted&quot; · Acme Manufacturing">')
        ->and($html)->not->toContain('content="Budget<2000')
        ->and($html)->toContain('Budget\u003C2000')
        ->and($records['NewsArticle']['articleBody'])->toBe('Budget<2000 birr and "quoted" bold')
        ->and($records['NewsArticle']['headline'])->toBe('Q&A: a<b "quoted"');
});

it('says an article\'s language only when the script and the organisation\'s language agree', function (string $locale, string $title, string $body, ?string $expected) {
    $tenant = newsTenant('acme', 'Acme Manufacturing', ['listed' => true]);
    $tenant->forceFill(['default_locale' => $locale])->save();
    $article = publicArticle($tenant, ['title' => $title, 'body' => $body]);

    $records = headRecords(serveNewsPage("http://acme.ethr.et/news/{$article->public_id}", 'news/__id__.html')->assertOk()->getContent());

    expect($records['NewsArticle'])->when($expected === null, fn ($r) => $r->not->toHaveKey('inLanguage'))
        ->when($expected !== null, fn ($r) => $r->inLanguage->toBe($expected));
})->with([
    'an Amharic story in an Amharic organisation' => ['am', 'ፋብሪካው ሰኞ ይከፈታል', 'ሁሉም ፈረቃዎች ሰኞ ከጠዋቱ ሁለት ሰዓት ይጀምራሉ።', 'am'],
    'an Amharic story in an English organisation' => ['en', 'ፋብሪካው ሰኞ ይከፈታል', 'ሁሉም ፈረቃዎች ሰኞ ከጠዋቱ ሁለት ሰዓት ይጀምራሉ።', null],
    'an English story in an English organisation' => ['en', 'Plant reopens Monday', 'All shifts resume.', 'en'],
    'an English story in an Amharic organisation' => ['am', 'Plant reopens Monday', 'All shifts resume.', null],
    'a Latin story in an Afaan Oromoo organisation' => ['om', 'Oduu haaraa', 'Hojiin guyyaa Wiixataa jalqaba.', 'om'],
    'a story with no letters at all' => ['en', '2026', '12345', null],
]);

it('lists the first page of stories in the news head, in the order the page lists them', function () {
    $tenant = newsTenant('acme', 'Acme Manufacturing', ['listed' => true]);
    publicArticle($tenant, ['title' => 'Plain old', 'published_at' => now()->subDays(9)]);
    publicArticle($tenant, ['title' => 'Plain new', 'published_at' => now()->subDays(1)]);
    publicArticle($tenant, ['title' => 'Urgent', 'priority' => 'urgent', 'published_at' => now()->subDays(4)]);
    publicArticle($tenant, ['title' => 'Featured', 'featured' => true, 'published_at' => now()->subDays(8)]);
    publicArticle($tenant, ['title' => 'High', 'priority' => 'high', 'published_at' => now()->subDays(3)]);
    publicArticle($tenant, ['title' => 'Hidden', 'show_on_landing' => false]);
    foreach (range(1, 8) as $i) {
        publicArticle($tenant, ['title' => "Filler {$i}", 'published_at' => now()->subDays(20 + $i)]);
    }

    $api = collect(test()->getJson('http://acme.ethr.et/api/v1/auth/tenant-landing/news')->assertOk()->json('data'));
    $html = serveNewsPage('http://acme.ethr.et/news', 'news.html')->assertOk()->getContent();
    $records = headRecords($html);
    $items = collect($records['CollectionPage']['mainEntity']['itemListElement']);

    // The same stories, in the same order, and the same number of them: the
    // page's first page, which is nine of the fourteen public ones.
    expect($items->map(fn (array $item) => basename($item['url']))->all())->toBe($api->pluck('public_id')->all())
        ->and($items)->toHaveCount(9)
        ->and($items->pluck('position')->all())->toBe(range(1, 9))
        ->and($items->first()['name'])->toBe('Featured')
        ->and($items->pluck('name')->all())->not->toContain('Hidden')
        ->and($records['CollectionPage']['mainEntity']['@type'])->toBe('ItemList')
        ->and($records['CollectionPage']['name'])->toBe('News · Acme Manufacturing')
        ->and(array_map(fn (array $crumb) => $crumb['item'], $records['BreadcrumbList']['itemListElement']))->toBe(['https://acme.ethr.et/login', 'https://acme.ethr.et/news']);
});

it('gives a news page with no stories no list, and the same robots as before', function () {
    newsTenant('acme', 'Acme Manufacturing', ['listed' => true]);

    $response = serveNewsPage('http://acme.ethr.et/news', 'news.html')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, follow');
    $html = $response->getContent();

    expect($html)->not->toContain('ItemList')
        ->not->toContain('CollectionPage')
        ->toContain('"@type":"BreadcrumbList"')
        ->toContain('<meta name="robots" content="noindex, follow">');
});

it('says the news head\'s fixed words in the organisation\'s own language', function (string $locale, string $title, string $description) {
    $tenant = newsTenant('acme', 'Acme Manufacturing', ['listed' => true]);
    $tenant->forceFill(['default_locale' => $locale])->save();
    publicArticle($tenant);

    $html = serveNewsPage('http://acme.ethr.et/news', 'news.html')->assertOk()->getContent();

    expect($html)->toContain("<title>{$title} · Acme Manufacturing</title>")
        ->toContain('<meta name="description" content="'.$description.'">')
        ->toContain('<meta property="og:title" content="'.$title.' · Acme Manufacturing">');
    // The application's own locale is left as it was.
    expect(app()->getLocale())->toBe('en');
})->with([
    'english' => ['en', 'News', 'News and announcements from Acme Manufacturing.'],
    'amharic' => ['am', 'ዜና', 'ከAcme Manufacturing የወጡ ዜናዎችና ማስታወቂያዎች።'],
    'afaan oromoo' => ['om', 'Oduu', 'Oduu fi ibsawwan Acme Manufacturing irraa.'],
]);

it('states the same description in the head and the payload of both news pages', function () {
    $tenant = newsTenant('acme', 'Acme Manufacturing', ['listed' => true]);
    $article = publicArticle($tenant);

    $list = serveNewsPage('http://acme.ethr.et/news', 'news.html')->getContent();
    $story = serveNewsPage("http://acme.ethr.et/news/{$article->public_id}", 'news/__id__.html')->getContent();

    expect(portalPayloadMeta($list, 'description'))->toBe(['News and announcements from Acme Manufacturing.'])
        ->and($list)->toContain('<meta name="description" content="News and announcements from Acme Manufacturing.">')
        ->and(portalPayloadMeta($story, 'description'))->toBe(['All shifts resume on Monday at 08:00.'])
        ->and($story)->toContain('<meta name="description" content="All shifts resume on Monday at 08:00.">')
        ->and($story)->not->toContain('News and announcements.');
});

it('dates the sitemap\'s news entries by when they last changed, and the sign-in entry not at all', function () {
    $tenant = newsTenant('acme', 'Acme Manufacturing', ['listed' => true]);
    $older = publicArticle($tenant, ['title' => 'Older', 'published_at' => now()->subDays(5)]);
    $newer = publicArticle($tenant, ['title' => 'Newer', 'published_at' => now()->subDays(2)]);
    foreach ([[$older, '2026-11-18 08:00:00'], [$newer, '2026-11-10 10:15:00']] as [$story, $at]) {
        $story->timestamps = false;
        $story->forceFill(['updated_at' => $at])->save();
    }

    $xml = (string) test()->get('http://ethr.et/sitemap-organisations.xml')->assertOk()->getContent();

    expect($xml)
        ->toContain("<loc>https://acme.ethr.et/news/{$older->public_id}</loc><lastmod>2026-11-18T08:00:00+00:00</lastmod>")
        ->toContain("<loc>https://acme.ethr.et/news/{$newer->public_id}</loc><lastmod>2026-11-10T10:15:00+00:00</lastmod>")
        ->toContain('<loc>https://acme.ethr.et/news</loc><lastmod>2026-11-18T08:00:00+00:00</lastmod>')
        ->toContain('<loc>https://acme.ethr.et/login</loc><xhtml:link')
        ->and(substr_count($xml, '<lastmod>'))->toBe(3);
});
