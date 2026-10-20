<?php

declare(strict_types=1);

use App\Enums\ContractStatus;
use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Jobs\SendExpiryRemindersJob;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeDocument;
use App\Notifications\ExpiryReminderNotification;
use App\Services\CurrentTenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;

// Contracts, probation periods and documents about to end reach HR ahead of
// time, on a few fixed days rather than every day. Until 2026-10-20 they
// appeared only on a screen someone had to open (blueprint E6, L4, L5, N3).

afterEach(function () {
    Carbon::setTestNow();
});

function expiryFixture(): array
{
    Carbon::setTestNow('2026-10-20 06:05');
    $tenant = createTenant();
    $hr = createUser(['role' => UserRole::HR_ADMIN], $tenant);
    $lead = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $leadUser = createUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $lead->id], $tenant);
    createUser(['role' => UserRole::EMPLOYEE], $tenant);

    $probationer = Employee::factory()->create([
        'tenant_id' => $tenant->id, 'name' => 'Selam Tesfaye', 'supervisor_id' => $lead->id,
        'status' => EmployeeStatus::PROBATION, 'probation_end_date' => '2026-11-03', // 14 days
    ]);
    $contracted = Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Abebe Kebede']);
    EmployeeContract::factory()->create([
        'tenant_id' => $tenant->id, 'employee_id' => $contracted->id,
        'status' => ContractStatus::ACTIVE, 'end_date' => '2026-11-19', // 30 days
    ]);
    // Ten days out is not a reminder day.
    EmployeeContract::factory()->create([
        'tenant_id' => $tenant->id, 'employee_id' => $contracted->id,
        'status' => ContractStatus::ACTIVE, 'end_date' => '2026-10-30',
    ]);
    EmployeeDocument::create([
        'tenant_id' => $tenant->id, 'employee_id' => $contracted->id, 'type' => 'other',
        'title' => 'Work permit', 'file_path' => 'x.pdf', 'file_size' => 1, 'mime_type' => 'application/pdf',
        'expiry_date' => '2026-10-27', // 7 days
    ]);

    return [$tenant, $hr, $leadUser, $probationer];
}

function runExpiryReminders(int $tenantId): void
{
    (new SendExpiryRemindersJob($tenantId))->handle(app(CurrentTenant::class));
}

it('sends HR one notice with every item due today', function () {
    Notification::fake();
    [$tenant, $hr] = expiryFixture();

    runExpiryReminders($tenant->id);

    Notification::assertSentToTimes($hr, ExpiryReminderNotification::class, 1);
    Notification::assertSentTo($hr, ExpiryReminderNotification::class, function (ExpiryReminderNotification $n) use ($hr) {
        $data = $n->toArray($hr);

        return $data['count'] === 3
            && array_column($data['items'], 'kind') === ['document', 'probation', 'contract']
            && $data['url'] === '/employees';
    });
});

it('tells the supervisor about their report\'s probation, and nothing else', function () {
    Notification::fake();
    [$tenant, , $leadUser] = expiryFixture();

    runExpiryReminders($tenant->id);

    Notification::assertSentTo($leadUser, ExpiryReminderNotification::class, function (ExpiryReminderNotification $n) use ($leadUser) {
        $items = $n->toArray($leadUser)['items'];

        return count($items) === 1 && $items[0]['kind'] === 'probation' && $items[0]['name'] === 'Selam Tesfaye';
    });
});

it('sends nothing on a day with nothing due', function () {
    Notification::fake();
    [$tenant] = expiryFixture();
    Carbon::setTestNow('2026-10-21 06:05');

    runExpiryReminders($tenant->id);

    Notification::assertNothingSent();
});

it('lists each item in the e-mail', function () {
    [$tenant, $hr] = expiryFixture();
    Notification::fake();

    runExpiryReminders($tenant->id);

    Notification::assertSentTo($hr, ExpiryReminderNotification::class, function (ExpiryReminderNotification $n) use ($hr) {
        $text = implode("\n", $n->toMail($hr)->introLines);

        return str_contains($text, 'Contract of Abebe Kebede ends on 2026-11-19.')
            && str_contains($text, 'Probation of Selam Tesfaye ends on 2026-11-03.')
            && str_contains($text, 'Work permit of Abebe Kebede expires on 2026-10-27.');
    });
});
