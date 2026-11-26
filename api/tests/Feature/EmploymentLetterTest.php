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
use App\Support\Letterhead;
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
        'locale' => 'en', 'letterhead' => Letterhead::for($tenant, 'en'), 'issuer_position' => '', 'organisation' => 'Syntax Technology', 'reference' => $l->reference, 'issued_on' => '20 October 2026',
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
    [$tenant, $employee, $me, $hr] = letterSetup();
    // HR is Selam: her one login is the HR account (one live login per employee).
    $me->delete();
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
        'locale' => 'am', 'letterhead' => ['name' => 'Syntax Technology', 'logo' => null, 'lines' => []], 'issuer_position' => '', 'organisation' => 'Syntax Technology', 'reference' => $letter->reference, 'issued_on' => '',
        'addressed_to' => null, 'name' => 'Selam Tesfaye', 'code' => 'E-007', 'hired_on' => '', 'left_on' => null,
        'position' => '', 'department' => '', 'salary' => null, 'purpose' => $letter->purpose, 'issuer' => '',
    ])->render();

    expect($html)->toContain('የሥራ ማረጋገጫ')->toContain('ለሚመለከተው ሁሉ')
        ->and(strlen($letters->pdf($letter)))->toBeGreaterThan(1000);
});

it('does not double the Amharic "ለ" when the purpose already starts with one', function () {
    [, $employee, , $hr] = letterSetup();
    $letters = app(EmploymentLetters::class);
    $letter = $letters->issue($letters->request($employee, ['purpose' => 'ለባንክ ብድር', 'language' => 'am']), $hr);

    $html = view('letters.employment', [
        'locale' => 'am', 'letterhead' => ['name' => 'Syntax Technology', 'logo' => null, 'lines' => []], 'issuer_position' => '', 'organisation' => 'Syntax Technology', 'reference' => $letter->reference, 'issued_on' => '',
        'addressed_to' => null, 'name' => 'Selam Tesfaye', 'code' => 'E-007', 'hired_on' => '', 'left_on' => null,
        'position' => '', 'department' => '', 'salary' => null, 'purpose' => $letter->purpose, 'issuer' => '',
    ])->render();

    expect(__('letters.purpose', ['purpose' => 'ለባንክ ብድር'], 'am'))->not->toContain('ለለ')
        ->and($html)->toContain('ለባንክ ብድር')->not->toContain('ለለ');
});

// An issued letter is a document a bank or an embassy relies on: it prints
// what it said on the day it was issued, whatever the record says afterwards.

function changeSelam(Employee $employee): void
{
    $employee->update([
        'name' => 'Selam Bekele',
        'salary_cents' => 9_900_000,
        'position_id' => Position::factory()->create(['tenant_id' => $employee->tenant_id, 'title' => 'Chief Executive'])->id,
        'department_id' => Department::factory()->create(['tenant_id' => $employee->tenant_id, 'name' => 'Board'])->id,
    ]);
}

it('prints an issued letter as it was issued, after a promotion, a raise and a rename', function () {
    [, $employee, , $hr] = letterSetup();
    $letters = app(EmploymentLetters::class);
    $letter = $letters->issue($letters->request($employee, ['purpose' => 'Bank', 'include_salary' => true]), $hr);
    $before = $letters->data($letter->fresh());

    changeSelam($employee);
    $after = $letters->data(EmploymentLetterRequest::query()->findOrFail($letter->id));

    expect($after['name'])->toBe('Selam Tesfaye')
        ->and($after['position'])->toBe('Software Engineer')
        ->and($after['department'])->toBe('Engineering')
        ->and($after['salary'])->toBe('45,000.00')
        ->and($after['reference'])->toBe($letter->reference)
        ->and($after['version'])->toBe(1)
        ->and($after)->toEqual($before);
    expect(strlen($letters->pdf($letter->fresh())))->toBeGreaterThan(1000);
});

