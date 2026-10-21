<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmploymentLetterRequest;
use App\Models\Position;
use App\Models\Tenant;
use App\Notifications\LetterDecidedNotification;
use App\Notifications\LetterRequestedNotification;
use App\Services\Letters\EmploymentLetters;
use Illuminate\Support\Facades\Notification;

// An employee asks HR for an employment letter, HR issues or declines it, and
// an issued letter downloads as a PDF in the language asked for. Until
// 2026-10-20 there was no way to ask (blueprint assessment ESS7).

function letterUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/{$path}";
}

function letterSetup(): array
{
    $tenant = createTenant(['name' => 'Syntax Technology']);
    $department = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Engineering']);
    $position = Position::factory()->create(['tenant_id' => $tenant->id, 'title' => 'Software Engineer']);
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id, 'name' => 'Selam Tesfaye', 'employee_code' => 'E-007',
        'hire_date' => '2022-03-01', 'department_id' => $department->id, 'position_id' => $position->id,
        'salary_cents' => 4_500_000,
    ]);
    $me = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    $hr = createUser(['role' => UserRole::HR_ADMIN], $tenant);

    return [$tenant, $employee, $me, $hr];
}

it('lets an employee ask for a letter, and tells HR', function () {
    Notification::fake();
    [$tenant, , $me, $hr] = letterSetup();
    test()->actingAs($me);

    test()->postJson(letterUrl($tenant, 'profile/letters'), [
        'purpose' => 'Bank loan application', 'include_salary' => true, 'language' => 'en',
    ])->assertCreated()->assertJsonPath('status', 'pending');

    Notification::assertSentTo($hr, LetterRequestedNotification::class);
    test()->getJson(letterUrl($tenant, 'profile/letters'))->assertOk()->assertJsonPath('data.0.purpose', 'Bank loan application');
});

it('lets HR issue it with a reference, and the employee download it', function () {
    Notification::fake();
    [$tenant, , $me, $hr] = letterSetup();
    test()->actingAs($me);
    $id = test()->postJson(letterUrl($tenant, 'profile/letters'), ['purpose' => 'Visa application'])->json('public_id');

    test()->actingAs($hr);
    test()->getJson(letterUrl($tenant, 'employees/letters'))->assertOk()->assertJsonPath('data.0.employee.name', 'Selam Tesfaye');
    test()->postJson(letterUrl($tenant, "employees/letters/{$id}/issue"))
        ->assertOk()
        ->assertJsonPath('status', 'issued')
        ->assertJsonPath('reference', sprintf('EL-%d-0001', now()->year));
    Notification::assertSentTo($me, LetterDecidedNotification::class);

    test()->actingAs($me);
    test()->get(letterUrl($tenant, "profile/letters/{$id}/pdf"))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
    expect(AuditLog::where('action', 'letter.downloaded')->exists())->toBeTrue();
});

it('writes what the record says, with salary only when asked', function () {
    [$tenant, $employee, , $hr] = letterSetup();
    $letters = app(EmploymentLetters::class);
    $with = $letters->issue($letters->request($employee, ['purpose' => 'Bank', 'include_salary' => true]), $hr);
    $without = $letters->issue($letters->request($employee, ['purpose' => 'Visa']), $hr);

    $html = fn (EmploymentLetterRequest $l) => view('letters.employment', [
        'locale' => 'en', 'organisation' => 'Syntax Technology', 'reference' => $l->reference, 'issued_on' => '20 October 2026',
        'addressed_to' => null, 'name' => 'Selam Tesfaye', 'code' => 'E-007', 'hired_on' => '1 March 2022', 'left_on' => null,
        'position' => 'Software Engineer', 'department' => 'Engineering',
        'salary' => $l->include_salary ? '45,000.00' : null, 'purpose' => $l->purpose, 'issuer' => '',
    ])->render();

    expect($html($with))->toContain('employed by Syntax Technology since 1 March 2022')
        ->toContain('Software Engineer in the Engineering department')
        ->toContain('45,000.00 birr')
        ->and($html($without))->not->toContain('birr');
    expect(strlen($letters->pdf($with)))->toBeGreaterThan(1000);
});

it('lets HR decline with a reason', function () {
    Notification::fake();
    [$tenant, , $me, $hr] = letterSetup();
    test()->actingAs($me);
    $id = test()->postJson(letterUrl($tenant, 'profile/letters'), ['purpose' => 'Embassy'])->json('public_id');

    test()->actingAs($hr);
    test()->postJson(letterUrl($tenant, "employees/letters/{$id}/reject"), [])->assertUnprocessable();
    test()->postJson(letterUrl($tenant, "employees/letters/{$id}/reject"), ['reason' => 'Probation not complete'])
        ->assertOk()->assertJsonPath('status', 'rejected');

    test()->actingAs($me);
    test()->get(letterUrl($tenant, "profile/letters/{$id}/pdf"))->assertNotFound();
});

it('never lets anyone decide a letter about themselves', function () {
    [$tenant, $employee, , $hr] = letterSetup();
    $hr->update(['employee_id' => $employee->id]);
    $letter = app(EmploymentLetters::class)->request($employee, ['purpose' => 'Mine']);

    test()->actingAs($hr->fresh());
    test()->postJson(letterUrl($tenant, "employees/letters/{$letter->public_id}/issue"))->assertForbidden();
});

it('never downloads someone else\'s letter', function () {
    [$tenant, $employee, , $hr] = letterSetup();
    $letters = app(EmploymentLetters::class);
    $issued = $letters->issue($letters->request($employee, ['purpose' => 'Bank']), $hr);
    $colleague = Employee::factory()->create(['tenant_id' => $tenant->id]);
    test()->actingAs(createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $colleague->id], $tenant));

    test()->get(letterUrl($tenant, "profile/letters/{$issued->public_id}/pdf"))->assertNotFound();
});

it('keeps letter requests to employees HR reaches', function () {
    [$tenant, $employee] = letterSetup();
    app(EmploymentLetters::class)->request($employee, ['purpose' => 'Bank']);
    test()->actingAs(createUser(['role' => UserRole::SUPERVISOR], $tenant));

    test()->getJson(letterUrl($tenant, 'employees/letters'))->assertForbidden();
});

it('writes the letter in Amharic when asked', function () {
    [, $employee, , $hr] = letterSetup();
    $letters = app(EmploymentLetters::class);
    $letter = $letters->issue($letters->request($employee, ['purpose' => 'ለባንክ ብድር', 'language' => 'am']), $hr);

    $html = view('letters.employment', [
        'locale' => 'am', 'organisation' => 'Syntax Technology', 'reference' => $letter->reference, 'issued_on' => '',
        'addressed_to' => null, 'name' => 'Selam Tesfaye', 'code' => 'E-007', 'hired_on' => '', 'left_on' => null,
        'position' => '', 'department' => '', 'salary' => null, 'purpose' => $letter->purpose, 'issuer' => '',
    ])->render();

    expect($html)->toContain('የሥራ ማረጋገጫ')->toContain('ለሚመለከተው ሁሉ')
        ->and(strlen($letters->pdf($letter)))->toBeGreaterThan(1000);
});
