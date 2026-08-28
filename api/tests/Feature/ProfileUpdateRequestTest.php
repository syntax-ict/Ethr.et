<?php

declare(strict_types=1);

use App\Enums\ProfileUpdateStatus;
use App\Enums\UserRole;
use App\Models\CustomRole;
use App\Models\Employee;
use App\Models\EmployeeBankDetail;
use App\Models\Permission;
use App\Models\ProfileUpdateRequest;
use App\Notifications\ProfileUpdateApprovedNotification;
use App\Notifications\ProfileUpdateRequestedNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;

/**
 * Regression cover for the gap these tests were written against: PUT /profile
 * reported gated changes as "pending approval" and then discarded them, so an
 * employee's new bank account simply evaporated.
 */
function profileFixture(): array
{
    $tenant = createTenant();
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Abebe Kebede',
    ]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    $employee->update(['user_id' => $user->id]);

    return [$tenant, $employee, $user];
}

// ── Staging ──

test('a gated profile change is persisted rather than discarded', function () {
    [$tenant, $employee, $user] = profileFixture();

    test()->actingAs($user);

    $response = test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/profile", [
        'bank_account_number' => '1000123456789',
    ]);

    $response->assertOk();
    expect($response->json('pending_approval.fields'))->toBe(['bank_account_number']);

    $staged = ProfileUpdateRequest::where('employee_id', $employee->id)->get();
    expect($staged)->toHaveCount(1);
    expect($staged->first()->new_value)->toBe('1000123456789');
    expect($staged->first()->status)->toBe(ProfileUpdateStatus::PENDING);

    // And crucially: not applied yet.
    expect($employee->fresh()->bankDetails()->count())->toBe(0);
});

test('a gated change is not applied to the employee record before review', function () {
    [$tenant, $employee, $user] = profileFixture();

    test()->actingAs($user);

    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/profile", [
        'name' => 'Kebede Abebe',
    ])->assertOk();

    expect($employee->fresh()->name)->toBe('Abebe Kebede');
});

test('non-gated fields still apply immediately', function () {
    [$tenant, $employee, $user] = profileFixture();

    test()->actingAs($user);

    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/profile", [
        'phone' => '+251911223344',
    ])->assertOk();

    expect($employee->fresh()->phone)->toBe('+251911223344');
    expect(ProfileUpdateRequest::where('employee_id', $employee->id)->count())->toBe(0);
});

test('submitting the same field twice supersedes the earlier pending request', function () {
    [$tenant, $employee, $user] = profileFixture();

    test()->actingAs($user);

    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/profile", ['name' => 'First Try'])->assertOk();
    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/profile", ['name' => 'Second Try'])->assertOk();

    $pending = ProfileUpdateRequest::where('employee_id', $employee->id)
        ->where('status', ProfileUpdateStatus::PENDING)
        ->get();

    expect($pending)->toHaveCount(1);
    expect($pending->first()->new_value)->toBe('Second Try');
});

test('submitting the value already on record queues nothing', function () {
    [$tenant, $employee, $user] = profileFixture();

    test()->actingAs($user);

    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/profile", [
        'name' => 'Abebe Kebede',
    ])->assertOk();

    expect(ProfileUpdateRequest::where('employee_id', $employee->id)->count())->toBe(0);
});

test('the employee sees their own pending requests on GET /profile', function () {
    [$tenant, $employee, $user] = profileFixture();

    test()->actingAs($user);

    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/profile", ['name' => 'New Name'])->assertOk();

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/profile");

    $response->assertOk();
    expect($response->json('pending_updates'))->toHaveCount(1);
    expect($response->json('pending_updates.0.field_name'))->toBe('name');
});

