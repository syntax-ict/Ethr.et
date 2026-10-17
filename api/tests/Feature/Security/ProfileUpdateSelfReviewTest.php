<?php

declare(strict_types=1);

use App\Enums\ProfileUpdateStatus;
use App\Enums\UserRole;
use App\Jobs\SendApprovalRemindersJob;
use App\Models\Employee;
use App\Models\ProfileUpdateRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ApprovalReminderNotification;
use App\Notifications\ProfileUpdateRequestedNotification;
use App\Services\CurrentTenant;
use Illuminate\Support\Facades\Notification;

/**
 * Capability audit 2026-10-17: a profile change could be decided by the person
 * it was about, or by the person who filed it.
 *
 * Reviewing a profile change is `employee.update`, which every HR admin holds,
 * and the review endpoint checked nothing else. An HR admin could put their own
 * bank account or TIN through the queue and approve it themselves, both on
 * `POST /profile-update-requests/{id}/review` and on `POST /approvals/batch`,
 * and the approvals queue listed their own requests for them to do so. Leave
 * and attendance corrections already refused this.
 */

/** @return array{0: Tenant, 1: User, 2: Employee} An HR admin who is also an employee. */
function selfReviewHrAdmin(?Tenant $tenant = null): array
{
    $tenant ??= createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::HR_ADMIN, 'employee_id' => $employee->id], $tenant);
    $employee->update(['user_id' => $user->id]);

    return [$tenant, $user, $employee];
}

function selfReviewPending(Employee $employee, ?User $requester, string $field = 'bank_account_number'): ProfileUpdateRequest
{
    return ProfileUpdateRequest::factory()->forField($field)->create([
        'tenant_id' => $employee->tenant_id,
        'employee_id' => $employee->id,
        'requested_by' => $requester?->id,
        'old_value' => null,
        'new_value' => '1000999888777',
    ]);
}

function selfReviewUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/{$path}";
}

test('an HR admin cannot approve a change to their own profile', function () {
    [$tenant, $hr, $employee] = selfReviewHrAdmin();
    $pending = selfReviewPending($employee, $hr);

    test()->actingAs($hr);

    test()->postJson(selfReviewUrl($tenant, "profile-update-requests/{$pending->public_id}/review"), ['action' => 'approve'])
        ->assertStatus(403)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://ethr.et/errors/self-approval')
        ->assertJsonPath('status', 403)
        ->assertJsonPath('detail', __('employee.cannot_review_own_profile'));

    expect($pending->fresh()->status)->toBe(ProfileUpdateStatus::PENDING)
        ->and($employee->fresh()->bankDetails()->count())->toBe(0);
});

test('an HR admin cannot reject a change to their own profile either', function () {
    [$tenant, $hr, $employee] = selfReviewHrAdmin();
    $pending = selfReviewPending($employee, $hr);

    test()->actingAs($hr);

    test()->postJson(selfReviewUrl($tenant, "profile-update-requests/{$pending->public_id}/review"), ['action' => 'reject'])
        ->assertStatus(403)
        ->assertJsonPath('type', 'https://ethr.et/errors/self-approval');

    expect($pending->fresh()->status)->toBe(ProfileUpdateStatus::PENDING);
});

test('the employee a change is about cannot decide it even when someone else filed it', function () {
    [$tenant, $hr, $employee] = selfReviewHrAdmin();
    [, $colleague] = selfReviewHrAdmin($tenant);
    $pending = selfReviewPending($employee, $colleague);

    test()->actingAs($hr);

    test()->postJson(selfReviewUrl($tenant, "profile-update-requests/{$pending->public_id}/review"), ['action' => 'approve'])
        ->assertStatus(403)
        ->assertJsonPath('detail', __('employee.cannot_review_own_profile'));

    expect($pending->fresh()->status)->toBe(ProfileUpdateStatus::PENDING);
});

test('the person who filed a change cannot decide it', function () {
    [$tenant, $hr] = selfReviewHrAdmin();
    $subject = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $pending = selfReviewPending($subject, $hr);

    test()->actingAs($hr);

    test()->postJson(selfReviewUrl($tenant, "profile-update-requests/{$pending->public_id}/review"), ['action' => 'approve'])
        ->assertStatus(403)
        ->assertJsonPath('detail', __('employee.cannot_review_filed_profile'));

    expect($pending->fresh()->status)->toBe(ProfileUpdateStatus::PENDING);
});

test('an HR admin cannot batch-approve a change to their own profile', function () {
    [$tenant, $hr, $employee] = selfReviewHrAdmin();
    $pending = selfReviewPending($employee, $hr);

    test()->actingAs($hr);

    $response = test()->postJson(selfReviewUrl($tenant, 'approvals/batch'), [
        'actions' => [
            ['type' => 'profile_update', 'public_id' => $pending->public_id, 'action' => 'approve'],
        ],
    ]);

    $response->assertOk();
    expect($response->json('results.0.status'))->toBe('error')
        ->and($response->json('results.0.detail'))->toBe(__('employee.cannot_review_own_profile'))
        ->and($pending->fresh()->status)->toBe(ProfileUpdateStatus::PENDING)
        ->and($employee->fresh()->bankDetails()->count())->toBe(0);
});

