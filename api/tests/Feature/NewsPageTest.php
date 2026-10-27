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
    file_put_contents($page, '<!doctype html><html><head><meta charset="utf-8"><title>News | ETHR</title><meta name="robots" content="noindex, nofollow"><meta name="description" content="News."></head><body><main id="news"></main></body></html>');

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
    newsTenant('acme', 'Acme Manufacturing');
    serveNewsPage('http://acme.ethr.et/news', 'news.html')
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'index, follow');

    newsTenant('hidden', 'Hidden Ltd', ['listed' => false]);
    serveNewsPage('http://hidden.ethr.et/news', 'news.html')
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

    newsTenant('quiet', 'Quiet Ltd', ['sections' => ['announcements' => false]]);
    serveNewsPage('http://quiet.ethr.et/news', 'news.html')->assertNotFound();
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

    $xml = (string) test()->get('http://ethr.et/sitemap-organisations.xml')->assertOk()->getContent();

    expect($xml)
        ->toContain('<loc>https://acme.ethr.et/news</loc>')
        ->toContain("<loc>https://acme.ethr.et/news/{$article->public_id}</loc>")
        // One article entry: the private one is absent.
        ->and(substr_count($xml, '<loc>https://acme.ethr.et/news/'))->toBe(1)
        // The shared-host organisation keeps its sign-in entry, and no news.
        ->and($xml)->toContain('https://www.ethr.et/onpath')
        ->not->toContain('news?org=onpath');
});
