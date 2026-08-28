<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\AddDisciplinaryNoteRequest;
use App\Http\Requests\Employee\CloseDisciplinaryCaseRequest;
use App\Http\Requests\Employee\FileDisciplinaryAppealRequest;
use App\Http\Requests\Employee\RecordDisciplinaryDecisionRequest;
use App\Http\Requests\Employee\ResolveDisciplinaryAppealRequest;
use App\Http\Requests\Employee\StoreDisciplinaryCaseRequest;
use App\Http\Resources\DisciplinaryCaseResource;
use App\Models\DisciplinaryCase;
use App\Models\Employee;
use App\Services\DisciplinaryCaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class DisciplinaryCaseController extends Controller
{
    public function __construct(private readonly DisciplinaryCaseService $cases) {}

    public function index(Employee $employee): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', DisciplinaryCase::class);

        $cases = $employee->disciplinaryCases()
            ->with(['reportedBy.employee', 'decidedBy.employee', 'appealDecidedBy.employee'])
            ->orderByDesc('incident_date')
            ->orderByDesc('id')
            ->get();

        return DisciplinaryCaseResource::collection($cases);
    }

    public function store(StoreDisciplinaryCaseRequest $request, Employee $employee): JsonResponse
    {
        Gate::authorize('manage', DisciplinaryCase::class);

        $case = $this->cases->open($employee, $request->validated(), $request->user()?->id);

        return (new DisciplinaryCaseResource($case))
            ->response()
            ->setStatusCode(201);
    }

    public function addNote(AddDisciplinaryNoteRequest $request, Employee $employee, DisciplinaryCase $disciplinaryCase): DisciplinaryCaseResource
    {
        Gate::authorize('manage', DisciplinaryCase::class);

        $case = $this->cases->addNote($disciplinaryCase, $request->validated('note'), $request->user());

        return new DisciplinaryCaseResource($case);
    }

    public function decide(RecordDisciplinaryDecisionRequest $request, Employee $employee, DisciplinaryCase $disciplinaryCase): DisciplinaryCaseResource
    {
        Gate::authorize('manage', DisciplinaryCase::class);

        $case = $this->cases->decide($disciplinaryCase, $request->validated(), $request->user()?->id);
        $case->load(['reportedBy.employee', 'decidedBy.employee']);

        return new DisciplinaryCaseResource($case);
    }

    public function appeal(FileDisciplinaryAppealRequest $request, Employee $employee, DisciplinaryCase $disciplinaryCase): DisciplinaryCaseResource
    {
        Gate::authorize('manage', DisciplinaryCase::class);

        $case = $this->cases->fileAppeal($disciplinaryCase, $request->validated('grounds'));

        return new DisciplinaryCaseResource($case);
    }

    public function resolveAppeal(ResolveDisciplinaryAppealRequest $request, Employee $employee, DisciplinaryCase $disciplinaryCase): DisciplinaryCaseResource
    {
        Gate::authorize('manage', DisciplinaryCase::class);

        $case = $this->cases->resolveAppeal($disciplinaryCase, $request->validated(), $request->user()?->id);
        $case->load(['reportedBy.employee', 'decidedBy.employee', 'appealDecidedBy.employee']);

        return new DisciplinaryCaseResource($case);
    }

    public function close(CloseDisciplinaryCaseRequest $request, Employee $employee, DisciplinaryCase $disciplinaryCase): DisciplinaryCaseResource
    {
        Gate::authorize('manage', DisciplinaryCase::class);

        $case = $this->cases->close($disciplinaryCase, $request->validated('notes'));
        $case->load(['reportedBy.employee', 'decidedBy.employee', 'appealDecidedBy.employee']);

        return new DisciplinaryCaseResource($case);
    }
}
