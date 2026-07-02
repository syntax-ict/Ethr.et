<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Admin\PlatformAnalyticsService;
use App\Services\Admin\SystemHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;

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

    public function auditLog(Request $request): JsonResponse
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

        $logs = $query->paginate($request->integer('per_page', 50));

        return response()->json($logs);
    }

    public function failedJobs(Request $request): JsonResponse
    {
        Gate::authorize('admin.manage');

        $jobs = DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->paginate($request->integer('per_page', 25));

        return response()->json($jobs);
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

        \Illuminate\Support\Facades\Artisan::call('queue:retry', ['id' => [$uuid]]);

        return response()->json(['message' => 'Job queued for retry.', 'uuid' => $uuid]);
    }

    public function retryAllFailedJobs(): JsonResponse
    {
        Gate::authorize('admin.manage');

        $count = DB::table('failed_jobs')->count();

        if ($count === 0) {
            return response()->json(['message' => 'No failed jobs to retry.', 'count' => 0]);
        }

        \Illuminate\Support\Facades\Artisan::call('queue:retry', ['id' => ['all']]);

        return response()->json(['message' => "Retrying {$count} failed jobs.", 'count' => $count]);
    }
}