it('keeps the snapshot encrypted at rest', function () {
    [, $employee, , $hr] = letterSetup();
    $letters = app(EmploymentLetters::class);
    $letter = $letters->issue($letters->request($employee, ['purpose' => 'Bank', 'include_salary' => true]), $hr);

    $raw = (string) DB::table('employment_letter_requests')->where('id', $letter->id)->value('snapshot');

    expect($raw)->not->toBe('')
        ->and($raw)->not->toContain('45,000')
        ->and($raw)->not->toContain('Selam')
        ->and($letter->fresh()->snapshot_at)->not->toBeNull();
});

it('freezes a letter issued before snapshots existed at its first download', function () {
    [, $employee, , $hr] = letterSetup();
    $letters = app(EmploymentLetters::class);
    $letter = $letters->issue($letters->request($employee, ['purpose' => 'Bank', 'include_salary' => true]), $hr);
    DB::table('employment_letter_requests')->where('id', $letter->id)->update(['snapshot' => null, 'snapshot_at' => null]);

    $letters->pdf(EmploymentLetterRequest::query()->findOrFail($letter->id));

    $frozen = EmploymentLetterRequest::query()->findOrFail($letter->id);
    expect($frozen->snapshot)->toBeArray()->and($frozen->snapshot['name'])->toBe('Selam Tesfaye')
        ->and($frozen->snapshot_at)->not->toBeNull();
    $audit = AuditLog::where('action', 'letter.frozen')->first();
    expect($audit)->not->toBeNull()->and($audit->payload['late'] ?? null)->toBeTrue();

    changeSelam($employee);
    expect($letters->data(EmploymentLetterRequest::query()->findOrFail($letter->id))['name'])->toBe('Selam Tesfaye');
    expect(AuditLog::where('action', 'letter.frozen')->count())->toBe(1);
});

it('has no snapshot for a pending or a rejected letter, and does not download it', function () {
    Notification::fake();
    [$tenant, $employee, $me, $hr] = letterSetup();
    $letters = app(EmploymentLetters::class);
    $pending = $letters->request($employee, ['purpose' => 'A']);
    $rejected = $letters->reject($letters->request($employee, ['purpose' => 'B']), $hr, 'No');

    test()->actingAs($me);
    foreach ([$pending, $rejected] as $letter) {
        expect($letter->fresh()->snapshot)->toBeNull();
        test()->get(letterUrl($tenant, "profile/letters/{$letter->public_id}/pdf"))->assertNotFound();
    }
});

it('never puts the snapshot in a response', function () {
    Notification::fake();
    [$tenant, $employee, $me, $hr] = letterSetup();
    $letters = app(EmploymentLetters::class);
    $letter = $letters->issue($letters->request($employee, ['purpose' => 'Bank', 'include_salary' => true]), $hr);

    test()->actingAs($me);
    $mine = test()->getJson(letterUrl($tenant, 'profile/letters'))->assertOk();
    $created = test()->postJson(letterUrl($tenant, 'profile/letters'), ['purpose' => 'X']);
    test()->actingAs($hr);
    $theirs = test()->getJson(letterUrl($tenant, 'employees/letters'))->assertOk();

    expect($mine->getContent())->toContain($letter->public_id);
    foreach ([$mine, $created, $theirs] as $response) {
        expect($response->getContent())->not->toContain('snapshot')->not->toContain('45,000');
    }
});

it('does not hand organisation A\'s letter to organisation B', function () {
    [$tenant, $employee, , $hr] = letterSetup();
    $letters = app(EmploymentLetters::class);
    $letter = $letters->issue($letters->request($employee, ['purpose' => 'Bank']), $hr);
    $other = createTenant(['name' => 'Other Org']);
    $otherEmployee = Employee::factory()->create(['tenant_id' => $other->id]);
    test()->actingAs(createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $otherEmployee->id], $other));

    test()->get(letterUrl($other, "profile/letters/{$letter->public_id}/pdf"))->assertNotFound();
    expect($tenant->id)->not->toBe($other->id);
});
