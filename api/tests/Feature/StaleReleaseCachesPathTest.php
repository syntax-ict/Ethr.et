<?php

declare(strict_types=1);

use App\Support\StaleReleaseCaches;

it('sweeps and reads the release stamp from the same file', function () {
    // The sweep runs before the container boots, so it cannot call public_path().
    // ReleaseStamp::read() does. If these differ, the sweep would judge caches
    // against a release the rest of the application never sees.
    expect(app()->publicPath('release.json'))->toBe(public_path('release.json'))
        ->and(StaleReleaseCaches::markerPath(app()))->toBe(app()->bootstrapPath('cache/ethr-recache-pending'));
});
