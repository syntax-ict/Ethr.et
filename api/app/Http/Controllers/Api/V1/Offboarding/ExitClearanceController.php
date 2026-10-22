<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Offboarding;

use App\Enums\EmployeeStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Offboarding\ListExitClearancesRequest;
use App\Http\Requests\Offboarding\UpdateExitClearanceItemRequest;
use App\Http\Resources\ExitClearanceItemResource;
use App\Http\Resources\ExitClearanceResource;
use App\Models\Employee;
use App\Models\ExitClearance;
use App\Models\ExitClearanceItem;
use App\Models\User;
use App\Services\Offboarding\ExitClearanceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * Exit clearance (blueprint L10). A resignation, termination or retirement
 * starts one (StartExitClearance); HR can also start one by hand for someone
 * who left before the checklist existed. Nothing here blocks the exit or pay.
 */
class ExitClearanceController extends Controller
{
    public function __construct(private readonly ExitClearanceService $clearances) {}

    /**
     * Open (or finished) clearances the caller has a part in: HR sees the
     * employees it can reach, finance sees all, a supervisor sees their
     * reports.
     */
    public function index(ListExitClearancesRequest $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();
        $hr = $user->hasPermission('employee.transition');
        $finance = $user->hasPermission('payroll.manageLoan');

        abort_unless($hr || $finance || $user->employee_id !== null, 403);

        $query = $this->withCounts(ExitClearance::query())
            ->with('employee')
            ->where('status', $request->input('status', ExitClearance::STATUS_OPEN))
            ->latest('id');

        if (! $finance) {
            $query->whereHas('employee', function (Builder $employees) use ($user, $hr): void {
                if ($hr) {
                    $user->scopeAccessibleEmployees($employees);
                } else {
                    $employees->where('supervisor_id', $user->employee_id);
                }
            });
        }

        return ExitClearanceResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    /**
     * The employee's latest clearance (null when there is none) and what they
     * still owe, which finance settles or notes against the obligations line.
     */
    public function show(Request $request, Employee $employee): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->clearances->canView($user, $employee), 403);

        return response()->json([
            'clearance' => $this->latest($employee),
            'obligations' => $this->clearances->obligations($employee),
        ]);
    }

    /** Start a clearance by hand, for someone who has left; returns the open one if it exists. */
    public function store(Request $request, Employee $employee): JsonResponse
    {
        $this->authorize('transition', $employee);

        if (! in_array($employee->status, EmployeeStatus::leaving(), true)) {
            throw ValidationException::withMessages([
                'employee' => __('exit_clearance.not_leaving'),
            ]);
        }

        $clearance = $this->clearances->start($employee, null, $request->user()?->id);
        $clearance = $this->withCounts(ExitClearance::query())->findOrFail($clearance->id);

        return $this->present($clearance, $employee)->response()->setStatusCode(201);
    }

    public function updateItem(UpdateExitClearanceItemRequest $request, Employee $employee, ExitClearanceItem $exitClearanceItem): ExitClearanceItemResource
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->clearances->canTick($user, $exitClearanceItem, $employee), 403);

        $item = $this->clearances->tick(
            $exitClearanceItem,
            $user,
            $request->boolean('done'),
            $request->validated('note'),
        );

        $item->load(['doneBy', 'clearance.employee']);

        return new ExitClearanceItemResource($item);
    }

    /**
     * @param  Builder<ExitClearance>  $query
     * @return Builder<ExitClearance>
     */
    private function withCounts(Builder $query): Builder
    {
        return $query->withCount([
            'items as item_count',
            'items as done_count' => fn (Builder $items) => $items->whereNotNull('done_at'),
        ]);
    }

    private function latest(Employee $employee): ?ExitClearanceResource
    {
        $clearance = $this->withCounts($employee->exitClearances()->getQuery())->first();

        return $clearance === null ? null : $this->present($clearance, $employee);
    }

    private function present(ExitClearance $clearance, Employee $employee): ExitClearanceResource
    {
        $clearance->setRelation('employee', $employee);
        $clearance->load('items.doneBy');
        $clearance->items->each(fn (ExitClearanceItem $item) => $item->setRelation('clearance', $clearance));

        return new ExitClearanceResource($clearance);
    }
}
