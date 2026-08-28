<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Http\Resources\FailedJobResource;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Admin\PlatformAnalyticsService;
use App\Services\Admin\SystemHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class AdminDashboardController extends Controller
{
    public function revenue(PlatformAnalyticsService $service): JsonResponse
    {
        Gate::authorize('admin.manage');

        return response()->json($service->revenue());
    }

    public function health(SystemHealthService $health): JsonResponse
    {
        Gate::authorize('admin.manage');

        return response()->json($health->check());
    }

    public function auditLog(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('admin.manage');

        $query = AuditLog::withoutGlobalScopes()->orderByDesc('created_at');

        if ($request->has('filter.action')) {
            $query->where('action', $request->input('filter.action'));
        }

        if ($request->has('filter.from')) {
            $query->whereDate('created_at', '>=', $request->input('filter.from'));
        }

        if ($request->has('filter.to')) {
            $query->whereDate('created_at', '<=', $request->input('filter.to'));
        }

        return AuditLogResource::collection(
            $query->paginate($request->integer('per_page', 50))
        );
    }

    public function failedJobs(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('admin.manage');

        return FailedJobResource::collection(
            DB::table('failed_jobs')
                ->orderByDesc('failed_at')
                ->paginate($request->integer('per_page', 25))
        );
    }

    public function retryFailedJob(string $uuid): JsonResponse
    {
        Gate::authorize('admin.manage');

        $job = DB::table('failed_jobs')->where('uuid', $uuid)->first();

        if (! $job) {
            return response()->json([
                'type' => 'https://ethr.et/errors/not-found',
                'title' => 'Job Not Found',
                'status' => 404,
                'detail' => 'Failed job not found.',
            ], 404)->header('Content-Type', 'application/problem+json');
        }

        Artisan::call('queue:retry', ['id' => [$uuid]]);

        // Operator actions on the queue were the one part of this console that
        // left no trace — every other admin action records, these did not.
        AuditLog::record('admin.failed_job.retried', null, [
            'uuid' => $uuid,
            'job' => $this->jobDisplayName($job),
        ]);

        return response()->json(['message' => 'Job queued for retry.', 'uuid' => $uuid]);
    }

    public function retryAllFailedJobs(): JsonResponse
    {
        Gate::authorize('admin.manage');

        $count = DB::table('failed_jobs')->count();

        if ($count === 0) {
            return response()->json(['message' => 'No failed jobs to retry.', 'count' => 0]);
        }

        Artisan::call('queue:retry', ['id' => ['all']]);

        AuditLog::record('admin.failed_job.retried_all', null, ['count' => $count]);

        return response()->json(['message' => "Retrying {$count} failed jobs.", 'count' => $count]);
    }

    /**
     * Drop a failed job without re-running it.
     *
     * CLAUDE.md's Queue Failure Recovery table specifies "retry/dismiss" actions
     * on this screen and only retry existed — so a job that can never succeed (a
     * deleted tenant, a payload the current code cannot deserialise) sat in the
     * console's Attention Required banner permanently. An operator either re-ran
     * it pointlessly or learned to ignore the banner, which is the worse outcome.
     *
     * Audited with the job's display name, not just its uuid: the row is gone
     * afterwards, so the audit entry is the only remaining record it existed.
     */
    public function dismissFailedJob(string $uuid): JsonResponse
    {
        Gate::authorize('admin.manage');

        $job = DB::table('failed_jobs')->where('uuid', $uuid)->first();

        if (! $job) {
            return response()->json([
                'type' => 'https://ethr.et/errors/not-found',
                'title' => 'Job Not Found',
                'status' => 404,
                'detail' => 'Failed job not found.',
            ], 404)->header('Content-Type', 'application/problem+json');
        }

        DB::table('failed_jobs')->where('uuid', $uuid)->delete();

        AuditLog::record('admin.failed_job.dismissed', null, [
            'uuid' => $uuid,
            'job' => $this->jobDisplayName($job),
            'queue' => $job->queue ?? null,
        ]);

        return response()->json(null, 204);
    }

    /**
     * Find a user across every tenant, by email or phone fragment.
     *
     * "Which organisation is this person on?" is where most support requests
     * start, and nothing in the console answered it — the only route was a
     * database session. Returns the tenant alongside each match so the operator
     * can go straight to that tenant's record.
     *
     * Scoped tight on purpose: a lookup, not an export. Minimum two characters,
     * capped at 25 rows, identity and account status only — never anything from
     * inside the tenant's own data. The search itself is audited, because
     * cross-tenant lookups are exactly the operator action worth reviewing.
     */
    public function searchUsers(Request $request): JsonResponse
    {
        Gate::authorize('admin.manage');

        $term = trim((string) $request->query('q', ''));

        if (mb_strlen($term) < 2) {
            return response()->json([
                'type' => 'https://ethr.et/errors/validation',
                'title' => 'Search Too Short',
                'status' => 422,
                'detail' => 'Enter at least 2 characters to search.',
            ], 422)->header('Content-Type', 'application/problem+json');
        }

        // LIKE wildcards escaped so a `%` typed into the box cannot turn a
        // bounded lookup into "return every user on the platform".
        $like = '%'.addcslashes($term, '%_\\').'%';

        $users = User::withoutGlobalScopes()
            ->where(fn ($q) => $q->where('email', 'like', $like)->orWhere('phone', 'like', $like))
            ->orderBy('email')
            ->limit(25)
            ->get();

        $tenants = Tenant::withoutGlobalScopes()
            ->whereIn('id', $users->pluck('tenant_id')->filter()->unique()->all())
            ->get()
            ->keyBy('id');

        AuditLog::record('admin.user.searched', null, [
            'term' => $term,
            'results' => $users->count(),
        ]);

        return response()->json([
            'data' => $users->map(function (User $user) use ($tenants) {
                $tenant = $user->tenant_id ? $tenants->get($user->tenant_id) : null;

                return [
                    'public_id' => $user->public_id,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'role' => $user->role->value,
                    'status' => $user->status,
                    'mfa_enabled' => (bool) $user->mfa_enabled,
                    'tenant' => $tenant ? [
                        'public_id' => $tenant->public_id,
                        'name' => $tenant->name,
                        'subdomain' => $tenant->subdomain,
                    ] : null,
                ];
            })->values(),
        ]);
    }

    /** The class name a failed job carries in its serialised payload, when readable. */
    private function jobDisplayName(object $job): ?string
    {
        $payload = json_decode((string) ($job->payload ?? ''), true);

        return is_array($payload) && isset($payload['displayName']) && is_string($payload['displayName'])
            ? $payload['displayName']
            : null;
    }
}
