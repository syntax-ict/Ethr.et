<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Concern;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PulseResponse;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ConcernRaisedNotification;
use App\Notifications\ConcernUpdatedNotification;
use App\Notifications\RecognitionReceivedNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

/**
 * Employee voice. The tests are mostly about what must NOT be possible:
 * tracing a pulse answer or an anonymous concern back to a person, reading
 * results for a group small enough to point at someone, or reading someone
 * else's concern.
 */
function voiceUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/voice{$path}";
}

function voiceEmployee(Tenant $tenant, array $employee = [], UserRole $role = UserRole::EMPLOYEE): User
{
    $record = Employee::factory()->create(['tenant_id' => $tenant->id, ...$employee]);

    return createUser(['role' => $role, 'employee_id' => $record->id], $tenant);
}

// ── Pulse ──

it('stores a pulse answer that names no one, once a week', function () {
    $tenant = createTenant();
    $user = voiceEmployee($tenant);

    test()->actingAs($user)->postJson(voiceUrl($tenant, '/pulse'), ['score' => 4, 'tags' => ['workload'], 'comment' => 'Busy but fine'])
        ->assertCreated();
    test()->actingAs($user)->getJson(voiceUrl($tenant, '/pulse'))->assertJsonPath('answered', true);
    test()->actingAs($user)->postJson(voiceUrl($tenant, '/pulse'), ['score' => 1])
        ->assertUnprocessable();

    // Nothing on the answer, or anywhere in the table, can name its author.
    expect(Schema::getColumnListing('pulse_responses'))
        ->not->toContain('user_id')->not->toContain('employee_id')->not->toContain('created_at')
        ->and(Schema::hasTable('pulse_participations'))->toBeFalse();
    expect(PulseResponse::withoutGlobalScopes()->sole()->score)->toBe(4);
});

it('shows HR nothing for a group too small to stay anonymous', function () {
    $tenant = createTenant();
    $hr = voiceEmployee($tenant, role: UserRole::HR_ADMIN);
    foreach (range(1, 4) as $i) {
        test()->actingAs(voiceEmployee($tenant))->postJson(voiceUrl($tenant, '/pulse'), ['score' => 2, 'comment' => "comment {$i}"]);
    }

    $insights = test()->actingAs($hr)->getJson(voiceUrl($tenant, '/pulse/insights'))->assertOk();
    expect($insights->json('recent.responses'))->toBe(4)
        ->and($insights->json('recent.average'))->toBeNull()
        ->and($insights->json('recent.comments'))->toBe([])
        ->and(end($insights->json()['trend'])['average'])->toBeNull();

    test()->actingAs(voiceEmployee($tenant))->postJson(voiceUrl($tenant, '/pulse'), ['score' => 5]);

    $insights = test()->actingAs($hr)->getJson(voiceUrl($tenant, '/pulse/insights'))->assertOk();
    expect($insights->json('recent.average'))->toBe(2.6)
        ->and($insights->json('recent.comments'))->toHaveCount(4);
});

it('breaks results down only for departments of at least five', function () {
    $tenant = createTenant();
    $big = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Operations']);
    $small = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Legal']);
    foreach (range(1, 5) as $i) {
        test()->actingAs(voiceEmployee($tenant, ['department_id' => $big->id]))->postJson(voiceUrl($tenant, '/pulse'), ['score' => 4]);
    }
    test()->actingAs(voiceEmployee($tenant, ['department_id' => $small->id]))->postJson(voiceUrl($tenant, '/pulse'), ['score' => 1]);

    $departments = test()->actingAs(voiceEmployee($tenant, role: UserRole::HR_ADMIN))
        ->getJson(voiceUrl($tenant, '/pulse/insights'))->json('recent.departments');

    expect(collect($departments)->pluck('department')->all())->toBe(['Operations']);
});

it('keeps pulse results from employees', function () {
    $tenant = createTenant();

    test()->actingAs(voiceEmployee($tenant))->getJson(voiceUrl($tenant, '/pulse/insights'))->assertForbidden();
});

// ── Speak up ──

