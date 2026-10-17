<?php

declare(strict_types=1);

use App\Enums\AttendanceStatus;
use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\AttendancePhotosDueForDeletionNotification;
use App\Services\CurrentTenant;
use App\Support\PhotoConsent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
 * The attendance-photo policy (docs/operations/ATTENDANCE-PHOTO-RETENTION.md):
 * selfies are kept for the organisation's retention period, then HR and the
 * department manager are told and have 48 hours to hold any a complaint still
 * needs; after that the photo files are deleted and the record is kept.
 */

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();
    $this->travelTo(Carbon::parse('2026-10-20 08:00:00', 'UTC'));

    $this->tenant = createTenant();
    $this->sales = Department::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->ops = Department::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->hr = createUser(['role' => UserRole::HR_ADMIN], $this->tenant);
    $this->salesManager = createUser([
        'role' => UserRole::DEPT_ADMIN,
        'employee_id' => Employee::factory()->create(['tenant_id' => $this->tenant->id, 'department_id' => $this->sales->id])->id,
    ], $this->tenant);
    $this->opsManager = createUser([
        'role' => UserRole::DEPT_ADMIN,
        'employee_id' => Employee::factory()->create(['tenant_id' => $this->tenant->id, 'department_id' => $this->ops->id])->id,
    ], $this->tenant);
    $this->supervisor = createUser(['role' => UserRole::SUPERVISOR], $this->tenant);
    $this->finance = createUser(['role' => UserRole::FINANCE_ADMIN], $this->tenant);

    $this->worker = Employee::factory()->create(['tenant_id' => $this->tenant->id, 'department_id' => $this->sales->id]);
});

/** A record with a check-in and a check-out selfie, its last punch `$daysAgo` days back. */
function recordWithSelfies(Tenant $tenant, Employee $employee, float $daysAgo): AttendanceRecord
{
    $out = now()->subDays($daysAgo);
    $in = $out->copy()->subHours(8);
    $checkIn = "{$tenant->public_id}/selfies/".Str::ulid().'.jpg';
    $checkOut = "{$tenant->public_id}/selfies/".Str::ulid().'.jpg';
    Storage::disk('local')->put($checkIn, 'jpeg-in');
    Storage::disk('local')->put($checkOut, 'jpeg-out');

    return AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'date' => $in->toDateString(),
        'check_in' => $in,
        'check_out' => $out,
        'status' => AttendanceStatus::PRESENT,
        'photo_path' => $checkIn,
        'metadata' => ['checkout_photo_path' => $checkOut, 'checkout_idempotency_key' => 'k-1'],
    ]);
}

function runRetention(Tenant $tenant): void
{
    test()->artisan('attendance:photo-retention')->assertSuccessful();
    // The command forgets the tenant when it is done; the assertions read as it.
    app(CurrentTenant::class)->set($tenant);
}

function photoApi(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1{$path}";
}

it('opens the review for selfies past the retention period and tells HR and that department\'s manager only', function () {
    $old = recordWithSelfies($this->tenant, $this->worker, 8);
    $fresh = recordWithSelfies($this->tenant, $this->worker, 2);

    runRetention($this->tenant);

    expect($old->fresh()->photo_review_started_at)->not->toBeNull()
        ->and($fresh->fresh()->photo_review_started_at)->toBeNull();
    // Announced, not deleted: nothing goes in the run that gave notice.
    Storage::disk('local')->assertExists($old->photo_path);

    Notification::assertSentTo($this->hr, AttendancePhotosDueForDeletionNotification::class,
        fn ($n) => $n->toArray($this->hr)['count'] === 1);
    Notification::assertSentTo($this->salesManager, AttendancePhotosDueForDeletionNotification::class);
    Notification::assertNotSentTo($this->opsManager, AttendancePhotosDueForDeletionNotification::class);
    Notification::assertNotSentTo($this->supervisor, AttendancePhotosDueForDeletionNotification::class);
    Notification::assertNotSentTo($this->finance, AttendancePhotosDueForDeletionNotification::class);
});