test('GET /profile survives strict lazy-loading once requests exist', function () {
    [$tenant, $employee, $user] = profileFixture();

    // The resource reads employee/requester/reviewer. Without eager-loading them the
    // endpoint 500s under preventLazyLoading — which is on everywhere except
    // production, so the employee's own profile page broke the moment they queued a
    // change. The suite missed it because the staged models are freshly created and
    // Laravel exempts those from the violation; only a *subsequent* read trips it.
    test()->actingAs($user);

    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/profile", ['name' => 'Lazy Guard'])
        ->assertOk();

    Model::preventLazyLoading(true);

    try {
        test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/profile")
            ->assertOk()
            ->assertJsonPath('pending_updates.0.field_name', 'name');
    } finally {
        Model::preventLazyLoading(false);
    }
});

test('the review queue survives strict lazy-loading', function () {
    [$tenant, $employee, $user] = profileFixture();
    $hr = createUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->actingAs($user);
    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/profile", ['name' => 'Lazy Guard'])
        ->assertOk();

    app('auth')->forgetGuards();
    test()->actingAs($hr);

    Model::preventLazyLoading(true);

    try {
        test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/profile-update-requests")
            ->assertOk()
            ->assertJsonPath('data.0.field_name', 'name');
    } finally {
        Model::preventLazyLoading(false);
    }
});

test('a broadcast outage does not make a successful staging look failed', function () {
    [$tenant, $employee, $user] = profileFixture();

    // Notifications fire inline from the request and the broadcast channel talks to
    // Reverb over cURL. With Reverb down this threw *after* the row was committed,
    // so the employee saw a 500 for a change that had in fact been queued.
    Notification::shouldReceive('send')
        ->andThrow(new RuntimeException('Pusher error: cURL error 7'));

    test()->actingAs($user);

    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/profile", [
        'bank_account_number' => '1000123456789',
    ])->assertOk();

    expect(ProfileUpdateRequest::where('employee_id', $employee->id)->count())->toBe(1);
});

// ── Review ──

test('approving a request applies the value to the employee', function () {
    [$tenant, $employee, $user] = profileFixture();
    $hr = createUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->actingAs($user);
    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/profile", ['name' => 'Kebede Abebe'])->assertOk();

    $pending = ProfileUpdateRequest::where('employee_id', $employee->id)->firstOrFail();

    app('auth')->forgetGuards();
    test()->actingAs($hr);

    test()->postJson(
        "http://{$tenant->subdomain}.ethr.test/api/v1/profile-update-requests/{$pending->public_id}/review",
        ['action' => 'approve'],
    )->assertOk();

    expect($employee->fresh()->name)->toBe('Kebede Abebe');
    expect($pending->fresh()->status)->toBe(ProfileUpdateStatus::APPROVED);
});

test('approving a bank change writes to employee_bank_details', function () {
    [$tenant, $employee, $user] = profileFixture();
    $hr = createUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->actingAs($user);
    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/profile", [
        'bank_account_number' => '1000999888777',
        'bank_name' => 'Commercial Bank of Ethiopia',
    ])->assertOk();

    app('auth')->forgetGuards();
    test()->actingAs($hr);

    foreach (ProfileUpdateRequest::where('employee_id', $employee->id)->get() as $request) {
        test()->postJson(
            "http://{$tenant->subdomain}.ethr.test/api/v1/profile-update-requests/{$request->public_id}/review",
            ['action' => 'approve'],
        )->assertOk();
    }

    $detail = $employee->fresh()->bankDetails()->first();
    expect($detail)->toBeInstanceOf(EmployeeBankDetail::class);
    expect($detail->account_number)->toBe('1000999888777');
    expect($detail->bank_name)->toBe('Commercial Bank of Ethiopia');
});

test('rejecting a request leaves the employee record untouched', function () {
    [$tenant, $employee, $user] = profileFixture();
    $hr = createUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->actingAs($user);
    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/profile", ['name' => 'Impostor'])->assertOk();

    $pending = ProfileUpdateRequest::where('employee_id', $employee->id)->firstOrFail();

    app('auth')->forgetGuards();
    test()->actingAs($hr);

    test()->postJson(
        "http://{$tenant->subdomain}.ethr.test/api/v1/profile-update-requests/{$pending->public_id}/review",
        ['action' => 'reject', 'notes' => 'Needs supporting document'],
    )->assertOk();

    expect($employee->fresh()->name)->toBe('Abebe Kebede');
    expect($pending->fresh()->status)->toBe(ProfileUpdateStatus::REJECTED);
    expect($pending->fresh()->review_notes)->toBe('Needs supporting document');
});