it('stores no trace of who raised an anonymous concern, and lets them follow it by code', function () {
    Notification::fake();
    $tenant = createTenant();
    $reporter = voiceEmployee($tenant);
    $hr = voiceEmployee($tenant, role: UserRole::HR_ADMIN);

    $raised = test()->actingAs($reporter)->postJson(voiceUrl($tenant, '/concerns'), [
        'category' => 'harassment', 'subject' => 'Comments in the warehouse',
        'description' => 'A supervisor keeps making remarks.', 'anonymous' => true,
    ])->assertCreated();

    $code = $raised->json('tracking_code');
    expect($code)->toMatch('/^[A-Z0-9]{4}(-[A-Z0-9]{4}){3}$/');

    $concern = Concern::withoutGlobalScopes()->sole();
    expect($concern->reporter_user_id)->toBeNull()
        ->and($concern->tracking_hash)->not->toBe($code);
    $this->assertDatabaseMissing('audit_log', ['action' => 'voice.concern_raised']);
    Notification::assertSentTo($hr, ConcernRaisedNotification::class);

    // HR answers; the reporter reads it with the code and replies.
    test()->actingAs($hr)->getJson(voiceUrl($tenant, '/concerns'))
        ->assertJsonPath('data.0.reporter_name', null);
    test()->actingAs($hr)->postJson(voiceUrl($tenant, "/concerns/{$concern->public_id}/messages"), ['body' => 'Thank you. Which shift?'])
        ->assertCreated();

    $tracked = test()->actingAs($reporter)->postJson(voiceUrl($tenant, '/concerns/track'), ['code' => strtolower($code)])
        ->assertOk()
        ->assertJsonPath('status', 'in_review');
    expect($tracked->json('messages.0.author'))->toBe('hr')
        ->and($tracked->json('messages.0.author_name'))->toBeNull();

    test()->actingAs($reporter)->postJson(voiceUrl($tenant, '/concerns/track/reply'), ['code' => $code, 'body' => 'The night shift.'])
        ->assertCreated()
        ->assertJsonPath('messages.1.author', 'reporter');

    test()->actingAs($reporter)->postJson(voiceUrl($tenant, '/concerns/track'), ['code' => 'AAAA-BBBB-CCCC-DDDD'])
        ->assertNotFound();
});

it('shows a named concern to its reporter and HR only, and tells the reporter of changes', function () {
    Notification::fake();
    $tenant = createTenant();
    $reporter = voiceEmployee($tenant);
    $colleague = voiceEmployee($tenant);
    $hr = voiceEmployee($tenant, role: UserRole::HR_ADMIN);

    $id = test()->actingAs($reporter)->postJson(voiceUrl($tenant, '/concerns'), [
        'category' => 'safety', 'subject' => 'Blocked fire exit', 'description' => 'Pallets in front of exit B.', 'anonymous' => false,
    ])->assertCreated()->assertJsonPath('tracking_code', null)->json('public_id');

    test()->actingAs($reporter)->getJson(voiceUrl($tenant, '/concerns/mine'))->assertJsonPath('data.0.public_id', $id);
    test()->actingAs($colleague)->getJson(voiceUrl($tenant, "/concerns/{$id}"))->assertNotFound();
    test()->actingAs($colleague)->getJson(voiceUrl($tenant, '/concerns'))->assertForbidden();

    test()->actingAs($hr)->getJson(voiceUrl($tenant, '/concerns'))->assertJsonPath('data.0.reporter_name', $reporter->name);
    test()->actingAs($hr)->putJson(voiceUrl($tenant, "/concerns/{$id}"), ['status' => 'resolved'])
        ->assertUnprocessable()->assertJsonValidationErrors('resolution');
    test()->actingAs($hr)->putJson(voiceUrl($tenant, "/concerns/{$id}"), ['status' => 'resolved', 'resolution' => 'Exit cleared; daily check added.'])
        ->assertOk()->assertJsonPath('status', 'resolved');

    Notification::assertSentTo($reporter, ConcernUpdatedNotification::class);
    $this->assertDatabaseHas('audit_log', ['action' => 'voice.concern_updated']);
});

// ── Recognition ──

it('lets colleagues recognise each other, but not themselves', function () {
    Notification::fake();
    $tenant = createTenant();
    $from = voiceEmployee($tenant);
    $to = voiceEmployee($tenant);

    test()->actingAs($from)->postJson(voiceUrl($tenant, '/recognitions'), [
        'to_employee_public_id' => $from->employee->public_id, 'value' => 'teamwork', 'message' => 'Me!',
    ])->assertUnprocessable();

    test()->actingAs($from)->postJson(voiceUrl($tenant, '/recognitions'), [
        'to_employee_public_id' => $to->employee->public_id, 'value' => 'helping_hand', 'message' => 'Covered my shift.',
    ])->assertCreated()->assertJsonPath('to.public_id', $to->employee->public_id);

    Notification::assertSentTo($to, RecognitionReceivedNotification::class);
    test()->actingAs($to)->getJson(voiceUrl($tenant, '/recognitions'))->assertJsonPath('data.0.message', 'Covered my shift.');
});
