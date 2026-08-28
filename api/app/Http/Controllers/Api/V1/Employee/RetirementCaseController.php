<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\AddRetirementNoteRequest;
use App\Http\Requests\Employee\CancelRetirementCaseRequest;
use App\Http\Requests\Employee\DecideRetirementCaseRequest;
use App\Http\Requests\Employee\FinalizeRetirementCaseRequest;
use App\Http\Requests\Employee\StoreRetirementCaseRequest;
use App\Http\Resources\RetirementCaseResource;
use App\Models\Employee;
use App\Models\RetirementCase;
use App\Services\RetirementCaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class RetirementCaseController extends Controller
{
    public function __construct(private readonly RetirementCaseService $cases) {}

    public function index(Employee $employee): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', RetirementCase::class);

        $cases = $employee->retirementCases()
            ->with(['initiatedBy.employee', 'decidedBy.employee'])
            ->orderByDesc('created_at')
            ->get();

        return RetirementCaseResource::collection($cases);
    }

    public function store(StoreRetirementCaseRequest $request, Employee $employee): JsonResponse
    {
        Gate::authorize('manage', RetirementCase::class);

        $case = $this->cases->initiate($employee, $request->validated(), $request->user()?->id);

        return (new RetirementCaseResource($case))
            ->response()
            ->setStatusCode(201);
    }

    public function addNote(AddRetirementNoteRequest $request, Employee $employee, RetirementCase $retirementCase): RetirementCaseResource
    {
        Gate::authorize('manage', RetirementCase::class);

        $case = $this->cases->addNote($retirementCase, $request->validated('note'), $request->user());

        return new RetirementCaseResource($case);
    }

    public function decide(DecideRetirementCaseRequest $request, Employee $employee, RetirementCase $retirementCase): RetirementCaseResource
    {
        Gate::authorize('manage', RetirementCase::class);

        $case = $this->cases->decide($retirementCase, $request->validated(), $request->user()?->id);
        $case->load(['initiatedBy.employee', 'decidedBy.employee']);

        return new RetirementCaseResource($case);
    }

    public function finalize(FinalizeRetirementCaseRequest $request, Employee $employee, RetirementCase $retirementCase): RetirementCaseResource
    {
        Gate::authorize('manage', RetirementCase::class);

        $case = $this->cases->finalize($retirementCase, $request->validated('effective_date'), $request->user()?->id);
        $case->load(['initiatedBy.employee', 'decidedBy.employee']);

        return new RetirementCaseResource($case);
    }

    public function cancel(CancelRetirementCaseRequest $request, Employee $employee, RetirementCase $retirementCase): RetirementCaseResource
    {
        Gate::authorize('manage', RetirementCase::class);

        $case = $this->cases->cancel($retirementCase, $request->validated('notes'));
        $case->load(['initiatedBy.employee', 'decidedBy.employee']);

        return new RetirementCaseResource($case);
    }
}
