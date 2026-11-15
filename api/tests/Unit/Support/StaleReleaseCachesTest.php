<?php

declare(strict_types=1);

use App\Support\StaleReleaseCaches;
use Illuminate\Foundation\Application;

/**
 * A throwaway application rooted in a temp directory, so the real
 * bootstrap/cache is never touched.
 *
 * @return array{0: Application, 1: string}
 */
function staleCachesFixture(): array
{
    $base = sys_get_temp_dir().'/ethr-stale-'.bin2hex(random_bytes(6));
    mkdir($base.'/bootstrap/cache', 0777, true);
    mkdir($base.'/public', 0777, true);

    return [new Application($base), $base];
}

function staleCachesRemove(string $dir): void
{
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir.'/'.$entry;
        is_dir($path) ? staleCachesRemove($path) : @unlink($path);
    }
    @rmdir($dir);
}

function stampRelease(string $base, string $body): void
{
    file_put_contents($base.'/public/release.json', $body);
}

const STALE_BUILT_AT = '2026-11-15T10:00:00Z';

beforeEach(function () {
    [$this->fixtureApp, $this->base] = staleCachesFixture();
    $this->built = strtotime(STALE_BUILT_AT);
    $this->config = $this->fixtureApp->getCachedConfigPath();
    $this->routes = $this->fixtureApp->getCachedRoutesPath();
    $this->marker = StaleReleaseCaches::markerPath($this->fixtureApp);
});

afterEach(function () {
    staleCachesRemove($this->base);
});

it('drops both caches older than the release and leaves a marker', function () {
    stampRelease($this->base, json_encode(['commit' => 'abcdef1', 'built_at' => STALE_BUILT_AT]));
    foreach ([$this->config, $this->routes] as $file) {
        file_put_contents($file, '<?php return [];');
        touch($file, $this->built - 3600);
    }

    $removed = StaleReleaseCaches::sweep($this->fixtureApp);

    expect($removed)->toHaveCount(2)
        ->and(is_file($this->config))->toBeFalse()
        ->and(is_file($this->routes))->toBeFalse()
        ->and(file_get_contents($this->marker))->toBe(STALE_BUILT_AT);
});

it('keeps caches built after the release and writes no marker', function () {
    stampRelease($this->base, json_encode(['built_at' => STALE_BUILT_AT]));
    foreach ([$this->config, $this->routes] as $file) {
        file_put_contents($file, '<?php return [];');
        touch($file, $this->built + 60);
    }

    expect(StaleReleaseCaches::sweep($this->fixtureApp))->toBe([])
        ->and(is_file($this->config))->toBeTrue()
        ->and(is_file($this->routes))->toBeTrue()
        ->and(is_file($this->marker))->toBeFalse();
});

it('drops only the stale one of the two', function () {
    stampRelease($this->base, json_encode(['built_at' => STALE_BUILT_AT]));
    file_put_contents($this->config, '<?php return [];');
    touch($this->config, $this->built + 60);
    file_put_contents($this->routes, '<?php return [];');
    touch($this->routes, $this->built - 60);

    expect(StaleReleaseCaches::sweep($this->fixtureApp))->toBe([$this->routes])
        ->and(is_file($this->config))->toBeTrue()
        ->and(is_file($this->routes))->toBeFalse()
        ->and(is_file($this->marker))->toBeTrue();
});

it('does nothing without a release stamp', function () {
    file_put_contents($this->routes, '<?php return [];');
    touch($this->routes, $this->built - 3600);

    expect(StaleReleaseCaches::sweep($this->fixtureApp))->toBe([])
        ->and(is_file($this->routes))->toBeTrue()
        ->and(is_file($this->marker))->toBeFalse();
});

it('does nothing when the release stamp is malformed', function (string $body) {
    stampRelease($this->base, $body);
    file_put_contents($this->routes, '<?php return [];');
    touch($this->routes, $this->built - 3600);

    expect(StaleReleaseCaches::sweep($this->fixtureApp))->toBe([])
        ->and(is_file($this->routes))->toBeTrue()
        ->and(is_file($this->marker))->toBeFalse();
})->with([
    'not json' => '{nope',
    'no built_at' => '{"commit":"abcdef1"}',
    'unparsable built_at' => '{"built_at":"last tuesday-ish"}',
    'built_at not a string' => '{"built_at":12}',
]);

it('returns at once when there is no cache file, without reading the release stamp', function () {
    // A directory where the stamp should be: reading it would fail loudly.
    mkdir($this->base.'/public/release.json');

    expect(StaleReleaseCaches::sweep($this->fixtureApp))->toBe([])
        ->and(is_file($this->marker))->toBeFalse();
});

it('never throws, even when the stamp is unreadable', function () {
    file_put_contents($this->routes, '<?php return [];');
    mkdir($this->base.'/public/release.json');

    expect(StaleReleaseCaches::sweep($this->fixtureApp))->toBe([])
        ->and(is_file($this->routes))->toBeTrue();
});

it('keeps a cache written moments ago, which is what route:cache relies on', function () {
    stampRelease($this->base, json_encode(['built_at' => STALE_BUILT_AT]));
    file_put_contents($this->config, '<?php return [];');

    expect(StaleReleaseCaches::sweep($this->fixtureApp))->toBe([])
        ->and(is_file($this->config))->toBeTrue();
});

it('reports caches as fresh only when both exist and postdate the release', function () {
    stampRelease($this->base, json_encode(['built_at' => STALE_BUILT_AT]));

    expect(StaleReleaseCaches::cachesAreFresh($this->fixtureApp))->toBeFalse();

    foreach ([$this->config, $this->routes] as $file) {
        file_put_contents($file, '<?php return [];');
        touch($file, $this->built + 60);
    }
    expect(StaleReleaseCaches::cachesAreFresh($this->fixtureApp))->toBeTrue();

    touch($this->routes, $this->built - 60);
    expect(StaleReleaseCaches::cachesAreFresh($this->fixtureApp))->toBeFalse();
});
