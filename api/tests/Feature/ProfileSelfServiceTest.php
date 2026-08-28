<?php

declare(strict_types=1);

use App\Enums\ProfileUpdateStatus;
use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\EmployeeBankDetail;
use App\Models\EmployeeEmergencyContact;
use App\Models\ProfileUpdateRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Cover for the self-service surface behind /profile.
 *
 * The page could previously edit three fields and nothing else: the photo field
 * on `PUT /profile` was unreachable (PHP parses multipart on POST only), the
 * language switcher wrote to localStorage and never to the user record, and the
 * emergency-contact form had no way to read back what was already on file.
 */
function selfServiceFixture(): array
{
    $tenant = createTenant();
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Abebe Kebede',
    ]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    $employee->update(['user_id' => $user->id]);

    test()->actingAs($user);

    return [$tenant, $employee, $user];
}

function profileUrl(object $tenant, string $path = ''): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/profile{$path}";
}

// ── Reading the profile ──

test('GET /profile returns the contacts and bank details the edit form prefills from', function () {
    [$tenant, $employee] = selfServiceFixture();

    EmployeeEmergencyContact::create([
        'tenant_id' => $employee->tenant_id,
        'employee_id' => $employee->id,
        'name' => 'Almaz Tesfaye',
        'relationship' => 'spouse',
        'phone' => '+251911223344',
        'priority' => 1,
    ]);

    EmployeeBankDetail::create([
        'tenant_id' => $employee->tenant_id,
        'employee_id' => $employee->id,
        'bank_name' => 'Commercial Bank of Ethiopia',
        'account_number' => '1000123456789',
        'is_primary' => true,
    ]);

    $response = test()->getJson(profileUrl($tenant))->assertOk();

    expect($response->json('emergency_contacts'))->toHaveCount(1);
    expect($response->json('emergency_contacts.0.name'))->toBe('Almaz Tesfaye');
    expect($response->json('bank_details.0.bank_name'))->toBe('Commercial Bank of Ethiopia');
});

test('GET /profile never returns a full account number or TIN', function () {
    [$tenant, $employee] = selfServiceFixture();

    $employee->update(['tin' => '0012345678']);
    EmployeeBankDetail::create([
        'tenant_id' => $employee->tenant_id,
        'employee_id' => $employee->id,
        'bank_name' => 'Awash Bank',
        'account_number' => '1000123456789',
        'is_primary' => true,
    ]);

    $response = test()->getJson(profileUrl($tenant))->assertOk();

    expect($response->json('bank_details.0.account_number_masked'))->toEndWith('6789');
    expect($response->json('bank_details.0.account_number_masked'))->not->toContain('1000123');
    expect($response->json('employee.tin_masked'))->toEndWith('5678');
    expect($response->json('employee.tin_masked'))->not->toContain('001234');
});

test('GET /profile tells the client which fields are gated', function () {
    [$tenant] = selfServiceFixture();

    $response = test()->getJson(profileUrl($tenant))->assertOk();

    expect($response->json('editable_fields.gated'))->toContain('bank_account_number');
    expect($response->json('editable_fields.self'))->toContain('phone');
});

// ── Immediate fields ──

test('marital status and nationality apply immediately', function () {
    [$tenant, $employee] = selfServiceFixture();

    test()->putJson(profileUrl($tenant), [
        'marital_status' => 'married',
        'nationality' => 'Ethiopian',
    ])->assertOk();

    expect($employee->fresh()->marital_status)->toBe('married');
    expect($employee->fresh()->nationality)->toBe('Ethiopian');
});

// ── Date of birth is gated ──

test('a birth date change is queued for HR rather than applied', function () {
    [$tenant, $employee] = selfServiceFixture();

    test()->putJson(profileUrl($tenant), ['date_of_birth' => '1995-04-12'])
        ->assertOk()
        ->assertJsonPath('pending_approval.fields.0', 'date_of_birth');

    expect($employee->fresh()->date_of_birth?->format('Y-m-d'))->not->toBe('1995-04-12');
});

test('resubmitting the birth date already on record queues nothing', function () {
    [$tenant, $employee] = selfServiceFixture();

    $employee->update(['date_of_birth' => '1995-04-12']);

    // Carbon stringifies to "1995-04-12 00:00:00", so without normalisation the
    // no-op guard misses and every save re-queues the same value for review.
    test()->putJson(profileUrl($tenant), ['date_of_birth' => '1995-04-12'])
        ->assertOk()
        ->assertJsonPath('pending_approval', null);

    expect(ProfileUpdateRequest::where('employee_id', $employee->id)->count())->toBe(0);
});

// ── Photo ──

test('an employee can upload and remove their own photo', function () {
    Storage::fake('minio');
    [$tenant, $employee] = selfServiceFixture();

    test()->post(
        profileUrl($tenant, '/photo'),
        ['photo' => UploadedFile::fake()->image('me.jpg', 400, 400)],
        ['Accept' => 'application/json']
    )->assertCreated();

    expect($employee->fresh()->photo_path)->not->toBeNull();

    test()->deleteJson(profileUrl($tenant, '/photo'))->assertNoContent();

    expect($employee->fresh()->photo_path)->toBeNull();
});