it('announces a record once, however often it runs', function () {
    recordWithSelfies($this->tenant, $this->worker, 8);

    runRetention($this->tenant);
    runRetention($this->tenant);

    Notification::assertSentToTimes($this->hr, AttendancePhotosDueForDeletionNotification::class, 1);
});

it('deletes the photo files after the 48-hour review and keeps the attendance record', function () {
    $record = recordWithSelfies($this->tenant, $this->worker, 8);
    $paths = $record->photoPaths();
    runRetention($this->tenant);

    $this->travel(47)->hours();
    runRetention($this->tenant);
    Storage::disk('local')->assertExists($paths['check_in']);

    $this->travel(2)->hours();
    runRetention($this->tenant);

    $after = $record->fresh();
    Storage::disk('local')->assertMissing($paths['check_in']);
    Storage::disk('local')->assertMissing($paths['check_out']);
    expect($after->photo_path)->toBeNull()
        ->and($after->metadata)->toBe(['checkout_idempotency_key' => 'k-1'])
        ->and($after->photos_deleted_at)->not->toBeNull()
        ->and($after->check_in->equalTo($record->check_in))->toBeTrue()
        ->and($after->check_out->equalTo($record->check_out))->toBeTrue()
        ->and($after->status)->toBe(AttendanceStatus::PRESENT);

    $audit = AuditLog::query()->where('action', 'attendance.photos_deleted')->sole();
    expect($audit->auditable_id)->toBe($record->id);
});

it('keeps a held photo past the review, and only HR releases the hold', function () {
    $record = recordWithSelfies($this->tenant, $this->worker, 8);
    runRetention($this->tenant);

    $this->actingAs($this->salesManager)
        ->postJson(photoApi($this->tenant, "/attendance/photos/{$record->public_id}/hold"), ['reason' => 'Complaint about a late mark on this day'])
        ->assertOk()
        ->assertJsonPath('held', true)
        ->assertJsonPath('delete_after', null);

    $this->travel(3)->days();
    runRetention($this->tenant);
    Storage::disk('local')->assertExists($record->photo_path);

    $this->app['auth']->forgetGuards();
    $this->actingAs($this->salesManager)
        ->deleteJson(photoApi($this->tenant, "/attendance/photos/{$record->public_id}/hold"))
        ->assertForbidden();

    $this->app['auth']->forgetGuards();
    $this->actingAs($this->hr)
        ->deleteJson(photoApi($this->tenant, "/attendance/photos/{$record->public_id}/hold"))
        ->assertOk()
        ->assertJsonPath('held', false);

    // Released: announced again before anything is deleted.
    Notification::fake();
    runRetention($this->tenant);
    Storage::disk('local')->assertExists($record->photo_path);
    Notification::assertSentTo($this->hr, AttendancePhotosDueForDeletionNotification::class);
});

