<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Jobs\SendWebPush;
use App\Models\AttendanceSetting;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\PushSubscription;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\MissingPunchNotification;
use App\Services\Push\WebPushSender;
use Carbon\Carbon;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Queue;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\WebPush;

/**
 * Web push: notifications on a phone or browser while ETHR is closed. Off
 * until the VAPID keys are set; every in-app notification is pushed to the
 * user's subscribed devices; punch reminders go out on schedule.
 */
afterEach(fn () => Carbon::setTestNow());

function wpUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1{$path}";
}

function wpConfigure(): void
{
    config(['webpush.public_key' => 'BPublicKeyForTests', 'webpush.private_key' => 'PrivateKeyForTests']);
}

function wpSubscribe(Tenant $tenant, User $user, string $endpoint = 'https://push.example.test/abc'): PushSubscription
{
    return PushSubscription::create([
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'endpoint' => $endpoint,
        'endpoint_hash' => PushSubscription::hashEndpoint($endpoint),
        'public_key' => 'p256dh-key',
        'auth_token' => 'auth-key',
    ]);
}

/** A sender whose pushes are recorded instead of sent. */
class RecordingWebPushSender extends WebPushSender
{
    /** @var list<array{user: int, payload: array<string, string>}> */
    public array $sent = [];

    public function toUser(int $userId, array $payload): int
    {
        $this->sent[] = ['user' => $userId, 'payload' => $payload];

        return 1;
    }
}

test('push is off, and says so, until the keys are set', function () {
    $tenant = createTenant();
    actingAsUser([], $tenant);

    test()->getJson(wpUrl($tenant, '/push/config'))
        ->assertOk()
        ->assertJsonPath('enabled', false)
        ->assertJsonPath('public_key', null);
    test()->postJson(wpUrl($tenant, '/push/subscriptions'), [
        'endpoint' => 'https://push.example.test/1', 'keys' => ['p256dh' => 'a', 'auth' => 'b'],
    ])->assertStatus(409);

    wpConfigure();
    test()->getJson(wpUrl($tenant, '/push/config'))
        ->assertJsonPath('enabled', true)
        ->assertJsonPath('public_key', 'BPublicKeyForTests');
});

test('a browser subscribes, moves to whoever signs in on it, and unsubscribes only itself', function () {
    wpConfigure();
    $tenant = createTenant();
    $first = actingAsUser([], $tenant);
    $body = ['endpoint' => 'https://push.example.test/shared', 'keys' => ['p256dh' => 'a', 'auth' => 'b']];

    test()->postJson(wpUrl($tenant, '/push/subscriptions'), $body)->assertCreated();
    expect(PushSubscription::query()->sole()->user_id)->toBe($first->id);

    $second = actingAsUser([], $tenant);
    test()->postJson(wpUrl($tenant, '/push/subscriptions'), $body)->assertCreated();
    expect(PushSubscription::query()->sole()->user_id)->toBe($second->id);

    test()->actingAs($first)->deleteJson(wpUrl($tenant, '/push/subscriptions'), ['endpoint' => $body['endpoint']])->assertNoContent();
    expect(PushSubscription::query()->count())->toBe(1);

    test()->actingAs($second)->deleteJson(wpUrl($tenant, '/push/subscriptions'), ['endpoint' => $body['endpoint']])->assertNoContent();
    expect(PushSubscription::query()->count())->toBe(0);
});

test('an in-app notification reaches the user\'s subscribed devices as a push', function () {
    wpConfigure();
    Queue::fake();
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $subscribed = createUser(['role' => UserRole::HR_ADMIN], $tenant);
    $notSubscribed = createUser(['role' => UserRole::HR_ADMIN], $tenant);
    wpSubscribe($tenant, $subscribed);

    $subscribed->notify(new MissingPunchNotification($employee, '2026-10-12', 'missing_check_in'));
    $notSubscribed->notify(new MissingPunchNotification($employee, '2026-10-12', 'missing_check_in'));

    Queue::assertPushed(SendWebPush::class, 1);
    Queue::assertPushed(SendWebPush::class, fn (SendWebPush $job): bool => $job->userId === $subscribed->id
        && $job->tenantId === $tenant->id
        && $job->payload['url'] === '/attendance'
        && str_contains($job->payload['body'], $employee->name));
});

