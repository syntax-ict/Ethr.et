<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Services\Observability\CronRunner;
use App\Support\BackgroundWork;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/**
 * An entry written by a queued job must not carry the person whose page request
 * happened to drain the queue (RunBackgroundWorkAfterResponse), nor their
 * address or browser.
 */
final class WritesAnAuditEntryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public readonly int $tenantId) {}

    public function handle(): void
    {
        AuditLog::record('test.background_entry', \App\Models\Tenant::findOrFail($this->tenantId));
    }
}

function asRequestFrom(string $ip, string $browser): void
{
    request()->server->set('REMOTE_ADDR', $ip);
    request()->headers->set('User-Agent', $browser);
}

it('keeps the signed-in person on an entry written inside a request', function () {
    $tenant = createTenant();
    $user = actingAsUser([], $tenant);
    asRequestFrom('203.0.113.7', 'ExampleBrowser/1.0');

    $entry = AuditLog::record('test.in_request', $tenant);

    expect($entry->user_id)->toBe($user->id)
        ->and($entry->ip_address)->toBe('203.0.113.7')
        ->and($entry->user_agent)->toBe('ExampleBrowser/1.0');
});

it('records no person, address or browser inside background work', function () {
    $tenant = createTenant();
    actingAsUser([], $tenant);
    asRequestFrom('203.0.113.7', 'ExampleBrowser/1.0');

    $entry = BackgroundWork::run(fn () => AuditLog::record('test.in_background', $tenant, ['k' => 'v']));

    expect($entry->user_id)->toBeNull()
        ->and($entry->ip_address)->toBeNull()
        ->and($entry->user_agent)->toBeNull()
        ->and($entry->tenant_id)->toBe($tenant->id)
        ->and($entry->payload)->toBe(['k' => 'v']);
});

it('clears the flag after an exception inside run', function () {
    expect(BackgroundWork::active())->toBeFalse();

    expect(fn () => BackgroundWork::run(fn () => throw new RuntimeException('boom')))
        ->toThrow(RuntimeException::class, 'boom');

    expect(BackgroundWork::active())->toBeFalse();
});

it('stays active until the outermost run finishes', function () {
    BackgroundWork::run(function () {
        BackgroundWork::run(fn () => null);

        expect(BackgroundWork::active())->toBeTrue();
    });

    expect(BackgroundWork::active())->toBeFalse();
});

it('drains the queue through CronRunner without the signed-in person on what a job writes', function () {
    config(['queue.default' => 'database']);
    Cache::flush();

    $tenant = createTenant();
    $user = actingAsUser([], $tenant);
    asRequestFrom('203.0.113.7', 'ExampleBrowser/1.0');

    WritesAnAuditEntryJob::dispatch($tenant->id);

    $result = app(CronRunner::class)->queue(5);

    expect($result)->not->toBeNull();

    $entry = AuditLog::withoutGlobalScopes()->where('action', 'test.background_entry')->firstOrFail();

    expect($entry->user_id)->toBeNull()
        ->and($entry->ip_address)->toBeNull()
        ->and($entry->user_agent)->toBeNull()
        ->and($entry->tenant_id)->toBe($tenant->id)
        ->and(BackgroundWork::active())->toBeFalse()
        ->and($user->id)->not->toBeNull();
});
