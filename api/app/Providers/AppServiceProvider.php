<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\UserRole;
use App\Events\TenantCreated;
use App\Listeners\ProvisionTenant;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        JsonResource::withoutWrapping();

        Event::listen(TenantCreated::class, ProvisionTenant::class);

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        RateLimiter::for('uploads', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });

        Gate::define('org.viewAny', fn (User $user): bool => true);
        Gate::define('org.view', fn (User $user): bool => true);
        Gate::define('org.create', fn (User $user): bool => $user->isAtLeast(UserRole::HR_ADMIN));
        Gate::define('org.update', fn (User $user): bool => $user->isAtLeast(UserRole::HR_ADMIN));
        Gate::define('org.delete', fn (User $user): bool => $user->isAtLeast(UserRole::TENANT_ADMIN));

        Gate::define('attendance.checkIn', fn (User $user): bool => true);
        Gate::define('attendance.viewOwn', fn (User $user): bool => true);
        Gate::define('attendance.viewTeam', fn (User $user): bool => $user->isAtLeast(UserRole::SUPERVISOR));
        Gate::define('attendance.viewAll', fn (User $user): bool => $user->isAtLeast(UserRole::HR_ADMIN));
        Gate::define('attendance.view', fn (User $user): bool => true);
        Gate::define('attendance.manage', fn (User $user): bool => $user->isAtLeast(UserRole::HR_ADMIN));

        Gate::define('shift.viewAny', fn (User $user): bool => true);
        Gate::define('shift.view', fn (User $user): bool => true);
        Gate::define('shift.create', fn (User $user): bool => $user->isAtLeast(UserRole::HR_ADMIN));
        Gate::define('shift.update', fn (User $user): bool => $user->isAtLeast(UserRole::HR_ADMIN));
        Gate::define('shift.delete', fn (User $user): bool => $user->isAtLeast(UserRole::TENANT_ADMIN));

        // Device gates
        Gate::define('device.viewAny', fn (User $user): bool => $user->isAtLeast(UserRole::HR_ADMIN));
        Gate::define('device.view', fn (User $user): bool => $user->isAtLeast(UserRole::HR_ADMIN));
        Gate::define('device.create', fn (User $user): bool => $user->isAtLeast(UserRole::TENANT_ADMIN));
        Gate::define('device.update', fn (User $user): bool => $user->isAtLeast(UserRole::TENANT_ADMIN));
        Gate::define('device.delete', fn (User $user): bool => $user->isAtLeast(UserRole::TENANT_ADMIN));

        // Correction gates
        Gate::define('correction.create', fn (User $user): bool => true);
        Gate::define('correction.viewOwn', fn (User $user): bool => true);
        Gate::define('correction.viewPending', fn (User $user): bool => $user->isAtLeast(UserRole::SUPERVISOR));
        Gate::define('correction.viewAll', fn (User $user): bool => $user->isAtLeast(UserRole::HR_ADMIN));
        Gate::define('correction.approve', fn (User $user): bool => $user->isAtLeast(UserRole::SUPERVISOR));

        // Holiday gates
        Gate::define('holiday.viewAny', fn (User $user): bool => true);
        Gate::define('holiday.view', fn (User $user): bool => true);
        Gate::define('holiday.create', fn (User $user): bool => $user->isAtLeast(UserRole::HR_ADMIN));
        Gate::define('holiday.update', fn (User $user): bool => $user->isAtLeast(UserRole::HR_ADMIN));
        Gate::define('holiday.delete', fn (User $user): bool => $user->isAtLeast(UserRole::TENANT_ADMIN));

        // Payroll gates
        Gate::define('payroll.viewAll', fn (User $user): bool => $user->isAtLeast(UserRole::FINANCE_ADMIN));
        Gate::define('payroll.process', fn (User $user): bool => $user->isAtLeast(UserRole::FINANCE_ADMIN));
        Gate::define('payroll.approve', fn (User $user): bool => $user->isAtLeast(UserRole::TENANT_ADMIN));
        Gate::define('payroll.manageLoan', fn (User $user): bool => $user->isAtLeast(UserRole::FINANCE_ADMIN));
        Gate::define('payroll.viewOwnPayslip', fn (User $user): bool => true);

        // Leave gates
        Gate::define('leave.viewTypes', fn (User $user): bool => true);
        Gate::define('leave.manageTypes', fn (User $user): bool => $user->isAtLeast(UserRole::HR_ADMIN));
        Gate::define('leave.request', fn (User $user): bool => true);
        Gate::define('leave.viewTeam', fn (User $user): bool => $user->isAtLeast(UserRole::SUPERVISOR));
        Gate::define('leave.viewAll', fn (User $user): bool => $user->isAtLeast(UserRole::HR_ADMIN));
        Gate::define('leave.approve', fn (User $user): bool => $user->isAtLeast(UserRole::SUPERVISOR));
        Gate::define('leave.adjustBalance', fn (User $user): bool => $user->isAtLeast(UserRole::HR_ADMIN));

        // Employee gates
        Gate::define('employee.viewAny', fn (User $user): bool => $user->isAtLeast(UserRole::SUPERVISOR));
        Gate::define('employee.view', fn (User $user): bool => $user->isAtLeast(UserRole::SUPERVISOR));
        Gate::define('employee.create', fn (User $user): bool => $user->isAtLeast(UserRole::HR_ADMIN));
        Gate::define('employee.update', fn (User $user): bool => $user->isAtLeast(UserRole::HR_ADMIN));
        Gate::define('employee.delete', fn (User $user): bool => $user->isAtLeast(UserRole::TENANT_ADMIN));
        Gate::define('employee.transition', fn (User $user): bool => $user->isAtLeast(UserRole::HR_ADMIN));
        Gate::define('employee.viewFinancial', fn (User $user): bool => $user->isAtLeast(UserRole::HR_ADMIN));
        Gate::define('employee.updateFinancial', fn (User $user): bool => $user->isAtLeast(UserRole::HR_ADMIN));

        // Announcement gates
        Gate::define('announcement.manage', fn (User $user): bool => $user->isAtLeast(UserRole::HR_ADMIN));

        // Dashboard & analytics gates
        Gate::define('dashboard.executive', fn (User $user): bool => $user->isAtLeast(UserRole::TENANT_ADMIN));

        // Report gates
        Gate::define('report.generate', fn (User $user): bool => $user->isAtLeast(UserRole::HR_ADMIN));

        // API key gates
        Gate::define('apikey.manage', fn (User $user): bool => $user->isAtLeast(UserRole::TENANT_ADMIN));

        // Webhook gates
        Gate::define('webhook.manage', fn (User $user): bool => $user->isAtLeast(UserRole::TENANT_ADMIN));

        // Admin gates
        Gate::define('admin.manage', fn (User $user): bool => $user->role === UserRole::SUPER_ADMIN);

        // Billing gates
        Gate::define('billing.manage', fn (User $user): bool => $user->isAtLeast(UserRole::TENANT_ADMIN));

        // Settings gates
        Gate::define('settings.manage', fn (User $user): bool => $user->isAtLeast(UserRole::TENANT_ADMIN));
    }
}
