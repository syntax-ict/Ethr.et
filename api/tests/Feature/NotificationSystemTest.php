<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollRun;
use App\Notifications\LeaveApprovedNotification;
use App\Notifications\LeaveRequestedNotification;
use App\Notifications\PayrollProcessedNotification;
use Illuminate\Support\Facades\Notification;

// ── Notification CRUD ──

test('user can list notifications', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);

    $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id]);
    $lr = LeaveRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
    ]);

    $user->notify(new LeaveApprovedNotification($lr));

    test()->actingAs($user);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/notifications");

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.type'))->toBe('LeaveApprovedNotification');
});

test('user can get unread count', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);

    $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id]);
    $lr = LeaveRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
    ]);

    $user->notify(new LeaveApprovedNotification($lr));
    $user->notify(new LeaveApprovedNotification($lr));

    test()->actingAs($user);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/notifications/unread-count");

    $response->assertOk();
    expect($response->json('count'))->toBe(2);
});

test('user can mark notification as read', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);

    $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id]);
    $lr = LeaveRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
    ]);

    $user->notify(new LeaveApprovedNotification($lr));

    test()->actingAs($user);

    $notification = $user->notifications()->first();

    $response = test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/notifications/{$notification->id}/read");

    $response->assertOk();
    expect($response->json('read_at'))->not->toBeNull();
});

test('user can mark all notifications as read', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);

    $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id]);
    $lr = LeaveRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
    ]);

    $user->notify(new LeaveApprovedNotification($lr));
    $user->notify(new LeaveApprovedNotification($lr));

    test()->actingAs($user);

    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/notifications/read-all")
        ->assertOk();

    expect($user->unreadNotifications()->count())->toBe(0);
});

// ── Notification Types ──

test('leave requested notification has correct data', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Annual']);
    $lr = LeaveRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
    ]);

    $notification = new LeaveRequestedNotification($lr);
    $data = $notification->toArray($employee);

    expect($data)->toHaveKey('leave_request_id', $lr->public_id);
    expect($data)->toHaveKey('leave_type', 'Annual');
    expect($data)->toHaveKey('message');
});

test('payroll processed notification has correct data', function () {
    $tenant = createTenant();
    $run = PayrollRun::factory()->create([
        'tenant_id' => $tenant->id,
        'period_label' => 'June 2026',
    ]);

    $notification = new PayrollProcessedNotification($run);
    $data = $notification->toArray($run);

    expect($data)->toHaveKey('period', 'June 2026');
    expect($data)->toHaveKey('message');
});

test('leave notification sends via database and mail', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id]);
    $lr = LeaveRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
    ]);

    $notification = new LeaveRequestedNotification($lr);
    $channels = $notification->via($employee);

    expect($channels)->toContain('database');
    expect($channels)->toContain('mail');
});

test('payroll notification sends via database only', function () {
    $tenant = createTenant();
    $run = PayrollRun::factory()->create(['tenant_id' => $tenant->id]);

    $notification = new PayrollProcessedNotification($run);
    $channels = $notification->via($run);

    expect($channels)->toContain('database');
    expect($channels)->not->toContain('mail');
});

// ── Auth ──

test('notifications require authentication', function () {
    $tenant = createTenant(['subdomain' => 'authtest']);
    test()->getJson('http://authtest.ethr.test/api/v1/notifications')
        ->assertUnauthorized();
});
