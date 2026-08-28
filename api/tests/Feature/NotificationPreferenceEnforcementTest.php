<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\NotificationPreference;
use App\Notifications\LeaveApprovedNotification;
use App\Services\CurrentTenant;
use Illuminate\Support\Facades\Notification;

/**
 * The preference matrix was settable and rendered in Settings since Phase 5, but
 * every notification hard-coded its own `via()`, so a stored row changed nothing.
 * These tests pin the wiring in both directions.
 */
function prefFixture(): array
{
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    $employee->update(['user_id' => $user->id]);

    return [$tenant, $employee, $user];
}

function leaveRequestFor(int $tenantId, int $employeeId): LeaveRequest
{
    $leaveType = LeaveType::factory()->create(['tenant_id' => $tenantId]);

    return LeaveRequest::factory()->create([
        'tenant_id' => $tenantId,
        'employee_id' => $employeeId,
        'leave_type_id' => $leaveType->id,
    ]);
}

test('email is delivered by default when the user has set no preference', function () {
    [$tenant, $employee, $user] = prefFixture();
    $lr = leaveRequestFor($tenant->id, $employee->id);

    $channels = (new LeaveApprovedNotification($lr))->via($user);

    expect($channels)->toContain('mail');
    expect($channels)->toContain('database');
});

test('disabling the email channel removes mail from via()', function () {
    [$tenant, $employee, $user] = prefFixture();
    $lr = leaveRequestFor($tenant->id, $employee->id);

    NotificationPreference::create([
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'notification_type' => 'leave_approved',
        'channel' => 'email',
        'enabled' => false,
    ]);

    $channels = (new LeaveApprovedNotification($lr))->via($user);

    expect($channels)->not->toContain('mail');
    // In-app is the record of what happened and stays regardless.
    expect($channels)->toContain('database');
});

test('a preference disables only its own notification type', function () {
    [$tenant, $employee, $user] = prefFixture();
    $lr = leaveRequestFor($tenant->id, $employee->id);

    NotificationPreference::create([
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'notification_type' => 'payslip_available',
        'channel' => 'email',
        'enabled' => false,
    ]);

    expect((new LeaveApprovedNotification($lr))->via($user))->toContain('mail');
});

test('turning the email channel back on restores mail', function () {
    [$tenant, $employee, $user] = prefFixture();
    $lr = leaveRequestFor($tenant->id, $employee->id);

    NotificationPreference::create([
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'notification_type' => 'leave_approved',
        'channel' => 'email',
        'enabled' => true,
    ]);

    expect((new LeaveApprovedNotification($lr))->via($user))->toContain('mail');
});

test('the preferences endpoint round-trips into actual delivery', function () {
    [$tenant, $employee, $user] = prefFixture();
    $lr = leaveRequestFor($tenant->id, $employee->id);

    test()->actingAs($user);

    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/notifications/preferences", [
        'preferences' => [
            'leave_approved' => ['email' => false],
        ],
    ])->assertOk();

    expect((new LeaveApprovedNotification($lr))->via($user->fresh()))->not->toContain('mail');
});

test('a preference is honoured with no tenant bound, as on a queue worker', function () {
    [$tenant, $employee, $user] = prefFixture();
    $lr = leaveRequestFor($tenant->id, $employee->id);

    NotificationPreference::create([
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'notification_type' => 'leave_approved',
        'channel' => 'email',
        'enabled' => false,
    ]);

    // NotificationPreference is BelongsToTenant. A queued notification is delivered
    // by a worker with no request to resolve CurrentTenant from, and under the
    // global scope the lookup returned zero rows there — indistinguishable from
    // "no preferences set", so the opt-out was silently ignored on exactly the
    // path most notifications take. Found by running the product, not by a test.
    app(CurrentTenant::class)->forget();

    expect((new LeaveApprovedNotification($lr))->via($user))->not->toContain('mail');
});

test('in_app cannot be switched off through the endpoint', function () {
    [$tenant, $employee, $user] = prefFixture();
    $lr = leaveRequestFor($tenant->id, $employee->id);

    test()->actingAs($user);

    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/notifications/preferences", [
        'preferences' => [
            'leave_approved' => ['in_app' => false],
        ],
    ])->assertOk();

    expect((new LeaveApprovedNotification($lr))->via($user->fresh()))->toContain('database');
});
