<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Enums\OrgScope;
use App\Http\Controllers\Controller;
use App\Services\Dashboard\DepartmentDashboardService;
use App\Services\Dashboard\EmployeeDashboardService;
use App\Services\Dashboard\ManagerDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DashboardController extends Controller
{
    public function employee(Request $request, EmployeeDashboardService $service): JsonResponse
    {
        $user = $request->user();

        return response()->json($service->assemble($user));
    }

    public function manager(Request $request, ManagerDashboardService $service): JsonResponse
    {
        Gate::authorize('leave.viewTeam');

        $user = $request->user();

        return response()->json($service->assemble($user));
    }

    /**
     * The section exists for a reader whose role reaches one department or
     * team. Everyone else has a view already: the organisation one, or the
     * direct-reports one.
     */
    public function department(Request $request, DepartmentDashboardService $service): JsonResponse
    {
        $user = $request->user();

        abort_unless(in_array($user->orgScope(), [OrgScope::DEPARTMENT, OrgScope::TEAM], true), 403);

        return response()->json($service->assemble($user));
    }
}
