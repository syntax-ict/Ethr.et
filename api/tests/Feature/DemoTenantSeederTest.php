<?php

declare(strict_types=1);

use App\Enums\LeaveStatus;
use App\Models\AttendanceRecord;
use App\Models\Device;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\PayrollRun;
use App\Models\Tenant;
use Database\Seeders\DemoTenantSeeder;

test('demo tenant seeder produces a realistic, sales-demo-ready tenant', function () {
    $this->seed(DemoTenantSeeder::class);

    $tenant = Tenant::where('subdomain', 'demo')->firstOrFail();

    expect(Employee::where('tenant_id', $tenant->id)->count())->toBeGreaterThanOrEqual(150);

    // Grouped in PHP rather than SQL. This was `selectRaw("strftime('%Y-%m',
    // date)")`, which is an SQLite built-in and does not exist in MySQL — so the
    // test failed with "FUNCTION strftime does not exist" the first time the
    // suite was pointed at MariaDB. `date` is cast to a date, so the model gives
    // a Carbon instance on either driver and no raw SQL is needed at all.
    $months = AttendanceRecord::where('tenant_id', $tenant->id)
        ->pluck('date')
        ->map(fn ($date) => $date->format('Y-m'))
        ->unique();
    expect($months->count())->toBeGreaterThanOrEqual(6);

    $payrollRuns = PayrollRun::where('tenant_id', $tenant->id)->orderBy('period_start')->get();
    expect($payrollRuns)->toHaveCount(3);
    expect($payrollRuns->where('status', 'approved'))->toHaveCount(2);
    expect($payrollRuns->where('status', 'completed'))->toHaveCount(1);
    expect($payrollRuns->first()->gross_total_cents)->toBeGreaterThan(0);

    $leaveRequests = LeaveRequest::where('tenant_id', $tenant->id)->get();
    expect($leaveRequests->where('status', LeaveStatus::PENDING))->not->toBeEmpty();
    expect($leaveRequests->where('status', LeaveStatus::APPROVED))->not->toBeEmpty();
    expect($leaveRequests->where('status', LeaveStatus::REJECTED))->not->toBeEmpty();

    $devices = Device::where('tenant_id', $tenant->id)->get();
    expect($devices->where('status', 'online'))->not->toBeEmpty();
    expect($devices->where('status', 'offline'))->not->toBeEmpty();
});

test('demo tenant seeder is idempotent — running it twice does not duplicate bulk data', function () {
    $this->seed(DemoTenantSeeder::class);
    $this->seed(DemoTenantSeeder::class);

    $tenant = Tenant::where('subdomain', 'demo')->firstOrFail();

    expect(Tenant::where('subdomain', 'demo')->count())->toBe(1);
    expect(Employee::where('tenant_id', $tenant->id)->count())->toBeLessThan(300);
});
