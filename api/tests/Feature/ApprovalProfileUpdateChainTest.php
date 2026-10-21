<?php

declare(strict_types=1);

use App\Enums\ApproverKind;
use App\Enums\ProfileUpdateStatus;
use App\Enums\UserRole;
use App\Models\ApprovalInstance;
use App\Models\ApprovalWorkflow;
use App\Models\Employee;
use App\Models\ProfileUpdateRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ProfileUpdateRequestedNotification;
use App\Services\Profile\ProfileUpdateRequestService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

/**
 * Profile changes on the approval engine (docs/APPROVAL-ENGINE-DESIGN.md,
 * Phase B). With no chain they are reviewed in one step by HR, as before;
 * with one, the change is applied only at the last step.
 */
function profileChainStage(Tenant $tenant, Employee $employee, User $requester, string $name): ProfileUpdateRequest
{
    test()->actingAs($requester);

    return app(ProfileUpdateRequestService::class)
        ->stage($employee, $requester, ['name' => $name])
        ->firstOrFail();
}

function profileChainReview(Tenant $tenant, User $reviewer, ProfileUpdateRequest $request, string $action = 'approve'): TestResponse
{
    app('auth')->forgetGuards();

    return test()->actingAs($reviewer)->postJson(
        "http://{$tenant->subdomain}.ethr.test/api/v1/profile-update-requests/{$request->public_id}/review",
        ['action' => $action, 'notes' => 'Checked against the ID'],
    );
}

/** @param  list<array{0: ApproverKind, 1?: string}>  $steps */
function profileChainWorkflow(Tenant $tenant, array $steps): void
{
    $workflow = ApprovalWorkflow::create(['tenant_id' => $tenant->id, 'request_type' => 'profile_update', 'name' => 'Two reviewers']);
    foreach ($steps as $i => $step) {
        $workflow->steps()->create([
            'tenant_id' => $tenant->id,
            'position' => $i + 1,
            'approver_kind' => $step[0],
            'approver_ref' => $step[1] ?? null,
        ]);
    }
}

test('with no chain a profile change is reviewed in one step, as before', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Old Name']);
    $requester = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    $hr = createUser(['role' => UserRole::HR_ADMIN], $tenant);

    $request = profileChainStage($tenant, $employee, $requester, 'New Name');

    profileChainReview($tenant, $hr, $request)
        ->assertOk()
        ->assertJsonPath('status', 'approved')
        ->assertJsonPath('approval.steps', 1);

    expect($employee->refresh()->name)->toBe('New Name');
});

test('a two-step chain applies the change only at the last step', function () {
    $tenant = createTenant();
    profileChainWorkflow($tenant, [[ApproverKind::PERMISSION], [ApproverKind::PERMISSION]]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Old Name']);
    $requester = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    $hr = createUser(['role' => UserRole::HR_ADMIN], $tenant);
    $hr2 = createUser(['role' => UserRole::HR_ADMIN], $tenant);

    $request = profileChainStage($tenant, $employee, $requester, 'New Name');

    profileChainReview($tenant, $hr, $request)
        ->assertOk()
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('approval.step', 2);
    expect($employee->refresh()->name)->toBe('Old Name');

    profileChainReview($tenant, $hr, $request)
        ->assertForbidden()
        ->assertJsonPath('type', 'https://ethr.et/errors/not-current-approver');

    profileChainReview($tenant, $hr2, $request)
        ->assertOk()
        ->assertJsonPath('status', 'approved');
    expect($employee->refresh()->name)->toBe('New Name');
});

test('the inbox shows a profile change at its step and only to that step\'s approvers', function () {
    $tenant = createTenant();
    profileChainWorkflow($tenant, [[ApproverKind::PERMISSION], [ApproverKind::PERMISSION]]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $requester = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    $hr = createUser(['role' => UserRole::HR_ADMIN], $tenant);
    $hr2 = createUser(['role' => UserRole::HR_ADMIN], $tenant);

    $request = profileChainStage($tenant, $employee, $requester, 'New Name');
    profileChainReview($tenant, $hr, $request)->assertOk();

    app('auth')->forgetGuards();
    expect(test()->actingAs($hr)->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/approvals/pending")->json('total'))->toBe(0);

    app('auth')->forgetGuards();
    $items = test()->actingAs($hr2)->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/approvals/pending")->json('items');
    expect($items)->toHaveCount(1)
        ->and($items[0]['type'])->toBe('profile_update')
        ->and($items[0]['step'])->toBe(2)
        ->and($items[0]['steps'])->toBe(2);
});

test('a rejection at the first step ends the change without applying it', function () {
    $tenant = createTenant();
    profileChainWorkflow($tenant, [[ApproverKind::PERMISSION], [ApproverKind::PERMISSION]]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Old Name']);
    $requester = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    $hr = createUser(['role' => UserRole::HR_ADMIN], $tenant);

    $request = profileChainStage($tenant, $employee, $requester, 'New Name');

    profileChainReview($tenant, $hr, $request, 'reject')
        ->assertOk()
        ->assertJsonPath('status', 'rejected')
        ->assertJsonPath('approval.status', 'rejected');
    expect($employee->refresh()->name)->toBe('Old Name');
});

test('resubmitting a field closes the approval of the request it supersedes', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $requester = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);

    $first = profileChainStage($tenant, $employee, $requester, 'First Try');
    profileChainStage($tenant, $employee, $requester, 'Second Try');

    expect($first->refresh()->status)->toBe(ProfileUpdateStatus::REJECTED)
        ->and($first->approvalInstance()->firstOrFail()->status)->toBe(ApprovalInstance::STATUS_REJECTED);
});

test('withdrawing a change closes its approval', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $requester = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);

    $request = profileChainStage($tenant, $employee, $requester, 'New Name');

    test()->actingAs($requester)
        ->deleteJson("http://{$tenant->subdomain}.ethr.test/api/v1/profile-update-requests/{$request->public_id}")
        ->assertOk();

    expect($request->approvalInstance()->firstOrFail()->status)->toBe(ApprovalInstance::STATUS_CANCELLED);
});

test('a chain naming one reviewer tells only them when a change is submitted', function () {
    Notification::fake();

    $tenant = createTenant();
    $named = createUser(['role' => UserRole::HR_ADMIN], $tenant);
    $other = createUser(['role' => UserRole::HR_ADMIN], $tenant);
    profileChainWorkflow($tenant, [[ApproverKind::USER, $named->public_id]]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $requester = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);

    profileChainStage($tenant, $employee, $requester, 'New Name');

    Notification::assertSentTo($named, ProfileUpdateRequestedNotification::class);
    Notification::assertNotSentTo($other, ProfileUpdateRequestedNotification::class);
});

test('the settings API accepts a chain for profile changes', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/settings/approval-workflows", [
        'request_type' => 'profile_update',
        'name' => 'HR then finance',
        'steps' => [
            ['approver_kind' => 'role', 'approver_ref' => 'hr_admin'],
            ['approver_kind' => 'role', 'approver_ref' => 'finance_admin'],
        ],
    ])->assertCreated()->assertJsonPath('request_type', 'profile_update');
});
