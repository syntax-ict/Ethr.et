<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Events\PayrollApproved;
use App\Listeners\NotifyPayslipsReleased;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Approving a payroll run must actually reach the people it pays.
 *
 * Found in the browser on 2026-10-08: four approved runs, 151 payslips logged
 * as "released", and not one payslip notification in the database for the one
 * employee who has a login. `PayslipAvailableNotification::toArray()` reads
 * `$entry->payrollRun`; lazy loading is disabled application-wide; the read
 * threw inside `Notification::send()`, and `SendsNotifications::notify()`
 * turned the throw into a log warning. A `Notification::fake()` never calls
 * `toArray()`, which is why nothing caught it. This test does not fake.
 */
it('writes a payslip notification for every employee with a login when a run is approved', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'acme']);
    app(CurrentTenant::class)->set($tenant);

    // Two entries, not one. Eloquent arms the lazy-loading guard only on
    // models hydrated as part of a result set larger than one, so a run with a
    // single payslip never reproduced what every real run (151 entries) did.
    $run = PayrollRun::factory()->create(['tenant_id' => $tenant->id]);
    $users = collect(range(1, 2))->map(function () use ($tenant, $run): User {
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        PayrollEntry::factory()->create([
            'tenant_id' => $tenant->id,
            'payroll_run_id' => $run->id,
            'employee_id' => $employee->id,
        ]);

        return User::factory()->create([
            'tenant_id' => $tenant->id,
            'employee_id' => $employee->id,
            'role' => UserRole::EMPLOYEE,
        ]);
    });
    $user = $users->first();

    // Exactly the state a queue worker is in: a fresh model, nothing loaded.
    app(CurrentTenant::class)->forget();
    Log::spy();

    (new NotifyPayslipsReleased)->handle(new PayrollApproved(PayrollRun::withoutGlobalScopes()->findOrFail($run->id)));

    $rows = DB::table('notifications')
        ->where('notifiable_id', $user->id)
        ->where('type', 'like', '%PayslipAvailable%')
        ->get();

    expect($rows)->toHaveCount(1)
        ->and(json_decode((string) $rows[0]->data, true)['period'])->toBe($run->period_label);

    Log::shouldNotHaveReceived('warning');
});