it('requires a reason to hold a photo', function () {
    $record = recordWithSelfies($this->tenant, $this->worker, 1);

    $this->actingAs($this->hr)
        ->postJson(photoApi($this->tenant, "/attendance/photos/{$record->public_id}/hold"), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reason']);
});

it('follows the organisation\'s retention period', function () {
    AttendanceSetting::query()->create([...AttendanceSetting::defaults(), 'tenant_id' => $this->tenant->id, 'photo_retention_days' => 30]);
    $record = recordWithSelfies($this->tenant, $this->worker, 8);

    runRetention($this->tenant);

    expect($record->fresh()->photo_review_started_at)->toBeNull();
    Notification::assertNothingSent();
});

it('shows a selfie to HR and to the department\'s manager, and audits each view', function () {
    $record = recordWithSelfies($this->tenant, $this->worker, 1);

    $this->actingAs($this->hr)
        ->get(photoApi($this->tenant, "/attendance/photos/{$record->public_id}/check-out"))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');

    $this->app['auth']->forgetGuards();
    $this->actingAs($this->salesManager)
        ->get(photoApi($this->tenant, "/attendance/photos/{$record->public_id}/check-in"))
        ->assertOk();

    expect(AuditLog::query()->where('action', 'attendance.photo_viewed')->count())->toBe(2);
});

it('does not show a selfie outside the viewer\'s reach', function (string $who, int $status) {
    $record = recordWithSelfies($this->tenant, $this->worker, 1);
    $viewer = match ($who) {
        'ops manager' => $this->opsManager,
        'supervisor' => $this->supervisor,
        'finance' => $this->finance,
        'the employee' => createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $this->worker->id], $this->tenant),
    };

    $this->actingAs($viewer)
        ->get(photoApi($this->tenant, "/attendance/photos/{$record->public_id}/check-in"))
        ->assertStatus($status);

    expect(AuditLog::query()->where('action', 'attendance.photo_viewed')->count())->toBe(0);
})->with([
    ['ops manager', 404],
    ['supervisor', 403],
    ['finance', 403],
    ['the employee', 403],
]);

it('lists the photos within the viewer\'s reach, with their retention dates', function () {
    $sales = recordWithSelfies($this->tenant, $this->worker, 1);
    $opsWorker = Employee::factory()->create(['tenant_id' => $this->tenant->id, 'department_id' => $this->ops->id]);
    recordWithSelfies($this->tenant, $opsWorker, 1);

    $this->actingAs($this->hr)
        ->getJson(photoApi($this->tenant, '/attendance/photos'))
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->app['auth']->forgetGuards();
    $this->actingAs($this->salesManager)
        ->getJson(photoApi($this->tenant, '/attendance/photos'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.public_id', $sales->public_id)
        ->assertJsonPath('data.0.has_check_in_photo', true)
        ->assertJsonPath('data.0.has_check_out_photo', true)
        ->assertJsonPath('data.0.keep_until', $sales->check_out->copy()->addDays(7)->toIso8601String());
});

it('narrows the list to photos under review or on hold', function () {
    recordWithSelfies($this->tenant, $this->worker, 8);
    recordWithSelfies($this->tenant, $this->worker, 1);
    runRetention($this->tenant);

    $this->actingAs($this->hr)
        ->getJson(photoApi($this->tenant, '/attendance/photos?status=review'))
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->app['auth']->forgetGuards();
    $this->actingAs($this->hr)
        ->getJson(photoApi($this->tenant, '/attendance/photos?status=held'))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('accepts a retention period from 1 to 90 days', function (int $days, int $status) {
    $this->actingAs($this->hr)
        ->putJson(photoApi($this->tenant, '/attendance/settings'), ['photo_retention_days' => $days])
        ->assertStatus($status);
})->with([
    [1, 200],
    [90, 200],
    [0, 422],
    [91, 422],
]);

it('refuses a phone selfie over 1.5 MB', function () {
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $this->worker->id], $this->tenant);
    PhotoConsent::give($user);

    $big = 'data:image/jpeg;base64,'.base64_encode("\xFF\xD8\xFF".str_repeat("\0", 1_600_000));

    $this->actingAs($user)
        ->postJson(photoApi($this->tenant, '/attendance/mobile/check-in'), [
            'idempotency_key' => (string) Str::uuid(),
            'latitude' => 9.0192,
            'longitude' => 38.7525,
            'photo' => $big,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['photo']);
});

it('grants the photo ability to HR, tenant admins and department managers only', function () {
    $holders = collect(UserRole::cases())
        ->reject(fn (UserRole $r) => $r === UserRole::SUPER_ADMIN)
        ->filter(fn (UserRole $r) => (new User(['role' => $r]))->hasPermission('attendance.viewPhotos'))
        ->map(fn (UserRole $r) => $r->value)
        ->values()
        ->all();

    expect($holders)->toEqualCanonicalizing(['tenant_admin', 'hr_admin', 'dept_admin']);
});
