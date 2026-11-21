<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\PayrollEntry;
use App\Services\Payroll\PayrollEngine;
use Carbon\Carbon;
use Illuminate\Support\Carbon as IlluminateCarbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

afterEach(function () {
    IlluminateCarbon::setTestNow();
});

it('writes nothing, dispatches nothing and moves no balance', function () {
    $this->travelTo(IlluminateCarbon::parse('2026-11-05 09:00:00', 'UTC'));
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 1_000_000, 'hire_date' => '2024-01-01']);
    EmployeeLoan::factory()->create([
        'tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'amount_cents' => 100_000,
        'remaining_cents' => 100_000, 'monthly_deduction_cents' => 50_000, 'start_date' => '2026-01-01',
    ]);
    $engine = app(PayrollEngine::class);
    $run = $engine->process($tenant->id, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'), $user->id)->run;
    $engine->approve($run, $user->id);
    $entry = PayrollEntry::query()->where('payroll_run_id', $run->id)->sole();
    $employee->update(['status' => 'resigned', 'termination_date' => '2026-10-16']);

    $tables = ['payroll_entries', 'payroll_runs', 'audit_log', 'employee_salary_history', 'employee_loans', 'employee_cost_sharing', 'payroll_pay_differences', 'employees', 'webhook_deliveries'];
    $snapshot = fn () => collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count().':'.md5(DB::table($t)->get()->toJson())])->all();

    $events = [];
    Event::listen('*', function (string $name) use (&$events): void {
        // Reading a row fires eloquent.retrieved; anything else is a write or a notification.
        if (! str_starts_with($name, 'Illuminate\Database\Events\\') && ! str_starts_with($name, 'eloquent.retrieved') && ! str_starts_with($name, 'eloquent.booting') && ! str_starts_with($name, 'eloquent.booted')) {
            $events[] = $name;
        }
    });
    Queue::fake();
    Bus::fake();
    $before = $snapshot();

    $result = $engine->counterfactual($entry->fresh());

    expect($snapshot())->toBe($before)
        ->and($result['paid']['gross_cents'])->toBe($entry->gross_cents)
        ->and($result['due']['gross_cents'])->toBeLessThan($entry->gross_cents);
    expect($events)->toBe([]);
    Queue::assertNothingPushed();
    Bus::assertNothingDispatched();
});