test('a request cannot be reviewed twice', function () {
    [$tenant, $employee, $user] = profileFixture();
    $hr = createUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->actingAs($user);
    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/profile", ['name' => 'Once Only'])->assertOk();

    $pending = ProfileUpdateRequest::where('employee_id', $employee->id)->firstOrFail();

    app('auth')->forgetGuards();
    test()->actingAs($hr);

    $url = "http://{$tenant->subdomain}.ethr.test/api/v1/profile-update-requests/{$pending->public_id}/review";

    test()->postJson($url, ['action' => 'approve'])->assertOk();
    test()->postJson($url, ['action' => 'reject'])->assertStatus(422);
});

// ── Authorization & isolation ──

test('an employee cannot review profile update requests', function () {
    [$tenant, $employee, $user] = profileFixture();

    test()->actingAs($user);
    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/profile", ['name' => 'Self Approve'])->assertOk();

    $pending = ProfileUpdateRequest::where('employee_id', $employee->id)->firstOrFail();

    test()->postJson(
        "http://{$tenant->subdomain}.ethr.test/api/v1/profile-update-requests/{$pending->public_id}/review",
        ['action' => 'approve'],
    )->assertStatus(403);

    expect($employee->fresh()->name)->toBe('Abebe Kebede');
});

test('an employee cannot list the review queue', function () {
    [$tenant, , $user] = profileFixture();

    test()->actingAs($user);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/profile-update-requests")
        ->assertStatus(403);
});

test('the review queue never shows another tenant requests', function () {
    [$tenantA, $employeeA, $userA] = profileFixture();
    [$tenantB] = profileFixture();

    test()->actingAs($userA);
    test()->putJson("http://{$tenantA->subdomain}.ethr.test/api/v1/profile", ['name' => 'Tenant A Person'])->assertOk();

    $hrB = createUser(['role' => UserRole::HR_ADMIN], $tenantB);

    app('auth')->forgetGuards();
    test()->actingAs($hrB);

    $response = test()->getJson("http://{$tenantB->subdomain}.ethr.test/api/v1/profile-update-requests");

    $response->assertOk();
    expect($response->json('data'))->toBe([]);
});

test('the employee always sees their own account number in full', function () {
    [$tenant, $employee, $user] = profileFixture();

    test()->actingAs($user);

    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/profile", [
        'bank_account_number' => '1000123456789',
    ])->assertOk();

    $own = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/profile");
    expect($own->json('pending_updates.0.new_value'))->toBe('1000123456789');
});

test('a reviewer without the financial permission sees a masked account number', function () {
    [$tenant, $employee, $user] = profileFixture();

    // The stock roles that carry `employee.update` (HR_ADMIN and above) also carry
    // `employee.viewFinancial`, so the masking only bites for a custom role that
    // grants review rights without financial visibility — exactly the separation
    // of duties the mask exists for.
    $role = CustomRole::create(['tenant_id' => $tenant->id, 'name' => 'Records Clerk']);
    $role->permissions()->sync(
        Permission::whereIn('name', ['employee.update', 'employee.viewAny'])->pluck('id')
    );
    $clerk = createUser(
        ['role' => UserRole::EMPLOYEE, 'custom_role_id' => $role->id],
        $tenant,
    );

    test()->actingAs($user);
    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/profile", [
        'bank_account_number' => '1000123456789',
    ])->assertOk();

    app('auth')->forgetGuards();
    test()->actingAs($clerk);

    $queue = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/profile-update-requests");
    $queue->assertOk();

    $masked = $queue->json('data.0.new_value');
    expect($masked)->not->toBe('1000123456789');
    expect($masked)->toEndWith('6789');
    expect($masked)->toStartWith('•');
});

