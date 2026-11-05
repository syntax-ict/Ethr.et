<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\AuditLogStatsRequest;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\CurrentTenant;
use App\Support\AuditSubjects;
use App\Support\TenantTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
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

    /**
     * How many entries the trail holds per day and per action over a range
     * (the last 30 days by default), for the charts above the list. Days are
     * the organisation's own: the stored UTC instant is shifted by the zone's
     * offset before it is dated, which `(tenant_id, created_at)` can still
     * serve as a range scan. Charts upgrade, 2026-11-04.
     */
    public function stats(AuditLogStatsRequest $request): JsonResponse
    {
        Gate::authorize('settings.manage');

        [$from, $to] = $request->range();
        $zone = TenantTime::zone(app(CurrentTenant::class)->get());
        $offset = (new \DateTimeZone($zone))->getOffset($from);

        $base = AuditLog::query()
            ->where('created_at', '>=', TenantTime::wallClockToUtc($from->toDateString(), '00:00', $zone))
            ->where('created_at', '<', TenantTime::wallClockToUtc($to->copy()->addDay()->toDateString(), '00:00', $zone));

        if ($request->action() !== null) {
            $base->where('action', 'like', '%'.$request->action().'%');
        }

        $day = DB::connection()->getDriverName() === 'sqlite'
            ? sprintf("date(created_at, '%+d seconds')", $offset)
            : sprintf('DATE(created_at + INTERVAL %d SECOND)', $offset);

        $byDay = [];
        foreach ((clone $base)->selectRaw("{$day} as day, count(*) as count")->groupByRaw($day)->orderByRaw($day)->get() as $row) {
            $byDay[] = [
                'date' => (string) $row->getAttribute('day'),
                'count' => (int) $row->getAttribute('count'),
            ];
        }

        $byAction = [];
        foreach ((clone $base)->selectRaw('action, count(*) as count')->groupBy('action')->orderByDesc('count')->orderBy('action')->limit(12)->get() as $row) {
            $byAction[] = [
                'action' => (string) $row->getAttribute('action'),
                'count' => (int) $row->getAttribute('count'),
            ];
        }

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'by_day' => $byDay,
            'by_action' => $byAction,
        ]);
    }
}