test('the filer cannot batch-reject a change they filed', function () {
    [$tenant, $hr] = selfReviewHrAdmin();
    $subject = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $pending = selfReviewPending($subject, $hr);

    test()->actingAs($hr);

    $response = test()->postJson(selfReviewUrl($tenant, 'approvals/batch'), [
        'actions' => [
            ['type' => 'profile_update', 'public_id' => $pending->public_id, 'action' => 'reject'],
        ],
    ]);

    $response->assertOk();
    expect($response->json('results.0.status'))->toBe('error')
        ->and($pending->fresh()->status)->toBe(ProfileUpdateStatus::PENDING);
});

test('another HR admin can still approve the change', function () {
    [$tenant, $hr, $employee] = selfReviewHrAdmin();
    [, $colleague] = selfReviewHrAdmin($tenant);
    $pending = selfReviewPending($employee, $hr);

    test()->actingAs($colleague);

    test()->postJson(selfReviewUrl($tenant, "profile-update-requests/{$pending->public_id}/review"), ['action' => 'approve'])
        ->assertOk();

    expect($pending->fresh()->status)->toBe(ProfileUpdateStatus::APPROVED)
        ->and($employee->fresh()->bankDetails()->first()?->account_number)->toBe('1000999888777');
});

test('another HR admin can still batch-approve the change', function () {
    [$tenant, $hr, $employee] = selfReviewHrAdmin();
    [, $colleague] = selfReviewHrAdmin($tenant);
    $pending = selfReviewPending($employee, $hr, 'name');

    test()->actingAs($colleague);

    test()->postJson(selfReviewUrl($tenant, 'approvals/batch'), [
        'actions' => [
            ['type' => 'profile_update', 'public_id' => $pending->public_id, 'action' => 'approve'],
        ],
    ])->assertOk()->assertJsonPath('results.0.status', 'approved');

    expect($employee->fresh()->name)->toBe('1000999888777');
});

test('the approvals queue and the dashboard count leave out changes the caller cannot decide', function () {
    [$tenant, $hr, $employee] = selfReviewHrAdmin();
    $own = selfReviewPending($employee, $hr);
    $filed = selfReviewPending(Employee::factory()->create(['tenant_id' => $tenant->id]), $hr);
    $other = selfReviewPending(Employee::factory()->create(['tenant_id' => $tenant->id]), null);

    test()->actingAs($hr);

    $queued = collect(test()->getJson(selfReviewUrl($tenant, 'approvals/pending'))->assertOk()->json('items'))
        ->where('type', 'profile_update')
        ->pluck('public_id');
    $counts = test()->getJson(selfReviewUrl($tenant, 'dashboard/manager'))->assertOk()->json('pending_approvals');

    // A request with no recorded filer stays decidable: `requested_by != ?` alone
    // would drop it, because NULL compares unknown rather than unequal.
    expect($queued->all())->toBe([$other->public_id])
        ->and($queued)->not->toContain($own->public_id)
        ->and($queued)->not->toContain($filed->public_id)
        ->and($counts['profile_update'])->toBe(1);
});

test('another HR admin sees the change in their queue', function () {
    [$tenant, $hr, $employee] = selfReviewHrAdmin();
    [, $colleague] = selfReviewHrAdmin($tenant);
    $own = selfReviewPending($employee, $hr);

    test()->actingAs($colleague);

    $queued = collect(test()->getJson(selfReviewUrl($tenant, 'approvals/pending'))->assertOk()->json('items'))
        ->pluck('public_id');

    expect($queued)->toContain($own->public_id)
        ->and(test()->getJson(selfReviewUrl($tenant, 'dashboard/manager'))->json('pending_approvals.profile_update'))->toBe(1);
});

test('the approval reminder does not chase a reviewer for their own change', function () {
    Notification::fake();

    [$tenant, $hr, $employee] = selfReviewHrAdmin();
    [, $colleague] = selfReviewHrAdmin($tenant);
    selfReviewPending($employee, $hr)->forceFill(['created_at' => now()->subHours(72)])->save();

    (new SendApprovalRemindersJob($tenant->id))->handle(app(CurrentTenant::class));

    Notification::assertSentTo($colleague, ApprovalReminderNotification::class);
    Notification::assertNotSentTo($hr, ApprovalReminderNotification::class);
});

test('staging a change does not ask its own author to review it', function () {
    Notification::fake();

    [$tenant, $hr] = selfReviewHrAdmin();
    [, $colleague] = selfReviewHrAdmin($tenant);

    test()->actingAs($hr);
    test()->putJson(selfReviewUrl($tenant, 'profile'), ['bank_account_number' => '1000555444333'])->assertOk();

    Notification::assertSentTo($colleague, ProfileUpdateRequestedNotification::class);
    Notification::assertNotSentTo($hr, ProfileUpdateRequestedNotification::class);
});