test('an HR reviewer with the financial permission sees the account number in full', function () {
    [$tenant, $employee, $user] = profileFixture();
    $hr = createUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->actingAs($user);
    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/profile", [
        'bank_account_number' => '1000123456789',
    ])->assertOk();

    app('auth')->forgetGuards();
    test()->actingAs($hr);

    $queue = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/profile-update-requests");
    $queue->assertOk();
    expect($queue->json('data.0.new_value'))->toBe('1000123456789');
});

test('the approvals summary masks the account number for the same reviewers', function () {
    [$tenant, $employee, $user] = profileFixture();

    $role = CustomRole::create(['tenant_id' => $tenant->id, 'name' => 'Records Clerk']);
    $role->permissions()->sync(
        Permission::whereIn('name', ['employee.update', 'leave.viewTeam'])->pluck('id')
    );
    $clerk = createUser(
        ['role' => UserRole::EMPLOYEE, 'custom_role_id' => $role->id],
        $tenant,
    );

    test()->actingAs($user);
    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/profile", [
        'bank_account_number' => '1000123456789',
    ])->assertOk();

    app('auth')->forgetGuards();
    test()->actingAs($clerk);

    $list = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/approvals/pending");
    $list->assertOk();

    $summary = collect($list->json('items'))
        ->firstWhere('type', 'profile_update')['summary'];

    expect($summary)->not->toContain('1000123456789');
    expect($summary)->toContain('6789');
});

// ── Notifications ──

test('staging a gated change notifies reviewers, and review notifies the employee', function () {
    Notification::fake();

    [$tenant, $employee, $user] = profileFixture();
    $hr = createUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->actingAs($user);
    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/profile", ['name' => 'Notify Me'])->assertOk();

    Notification::assertSentTo($hr, ProfileUpdateRequestedNotification::class);

    $pending = ProfileUpdateRequest::where('employee_id', $employee->id)->firstOrFail();

    app('auth')->forgetGuards();
    test()->actingAs($hr);
    test()->postJson(
        "http://{$tenant->subdomain}.ethr.test/api/v1/profile-update-requests/{$pending->public_id}/review",
        ['action' => 'approve'],
    )->assertOk();

    Notification::assertSentTo($user, ProfileUpdateApprovedNotification::class);
});

// ── Approvals centre integration ──

test('pending profile updates appear in the approvals centre and can be batch-approved', function () {
    [$tenant, $employee, $user] = profileFixture();
    $hr = createUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->actingAs($user);
    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/profile", ['name' => 'Batch Me'])->assertOk();

    $pending = ProfileUpdateRequest::where('employee_id', $employee->id)->firstOrFail();

    app('auth')->forgetGuards();
    test()->actingAs($hr);

    $list = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/approvals/pending");
    $list->assertOk();
    expect(collect($list->json('items'))->pluck('type'))->toContain('profile_update');

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/approvals/batch", [
        'actions' => [
            ['type' => 'profile_update', 'public_id' => $pending->public_id, 'action' => 'approve'],
        ],
    ])->assertOk();

    expect($employee->fresh()->name)->toBe('Batch Me');
});

test('a supervisor cannot batch-approve a profile update', function () {
    [$tenant, $employee, $user] = profileFixture();
    $supervisor = createUser(['role' => UserRole::SUPERVISOR], $tenant);

    test()->actingAs($user);
    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/profile", ['name' => 'Sneak Through'])->assertOk();

    $pending = ProfileUpdateRequest::where('employee_id', $employee->id)->firstOrFail();

    app('auth')->forgetGuards();
    test()->actingAs($supervisor);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/approvals/batch", [
        'actions' => [
            ['type' => 'profile_update', 'public_id' => $pending->public_id, 'action' => 'approve'],
        ],
    ]);

    $response->assertOk();
    expect($response->json('results.0.status'))->toBe('error');
    expect($employee->fresh()->name)->toBe('Abebe Kebede');
});
