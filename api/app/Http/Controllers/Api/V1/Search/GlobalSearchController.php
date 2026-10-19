<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Search;

use App\Http\Controllers\Controller;
use App\Http\Requests\Search\GlobalSearchRequest;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;

/**
 * The header's global search: one request, every kind of record the caller
 * may open, a handful of each.
 *
 * Nothing here is a new permission. Each group is included only when the
 * caller already holds the permission its own page checks, and the people in
 * it are narrowed by the same `scopeAccessibleEmployees()` those pages use, so
 * search can never show a row its link would then refuse. A caller who may
 * see none of it gets empty groups, not an error: the palette still searches
 * pages and actions on the client.
 *
 * The tenant scope applies to every model queried here; nothing is bypassed.
 */
class GlobalSearchController extends Controller
{
    private const PER_GROUP = 5;

    public function __invoke(GlobalSearchRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $term = trim((string) $request->validated('q'));

        $people = $this->employees($user, $term);
        $attendancePeople = $this->canSeeAttendance($user) ? $people : new Collection;

        return response()->json([
            'query' => $term,
            'employees' => $people->map(fn (Employee $e) => [
                'id' => $e->public_id,
                'name' => $e->name,
                'employee_code' => $e->employee_code,
                'department' => $e->department?->name,
                'position' => $e->position?->title,
            ])->values(),
            'attendance' => $attendancePeople->map(fn (Employee $e) => [
                'id' => $e->public_id,
                'name' => $e->name,
                'employee_code' => $e->employee_code,
            ])->values(),
            'leave' => $this->leave($user, $term)->map(fn (LeaveRequest $l) => [
                'id' => $l->public_id,
                'employee_name' => $l->employee?->name,
                'leave_type' => $l->leaveType?->name,
                'start_date' => $l->start_date?->format('Y-m-d'),
                'end_date' => $l->end_date?->format('Y-m-d'),
                'status' => $l->status->value,
                'own' => (bool) ($user->employee_id !== null && $l->employee_id === $user->employee_id),
            ])->values(),
            'departments' => $this->departments($user, $term)->map(fn (Department $d) => [
                'id' => $d->public_id,
                'name' => $d->name,
                'code' => $d->code,
            ])->values(),
        ]);
    }

    /** @return Collection<int, Employee> */
    private function employees(User $user, string $term): Collection
    {
        if (! $user->hasPermission('employee.viewAny')) {
            return new Collection;
        }

        $query = Employee::query()->with(['department:id,name', 'position:id,title']);
        $user->scopeAccessibleEmployees($query);

        return $query->search($term)
            ->orderBy('name')
            ->limit(self::PER_GROUP)
            ->get();
    }

    private function canSeeAttendance(User $user): bool
    {
        return $user->hasPermission('attendance.viewAll') || $user->hasPermission('attendance.viewTeam');
    }

    /**
     * Leave the caller can already list: everyone's with `leave.viewAll`, the
     * people they can reach with `leave.viewTeam`, otherwise only their own.
     *
     * @return Collection<int, LeaveRequest>
     */
    private function leave(User $user, string $term): Collection
    {
        $query = LeaveRequest::query()->with(['employee:id,name', 'leaveType:id,name']);

        if ($user->hasPermission('leave.viewAll')) {
            // every request in the tenant
        } elseif ($user->hasPermission('leave.viewTeam')) {
            $query->whereHas('employee', fn (Builder $q) => $user->scopeAccessibleEmployees($q));
        } elseif ($user->employee_id !== null) {
            $query->where('employee_id', $user->employee_id);
        } else {
            return new Collection;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        return $query
            ->where(function (Builder $q) use ($term, $like) {
                $q->whereHas('employee', function (Builder $e) use ($term): void {
                    /** @var Builder<Employee> $e */
                    $e->search($term);
                })
                    ->orWhereHas('leaveType', fn (Builder $t) => $t->where('name', 'like', $like));
            })
            ->orderByDesc('start_date')
            ->limit(self::PER_GROUP)
            ->get();
    }

    /** @return Collection<int, Department> */
    private function departments(User $user, string $term): Collection
    {
        // The Organization page is where these open, and it is gated on
        // `org.create`, not the `org.viewAny` every role holds: a result whose
        // link refuses is worse than no result.
        if (! $user->hasPermission('org.create')) {
            return new Collection;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        return Department::query()
            ->where(fn (Builder $q) => $q->where('name', 'like', $like)->orWhere('code', 'like', $like))
            ->orderBy('name')
            ->limit(self::PER_GROUP)
            ->get(['id', 'public_id', 'name', 'code']);
    }
}
