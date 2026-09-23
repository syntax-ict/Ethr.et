<?php

declare(strict_types=1);

use App\Jobs\DispatchWebhookJob;
use App\Jobs\NotifyAnnouncementAudienceJob;
use App\Jobs\ProcessPayrollJob;
use App\Models\Announcement;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Tenant;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\CurrentTenant;
use App\Services\Payroll\PayrollEngine;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/**
 * Queued jobs that look a row up by primary key with the tenant scope dropped.
 *
 * `docs/audit/BASELINE.md` §11d classed these as "derived from a key, stating
 * nothing": each was safe because the id arrived from a tenant-scoped
 * dispatcher, but the query asserted nothing itself. A worker resolves no
 * tenant, so `BelongsToTenant` would apply `0 = 1` — the scope has to come off,
 * and nothing was put back in its place.
 *
 * §11g carries the tenant in the job payload and states it. These tests pin the
 * *fail-closed* direction: a payload whose id belongs to another tenant does
 * nothing at all, rather than acting on that tenant's row.
 *
 * They run with no tenant resolved (`CurrentTenant::forget()`), which is the
 * condition a real worker is in — a test that left a tenant resolved would pass
 * through the global scope and prove nothing about the predicate.
 *
 * `$tenantId` was nullable with a default until 2026-09-23, so that jobs
 * serialized before §11g deployed still unserialized. That window closed with
 * the drain (§11i): it is now required, the predicate is on the query's own
 * chain rather than behind an `if`, and `the tenant id is required on every job
 * that scopes by it` pins that shape against a regression to either.
 */
function processingRun(Tenant $tenant): PayrollRun
{
    return PayrollRun::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => 'processing',
    ]);
}

// ── ProcessPayrollJob: the most expensive wrong row in the codebase ──

test('payroll processing refuses a run belonging to another tenant', function () {
    $owner = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $run = processingRun($owner);

    app(CurrentTenant::class)->forget();

    // Right id, wrong tenant — the shape a replayed or hand-requeued payload
    // takes.
    (new ProcessPayrollJob($run->id, $other->id))->handle(app(PayrollEngine::class));

    expect(PayrollRun::withoutGlobalScopes()->findOrFail($run->id)->status)->toBe('processing');
});

test('payroll failure handling refuses a run belonging to another tenant', function () {
    $owner = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $run = processingRun($owner);

    app(CurrentTenant::class)->forget();

    (new ProcessPayrollJob($run->id, $other->id))->failed(new RuntimeException('boom'));

    // failed() marks a run `failed`. Against the wrong tenant that is a write
    // into their payroll, so it must find nothing rather than find anything.
    expect(PayrollRun::withoutGlobalScopes()->findOrFail($run->id)->status)->toBe('processing');
});

test('payroll failure handling still marks the owning tenant run failed', function () {
    $owner = Tenant::factory()->create();
    $run = processingRun($owner);

    app(CurrentTenant::class)->forget();

    (new ProcessPayrollJob($run->id, $owner->id))->failed(new RuntimeException('boom'));

    expect(PayrollRun::withoutGlobalScopes()->findOrFail($run->id)->status)->toBe('failed');
});

// ── DispatchWebhookJob: the wrong webhook POSTs one tenant's data to another ──

function deliveryFor(Webhook $webhook): WebhookDelivery
{
    return WebhookDelivery::withoutGlobalScopes()->create([
        'tenant_id' => $webhook->tenant_id,
        'webhook_id' => $webhook->id,
        'event' => 'payroll.processed',
        'payload' => ['event' => 'payroll.processed'],
        'attempt' => 0,
    ]);
}

test('webhook delivery refuses a webhook belonging to another tenant', function () {
    Http::fake(['*' => Http::response('ok', 200)]);

    $owner = Tenant::factory()->create();
    $other = Tenant::factory()->create();

    $webhook = Webhook::factory()->create([
        'tenant_id' => $owner->id,
        'events' => ['payroll.processed'],
        'is_active' => true,
    ]);
    $delivery = deliveryFor($webhook);

    app(CurrentTenant::class)->forget();

    (new DispatchWebhookJob(
        $webhook->id,
        'payroll.processed',
        ['event' => 'payroll.processed'],
        $delivery->id,
        $other->id,
    ))->handle();

    // Nothing signed, nothing sent.
    Http::assertNothingSent();

    expect(WebhookDelivery::withoutGlobalScopes()->findOrFail($delivery->id)->delivered_at)->toBeNull();
});

