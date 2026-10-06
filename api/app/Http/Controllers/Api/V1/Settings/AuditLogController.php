<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\AuditSubjects;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class AuditLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('settings.manage');

        $query = AuditLog::query()
            ->orderByDesc('created_at');

        if ($request->has('filter.action')) {
            $query->where('action', 'like', '%'.$request->input('filter.action').'%');
        }

        // A public id, like every other filter: the numeric key it took is
        // what the resource no longer exposes.
        if ($request->has('filter.user_id')) {
            $query->whereIn('user_id', User::query()->where('public_id', $request->input('filter.user_id'))->select('id'));
        }

        if ($request->has('filter.from')) {
            $query->whereDate('created_at', '>=', $request->input('filter.from'));
        }

        if ($request->has('filter.to')) {
            $query->whereDate('created_at', '<=', $request->input('filter.to'));
        }

        $logs = $query->paginate($request->integer('per_page', 50));
        AuditSubjects::attach($logs->getCollection());

        return AuditLogResource::collection($logs);
    }
}
