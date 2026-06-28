<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('settings.manage');

        $query = AuditLog::query()
            ->orderByDesc('created_at');

        if ($request->has('filter.action')) {
            $query->where('action', 'like', '%' . $request->input('filter.action') . '%');
        }

        if ($request->has('filter.user_id')) {
            $query->where('user_id', $request->input('filter.user_id'));
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