test('a non-image upload is rejected', function () {
    Storage::fake('minio');
    [$tenant] = selfServiceFixture();

    test()->post(
        profileUrl($tenant, '/photo'),
        ['photo' => UploadedFile::fake()->create('payroll.pdf', 100, 'application/pdf')],
        ['Accept' => 'application/json']
    )->assertStatus(422);
});

// ── Preferences ──

test('a language choice is persisted to the user record', function () {
    [$tenant, , $user] = selfServiceFixture();

    test()->putJson(profileUrl($tenant, '/preferences'), ['locale' => 'am'])
        ->assertOk()
        ->assertJsonPath('locale', 'am');

    expect($user->fresh()->locale)->toBe('am');
});

test('setting one preference does not clear the others', function () {
    [$tenant, , $user] = selfServiceFixture();

    test()->putJson(profileUrl($tenant, '/preferences'), ['calendar' => 'dual'])->assertOk();
    test()->putJson(profileUrl($tenant, '/preferences'), ['theme' => 'dark'])
        ->assertOk()
        ->assertJsonPath('calendar', 'dual')
        ->assertJsonPath('theme', 'dark');

    expect($user->fresh()->preferences)->toMatchArray(['calendar' => 'dual', 'theme' => 'dark']);
});

test('an unsupported locale is rejected', function () {
    [$tenant] = selfServiceFixture();

    test()->putJson(profileUrl($tenant, '/preferences'), ['locale' => 'fr'])
        ->assertStatus(422);
});

// ── Emergency contacts ──

test('an employee manages their own emergency contacts', function () {
    [$tenant, $employee] = selfServiceFixture();

    $created = test()->postJson(profileUrl($tenant, '/emergency-contacts'), [
        'name' => 'Almaz Tesfaye',
        'relationship' => 'spouse',
        'phone' => '+251911223344',
    ])->assertCreated();

    $publicId = $created->json('public_id');

    test()->putJson(profileUrl($tenant, "/emergency-contacts/{$publicId}"), [
        'name' => 'Almaz T.',
        'relationship' => 'spouse',
        'phone' => '+251911223355',
    ])->assertOk()->assertJsonPath('phone', '+251911223355');

    test()->getJson(profileUrl($tenant, '/emergency-contacts'))
        ->assertOk()
        ->assertJsonCount(1, 'data');

    test()->deleteJson(profileUrl($tenant, "/emergency-contacts/{$publicId}"))
        ->assertNoContent();

    expect($employee->emergencyContacts()->count())->toBe(0);
});

test('an employee\'s emergency contact phone is canonicalized from local 09... form', function () {
    [$tenant] = selfServiceFixture();

    $response = test()->postJson(profileUrl($tenant, '/emergency-contacts'), [
        'name' => 'Local Format',
        'relationship' => 'parent',
        'phone' => '0911223344',
    ]);

    $response->assertCreated()->assertJsonPath('phone', '+251911223344');
});

test("an employee cannot touch another employee's emergency contact", function () {
    [$tenant] = selfServiceFixture();

    $other = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $contact = EmployeeEmergencyContact::create([
        'tenant_id' => $other->tenant_id,
        'employee_id' => $other->id,
        'name' => 'Someone Else',
        'relationship' => 'parent',
        'phone' => '+251911000000',
        'priority' => 1,
    ]);

    test()->deleteJson(profileUrl($tenant, "/emergency-contacts/{$contact->public_id}"))
        ->assertNotFound();

    expect(EmployeeEmergencyContact::find($contact->id))->not->toBeNull();
});

// ── Withdrawing a staged change ──

test('an employee can withdraw a request nobody has reviewed', function () {
    [$tenant, $employee] = selfServiceFixture();

    test()->putJson(profileUrl($tenant), ['name' => 'Abebe K. Kebede'])->assertOk();

    $pending = ProfileUpdateRequest::where('employee_id', $employee->id)->firstOrFail();

    test()->deleteJson("http://{$tenant->subdomain}.ethr.test/api/v1/profile-update-requests/{$pending->public_id}")
        ->assertOk();

    expect($pending->fresh()->status)->toBe(ProfileUpdateStatus::WITHDRAWN);
    // Withdrawn is not rejected: HR turned nothing down here.
    expect($employee->fresh()->name)->toBe('Abebe Kebede');
});

test("an employee cannot withdraw someone else's request", function () {
    [$tenant] = selfServiceFixture();

    $other = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $request = ProfileUpdateRequest::create([
        'tenant_id' => $other->tenant_id,
        'employee_id' => $other->id,
        'field_name' => 'name',
        'old_value' => 'Old',
        'new_value' => 'New',
        'status' => ProfileUpdateStatus::PENDING->value,
    ]);

    test()->deleteJson("http://{$tenant->subdomain}.ethr.test/api/v1/profile-update-requests/{$request->public_id}")
        ->assertNotFound();

    expect($request->fresh()->status)->toBe(ProfileUpdateStatus::PENDING);
});
