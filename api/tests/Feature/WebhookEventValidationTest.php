<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Webhook;
use App\Support\WebhookEvents;

/*
 * Audit N11: `events.*` accepted any string. A webhook subscribed to an event the
 * application never sends saved, was listed as Active, and never fired — the
 * tenant had no way to learn it was waiting for nothing.
 */

function webhookHost(): array
{
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    return [$tenant, "http://{$tenant->subdomain}.ethr.test/api/v1/webhooks"];
}

test('creating a webhook for an event that is never sent is refused', function () {
    [, $url] = webhookHost();

    test()->postJson($url, [
        'url' => 'https://example.com/hook',
        'events' => ['employee.created', 'attendance.recorded'],
    ])->assertStatus(422)->assertJsonValidationErrors(['events.1']);

    expect(Webhook::count())->toBe(0);
});

test('updating a webhook to an event that is never sent is refused', function () {
    [$tenant, $url] = webhookHost();

    $webhook = Webhook::factory()->create([
        'tenant_id' => $tenant->id,
        'events' => ['employee.created'],
    ]);

    test()->putJson("{$url}/{$webhook->public_id}", [
        'events' => ['employee.terminated'],
    ])->assertStatus(422)->assertJsonValidationErrors(['events.0']);

    expect($webhook->fresh()->events)->toBe(['employee.created']);
});

test('the same event twice is refused', function () {
    [, $url] = webhookHost();

    test()->postJson($url, [
        'url' => 'https://example.com/hook',
        'events' => ['leave.approved', 'leave.approved'],
    ])->assertStatus(422);
});

test('every event the application sends can be subscribed to', function () {
    [, $url] = webhookHost();

    test()->postJson($url, [
        'url' => 'https://example.com/hook',
        'events' => WebhookEvents::ALL,
    ])->assertCreated()->assertJsonPath('events', WebhookEvents::ALL);
});

test('the event list is exactly the events dispatched from app/', function () {
    $dispatched = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));
    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        preg_match_all(
            "/->webhook\(\s*[^,]+,\s*'([a-z_]+\.[a-z_]+)'/",
            (string) file_get_contents($file->getPathname()),
            $matches,
        );

        foreach ($matches[1] as $event) {
            $dispatched[$event] = true;
        }
    }

    $dispatched = array_keys($dispatched);
    sort($dispatched);
    $listed = WebhookEvents::ALL;
    sort($listed);

    // A broken directory walk would find nothing and agree with an empty list.
    expect(count($dispatched))->toBeGreaterThanOrEqual(9)
        ->and($dispatched)->toBe($listed);
});
