<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Admin\PlatformAnalyticsService;
use App\Services\Admin\SystemHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    public function auditLog(Request $request): JsonResponse
    {
        Gate::authorize('admin.manage');

        $query = AuditLog::query()->orderByDesc('created_at');

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
}