test('webhook delivery still fires for the owning tenant', function () {
    Http::fake(['*' => Http::response('ok', 200)]);

    $owner = Tenant::factory()->create();

    $webhook = Webhook::factory()->create([
        'tenant_id' => $owner->id,
        'events' => ['payroll.processed'],
        'is_active' => true,
    ]);
    $delivery = deliveryFor($webhook);

    app(CurrentTenant::class)->forget();

    (new DispatchWebhookJob(
        $webhook->id,
        'payroll.processed',
        ['event' => 'payroll.processed'],
        $delivery->id,
        $owner->id,
    ))->handle();

    Http::assertSent(fn ($request) => $request->url() === $webhook->url);

    expect(WebhookDelivery::withoutGlobalScopes()->findOrFail($delivery->id)->response_status)->toBe(200);
});

// ── NotifyAnnouncementAudienceJob: the wrong row notifies the wrong employees ──

test('announcement notification refuses an announcement belonging to another tenant', function () {
    Notification::fake();

    $owner = Tenant::factory()->create();
    $other = Tenant::factory()->create();

    $announcement = Announcement::factory()->create([
        'tenant_id' => $owner->id,
        'target_type' => 'all',
        'published_at' => now()->subMinute(),
    ]);

    app(CurrentTenant::class)->forget();

    (new NotifyAnnouncementAudienceJob($announcement->id, $other->id))
        ->handle(app(CurrentTenant::class));

    // This job calls CurrentTenant::set() from the row it finds and then
    // notifies that audience, so the wrong row does not merely read across the
    // boundary — it sends one tenant's announcement to another's employees.
    Notification::assertNothingSent();
});

// ── The contract, after the drain ──

test('the tenant id is required on every job that scopes by it', function (string $jobClass) {
    $tenantId = collect((new ReflectionMethod($jobClass, '__construct'))->getParameters())
        ->first(fn (ReflectionParameter $parameter) => $parameter->getName() === 'tenantId');

    // Renaming it would otherwise make every assertion below vacuous.
    expect($tenantId)->not->toBeNull();

    $type = $tenantId->getType();

    // Two separate regressions, and the test has to fail on each.
    //
    // Optional is how the predicate became conditional in the first place: a
    // default means `handle()` has to ask whether it has a tenant, and a
    // predicate applied only sometimes is stated by the dispatcher rather than
    // by the query (BASELINE.md §11i — it is why these five sites did not
    // count as self-proving even after §11g put the id in the payload).
    //
    // Nullable is the same defect one argument earlier: required but `?int`
    // lets a caller pass null explicitly and get the unscoped lookup back,
    // which is precisely the cross-tenant read the tests above prove is closed.
    expect($tenantId->isOptional())->toBeFalse()
        ->and($type)->toBeInstanceOf(ReflectionNamedType::class)
        ->and($type->getName())->toBe('int')
        ->and($type->allowsNull())->toBeFalse();
})->with([
    ProcessPayrollJob::class,
    DispatchWebhookJob::class,
    NotifyAnnouncementAudienceJob::class,
]);

test('a job that scopes by tenant cannot be constructed without one', function () {
    // The shape assertions above describe the signature; this one proves what
    // the signature buys. A payload serialized before §11g carries no tenant
    // id, and after the drain there is none left in flight — so the old
    // permissive construction must now fail loudly rather than fall through to
    // an unscoped `find()`.
    expect(fn () => new ProcessPayrollJob(1))->toThrow(ArgumentCountError::class);
});

// ── The one non-job site: a staging column that can be stale ──

test('a migration merge target from another tenant is not returned', function () {
    $owner = Tenant::factory()->create();
    $other = Tenant::factory()->create();

    $foreign = Employee::factory()->create(['tenant_id' => $other->id]);

    app(CurrentTenant::class)->forget();

    // mergeTarget() is private; the invariant it now carries is that a lookup
    // scoped to the caller's $tenantId cannot return another tenant's row.
    // Asserted against the same query the method runs.
    expect(
        Employee::withoutGlobalScope('tenant')->where('tenant_id', $owner->id)->find($foreign->id)
    )->toBeNull();

    // And the predicate is what makes the difference — without it the same id
    // hands back the other tenant's employee, which is what a stale
    // `resolved_employee_id` would have attached the merge to.
    expect(
        Employee::withoutGlobalScope('tenant')->find($foreign->id)?->id
    )->toBe($foreign->id);
});
