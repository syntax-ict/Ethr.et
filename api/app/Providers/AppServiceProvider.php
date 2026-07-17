<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\AttendanceRecorded;
use App\Events\DeviceOffline;
use App\Events\PayrollProcessed;
use App\Events\TenantCreated;
use App\Listeners\InvalidateDashboardCache;
use App\Listeners\NotifyDeviceOffline;
use App\Listeners\ProvisionTenant;
use App\Models\Permission;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Dedoc\Scramble\Scramble;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (class_exists(Scramble::class)) {
            Scramble::ignoreDefaultRoutes();
        }
    }

    public function boot(): void
    {
        JsonResource::withoutWrapping();

        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        Event::listen(TenantCreated::class, ProvisionTenant::class);
        Event::listen(DeviceOffline::class, NotifyDeviceOffline::class);
        Event::listen(AttendanceRecorded::class, [InvalidateDashboardCache::class, 'handleAttendance']);
        Event::listen(PayrollProcessed::class, [InvalidateDashboardCache::class, 'handlePayroll']);

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        RateLimiter::for('uploads', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('otp', function (Request $request) {
            return Limit::perMinute(3)->by($request->input('phone', $request->ip()));
        });

        RateLimiter::for('payroll-process', function (Request $request) {
            return Limit::perHour(2)->by('tenant:'.($request->user()?->tenant_id ?: $request->ip()));
        });

        RateLimiter::for('imports', function (Request $request) {
            return Limit::perHour(5)->by('tenant:'.($request->user()?->tenant_id ?: $request->ip()));
        });

        RateLimiter::for('dashboard', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('webhooks-test', function (Request $request) {
            return Limit::perHour(10)->by('tenant:'.($request->user()?->tenant_id ?: $request->ip()));
        });

        Gate::before(function (User $user, string $ability): ?bool {
            if (Permission::isKnownAbility($ability)) {
                return $user->hasPermission($ability);
            }

            return null;
        });
    }
}
