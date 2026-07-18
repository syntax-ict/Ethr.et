<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Http\Resources\FailedJobResource;
use App\Models\AuditLog;
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

        return response()->json(['message' => "Retrying {$count} failed jobs.", 'count' => $count]);
    }
}