test('nothing is pushed while push is not configured', function () {
    Queue::fake();
    $tenant = createTenant();
    $user = createUser(['role' => UserRole::HR_ADMIN], $tenant);
    wpSubscribe($tenant, $user);

    $user->notify(new MissingPunchNotification(Employee::factory()->create(['tenant_id' => $tenant->id]), '2026-10-12', 'missing_check_in'));

    Queue::assertNotPushed(SendWebPush::class);
});

test('an endpoint the push service reports gone is forgotten', function () {
    wpConfigure();
    $tenant = createTenant();
    $user = createUser([], $tenant);
    $gone = wpSubscribe($tenant, $user, 'https://push.example.test/gone');
    $live = wpSubscribe($tenant, $user, 'https://push.example.test/live');

    $client = Mockery::mock(WebPush::class);
    $client->shouldReceive('queueNotification')->twice();
    $client->shouldReceive('flush')->once()->andReturnUsing(function () {
        yield new MessageSentReport(new Request('POST', 'https://push.example.test/gone'), new Response(410), false, 'Gone');
        yield new MessageSentReport(new Request('POST', 'https://push.example.test/live'), new Response(201), true);
    });
    $sender = new class($client) extends WebPushSender
    {
        public function __construct(private readonly WebPush $fake) {}

        protected function client(): WebPush
        {
            return $this->fake;
        }
    };

    expect($sender->toUser($user->id, ['title' => 'ETHR', 'body' => 'Hello']))->toBe(1)
        ->and(PushSubscription::query()->whereKey($gone->id)->exists())->toBeFalse()
        ->and($live->fresh()->last_used_at)->not->toBeNull();
});

test('the real push client builds with real VAPID keys', function () {
    // Every other test here replaces client() with a fake, so a library
    // upgrade that changes the WebPush constructor (web-push 11 took a
    // PSR-18 client where 9 took a timeout) passed them all while every real
    // send threw. This one builds the client the way production does. The
    // pair is a throwaway made for this test, fixed rather than generated
    // because key generation on Windows PHP needs OPENSSL_CONF set.
    config([
        'webpush.subject' => 'mailto:support@ethr.et',
        'webpush.public_key' => 'BM0njQKmQ2DRMx1buCPUQuFHWtw136udNxC0WHsuh8LQjfXAqs-QsOcTzziQfksBZOOUxcgfB4Sxb9wObr8kSlE',
        'webpush.private_key' => '-70dx5Toi2lpQGyd1KVhRQCq3Nmu5BXFY3TohckVek0',
    ]);
    $sender = new class extends WebPushSender
    {
        public function build(): WebPush
        {
            return $this->client();
        }
    };

    expect($sender->build())->toBeInstanceOf(WebPush::class);
});

test('punch reminders are pushed once, in their window, to subscribed staff', function () {
    wpConfigure();
    $sender = new RecordingWebPushSender;
    app()->instance(WebPushSender::class, $sender);

    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $shift = Shift::factory()->create(['tenant_id' => $tenant->id, 'start_time' => '08:30', 'end_time' => '17:30', 'grace_minutes' => 10, 'working_days' => '1,2,3,4,5']);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
    ShiftAssignment::factory()->create(['tenant_id' => $tenant->id, 'shift_id' => $shift->id, 'assignable_type' => Employee::class, 'assignable_id' => $employee->id, 'effective_from' => '2026-10-01']);
    AttendanceSetting::create(['tenant_id' => $tenant->id, ...AttendanceSetting::defaults(), 'reminder_lead_minutes' => 10]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    wpSubscribe($tenant, $user);

    // Monday 08:21 in Addis Ababa: inside the window that opens ten minutes
    // before the 08:30 start.
    Carbon::setTestNow(Carbon::parse('2026-10-12 08:21', 'Africa/Addis_Ababa')->utc());
    test()->artisan('attendance:push-reminders')->assertSuccessful();
    test()->artisan('attendance:push-reminders')->assertSuccessful();

    expect($sender->sent)->toHaveCount(1)
        ->and($sender->sent[0]['user'])->toBe($user->id)
        ->and($sender->sent[0]['payload']['body'])->toContain('08:30');

    // 08:42, grace over, still not checked in.
    Carbon::setTestNow(Carbon::parse('2026-10-12 08:42', 'Africa/Addis_Ababa')->utc());
    test()->artisan('attendance:push-reminders')->assertSuccessful();
    expect($sender->sent)->toHaveCount(2)
        ->and($sender->sent[1]['payload']['tag'])->toBe('punch-not_checked_in');
});
