<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\SmsSender;
use App\Events\AttendanceRecorded;
use App\Events\DeviceOffline;
use App\Events\DeviceSyncFailed;
use App\Events\PayrollRunFailed;
use App\Events\PayrollProcessed;
use App\Events\TenantCreated;
use App\Listeners\InvalidateDashboardCache;
use App\Listeners\NotifyDeviceOffline;
use App\Listeners\NotifyDeviceSyncFailed;
use App\Listeners\NotifyPayrollRunFailed;
use App\Listeners\NotifyPayrollProcessed;
use App\Listeners\ProvisionTenant;
use App\Models\AttendanceRecord;
use App\Models\DisciplinaryCase;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\PayrollRun;
use App\Models\Permission;
use App\Models\PersonalAccessToken;
use App\Models\PersonnelAction;
use App\Models\ProfileUpdateRequest;
use App\Models\RetirementCase;
use App\Models\ShiftRotation;
use App\Models\User;
use App\Notifications\Channels\SmsChannel;
use App\Policies\AttendanceRecordPolicy;
use App\Policies\DisciplinaryCasePolicy;
use App\Policies\EmployeePolicy;
use App\Policies\LeaveRequestPolicy;
use App\Policies\PayrollRunPolicy;
use App\Policies\PersonnelActionPolicy;
use App\Policies\ProfileUpdateRequestPolicy;
use App\Policies\RetirementCasePolicy;
use App\Policies\ShiftRotationPolicy;
use App\Services\Sms\EthioTelecomSmsSender;
use App\Services\Sms\LogSmsSender;
use App\Services\Sso\SamlProvider;
use App\Services\Sso\SsoProviderInterface;
use App\Support\Scramble\DescribeApiDocument;
use App\Support\Scramble\GroupOperationsByDomain;
use Dedoc\Scramble\Scramble;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use Sentry\Laravel\Integration;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (class_exists(Scramble::class)) {
            // Must stay in register(): Scramble reads this flag in bootingPackage().
            Scramble::ignoreDefaultRoutes();
        }

        $this->app->bind(SsoProviderInterface::class, SamlProvider::class);

        $this->app->singleton(SmsSender::class, fn () => match (config('sms.driver')) {
            'ethiotelecom' => new EthioTelecomSmsSender,
            default => new LogSmsSender,
        });
    }

    public function boot(): void
    {
        if (class_exists(Scramble::class)) {
            // Registered in boot(), not register(), and the ordering is load-bearing.
            // Scramble appends its own defaults in bootingPackage() — including
            // AddDocumentTags, which *overwrites* $document->tags with tags built
            // from #[Group] attributes. Document transformers run in append order, so
            // anything registered during register() is silently undone by it. Booting
            // after the package puts this last, where it wins.
            //
            // Groups 310 operations into the product's own domains instead of 83
            // controller-name tags, fills in the summaries controllers do not supply,
            // and gives the document real tag prose and server URLs.
            Scramble::configure()
                ->withOperationTransformers(GroupOperationsByDomain::class)
                ->withDocumentTransformers(DescribeApiDocument::class);
        }

        // Fail closed on the single most damaging production misconfiguration:
        // shipping with APP_DEBUG=true. That leaks stack traces, env values and
        // query bindings in every error response (RFC-7807 `detail`). The env
        // example defaults to APP_ENV=local/APP_DEBUG=true for developer
        // convenience, so an operator who copies it without flipping the flag
        // would otherwise expose the whole app. Refuse to boot instead.
        if ($this->app->isProduction() && (bool) config('app.debug') === true) {
            throw new \RuntimeException(
                'APP_DEBUG must be false when APP_ENV=production. Refusing to boot with debug output enabled.'
            );
        }

        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        JsonResource::withoutWrapping();

        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // A failed queued job used to leave no trace outside the `failed_jobs`
        // table and the admin dashboard, so nothing announced it. That is how
        // ScanAttendanceAnomaliesJob came to fail on every scheduled run —
        // found only by opening the health endpoint for an unrelated reason.
        //
        // Queue failures are the class of error most likely to go unnoticed:
        // there is no user watching a response, and a retry exhausting itself
        // looks exactly like nothing happening.
        Queue::failing(function (JobFailed $event): void {
            Log::error('Queued job failed', [
                'job' => $event->job->resolveName(),
                'queue' => $event->job->getQueue(),
                'connection' => $event->connectionName,
                'attempts' => $event->job->attempts(),
                'exception' => $event->exception->getMessage(),
            ]);

            // Reported explicitly rather than relying on log-channel forwarding,
            // so the alert carries the stack trace and survives a logging config
            // that does not route errors onward.
            if (app()->bound('sentry')) {
                Integration::captureUnhandledException($event->exception);
            }
        });

        Event::listen(TenantCreated::class, ProvisionTenant::class);
        Event::listen(DeviceOffline::class, NotifyDeviceOffline::class);
        Event::listen(DeviceSyncFailed::class, NotifyDeviceSyncFailed::class);
        Event::listen(PayrollRunFailed::class, NotifyPayrollRunFailed::class);
        Event::listen(AttendanceRecorded::class, [InvalidateDashboardCache::class, 'handleAttendance']);
        Event::listen(PayrollProcessed::class, [InvalidateDashboardCache::class, 'handlePayroll']);
        Event::listen(PayrollProcessed::class, NotifyPayrollProcessed::class);

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // `/health` has to stay unauthenticated — container and uptime probes
        // cannot log in — but each call fans a single HTTP request out into
        // five backend round trips (primary DB, read replica, Redis, queue
        // depth, MinIO). nginx caps /api/v1/* at 60r/m per IP, but that only
        // covers traffic that actually arrives through nginx; anything bound
        // straight to the api container bypasses it. 30/min is well above what
        // any real probe needs.
        RateLimiter::for('health', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });

        // The platform console fans out into cross-tenant queries (every tenant's
        // headcount, the whole audit trail, the failed-job table) and drives queue
        // operations, none of which the per-tenant budgets below describe. Keyed
        // to the operator, not the IP, so one operator cannot exhaust another's
        // budget from a shared office address. Generous enough that no legitimate
        // console session notices it; low enough to bound a scripted sweep of the
        // audit log or the user-search endpoint.
        // The cron routes are guarded by a shared secret, so the limiter is not
        // the access control — it is the brake on guessing one. Keyed to the IP
        // and deliberately tight: a legitimate caller fires at most once a
        // minute per task, so 10 leaves ample headroom for a retry while
        // capping an online search at 14,400 attempts a day against a 32-char
        // minimum token. It counts REJECTED requests too -- which is only true
        // because the route lists `throttle:cron` BEFORE VerifyCronToken; the
        // other order would brake legitimate callers and nobody else.
        RateLimiter::for('cron', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        RateLimiter::for('platform-admin', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
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

        // `sms` is a first-class channel name so notifications can declare it in
        // via() the same way they declare mail; the channel itself decides whether
        // the configured driver can actually deliver.
        Notification::extend('sms', fn ($app) => new SmsChannel($app->make(SmsSender::class)));

        Gate::policy(Employee::class, EmployeePolicy::class);
        Gate::policy(AttendanceRecord::class, AttendanceRecordPolicy::class);
        Gate::policy(PayrollRun::class, PayrollRunPolicy::class);
        Gate::policy(LeaveRequest::class, LeaveRequestPolicy::class);
        Gate::policy(ShiftRotation::class, ShiftRotationPolicy::class);
        Gate::policy(ProfileUpdateRequest::class, ProfileUpdateRequestPolicy::class);
        // These three worked already — Laravel 12 auto-discovers
        // App\Policies\{Model}Policy — so this is consistency, not a fix: the
        // list stays the single readable answer to "what is authorized", rather
        // than one that silently under-reports by three.
        Gate::policy(DisciplinaryCase::class, DisciplinaryCasePolicy::class);
        Gate::policy(PersonnelAction::class, PersonnelActionPolicy::class);
        Gate::policy(RetirementCase::class, RetirementCasePolicy::class);

        Gate::before(function (User $user, string $ability): ?bool {
            if (Permission::isKnownAbility($ability)) {
                return $user->hasPermission($ability);
            }

            return null;
        });

        // Consumed by Scramble's RestrictedDocsAccess middleware (see
        // config/scramble.php). Outside the local environment, GET /api/docs is
        // refused unless this passes — the docs enumerate every operation and its
        // schemas, which is a map of the whole product and not something to serve
        // anonymously. Deliberately not a Permission ability: it is a platform
        // concern, not a tenant-delegatable one, so Gate::before above returns
        // null for it and execution reaches here.
        Gate::define('viewApiDocs', fn (User $user): bool => $user->isSuperAdmin());
    }
}
