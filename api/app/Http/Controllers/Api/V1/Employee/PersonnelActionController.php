<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\StorePersonnelActionRequest;
use App\Http\Resources\PersonnelActionResource;
use App\Models\Employee;
use App\Models\PersonnelAction;
use App\Services\PersonnelActionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class PersonnelActionController extends Controller
{
    public function __construct(private readonly PersonnelActionService $actions) {}

    /**
     * The employee's employment history — newest first, by effective date.
     */
    public function index(Employee $employee): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', PersonnelAction::class);
        $this->reach($employee);

        $actions = $employee->personnelActions()
            ->with('recordedBy.employee')
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->get();

        return PersonnelActionResource::collection($actions);
    }

    public function store(StorePersonnelActionRequest $request, Employee $employee): JsonResponse
    {
        Gate::authorize('create', PersonnelAction::class);
        $this->reach($employee);

        $action = $this->actions->record(
            $employee,
            $request->validated(),
            $request->user()?->id,
        );

        $action->load('recordedBy.employee');

        return (new PersonnelActionResource($action))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * An employee's history is read and written one employee at a time, so
     * the caller must reach that employee, as on every employee screen. Until
     * 2026-10-20 the class-level permission, which supervisors hold, was the
     * only check, so any employee's history was readable (blueprint D5).
     */
    private function reach(Employee $employee): void
    {
        $user = request()->user();

        abort_unless($user !== null && $user->canAccessEmployee($employee), 404);
    }
}
