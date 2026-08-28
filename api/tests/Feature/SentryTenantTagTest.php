<?php

declare(strict_types=1);

use App\Services\CurrentTenant;
use App\Services\Observability\SentryScrubber;
use Sentry\Event;

it('tags the event with the resolved tenant', function () {
    $tenant = createTenant();

    $event = SentryScrubber::handle(Event::createEvent());

    expect($event?->getTags()['tenant.id'] ?? null)->toBe((string) $tenant->id);
});

it('omits the tenant tag when no tenant is resolved', function () {
    app(CurrentTenant::class)->forget();

    $event = SentryScrubber::handle(Event::createEvent());

    expect($event?->getTags())->not->toHaveKey('tenant.id');
});
