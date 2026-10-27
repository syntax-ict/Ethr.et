<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Recruitment;

use App\Enums\ApplicationStage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Recruitment\ApproveOfferRequest;
use App\Http\Requests\Recruitment\HireApplicationRequest;
use App\Http\Requests\Recruitment\MoveJobApplicationRequest;
use App\Http\Requests\Recruitment\RecordApplicationEventRequest;
use App\Http\Requests\Recruitment\StoreJobApplicationRequest;
use App\Http\Resources\EmployeeResource;
use App\Http\Resources\JobApplicationEventResource;
use App\Http\Resources\JobApplicationResource;
use App\Models\Employee;
use App\Models\JobApplication;
use App\Models\Vacancy;
use App\Services\RecruitmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Applicants for a vacancy: record them, move them through screening and
 * interviews, make an offer, have someone else approve it, and hire.
 *
 * Every route needs `recruitment.manage`. Approving an offer needs
 * `recruitment.approve` as well, from someone other than whoever made the
 * offer; hiring needs the right to create employees. Applications are found
 * through the tenant scope, so another organisation's id is a 404.
 */
class JobApplicationController extends Controller
{
    private const DETAIL = ['vacancy', 'employee', 'offerMadeBy.employee', 'offerApprovedBy.employee', 'events.actor.employee'];

    public function __construct(private readonly RecruitmentService $recruitment) {}

    public function index(Request $request, string $vacancy): AnonymousResourceCollection
    {
        Gate::authorize('recruitment.manage');
        $model = Vacancy::query()->where('public_id', $vacancy)->firstOrFail();

        $query = JobApplication::query()
            ->where('vacancy_id', $model->id)
            ->with(['employee', 'offerMadeBy.employee', 'offerApprovedBy.employee'])
            ->orderByRaw("CASE stage WHEN 'offer' THEN 1 WHEN 'interview' THEN 2 WHEN 'screening' THEN 3 WHEN 'applied' THEN 4 WHEN 'hired' THEN 5 ELSE 6 END")
            ->orderByDesc('id');

        $stage = $request->query('stage');
        if (is_string($stage) && ApplicationStage::tryFrom($stage) !== null) {
            $query->where('stage', $stage);
        }

        return JobApplicationResource::collection($query->paginate(min($request->integer('per_page', 25), 100)));
    }

    public function store(StoreJobApplicationRequest $request, string $vacancy): JsonResponse
    {
        Gate::authorize('recruitment.manage');
        $model = Vacancy::query()->where('public_id', $vacancy)->firstOrFail();

        $application = $this->recruitment->receive($model, $request->validated(), $request->user());

        return $this->detail($application)->response()->setStatusCode(201);
    }

    public function show(string $application): JsonResponse
    {
        Gate::authorize('recruitment.manage');

        return $this->detail($this->find($application))->response();
    }

    public function move(MoveJobApplicationRequest $request, string $application): JsonResponse
    {
        Gate::authorize('recruitment.manage');
        if ($request->validated('offer_salary_cents') !== null) {
            Gate::authorize('employee.updateFinancial');
        }

        $model = $this->recruitment->move(
            $this->find($application),
            ApplicationStage::from($request->validated('stage')),
            $request->validated(),
            $request->user(),
        );

        return $this->detail($model)->response();
    }

    public function record(RecordApplicationEventRequest $request, string $application): JsonResponse
    {
        Gate::authorize('recruitment.manage');

        $event = $this->recruitment->record($this->find($application), $request->validated(), $request->user());

        return (new JobApplicationEventResource($event->load('actor.employee')))->response()->setStatusCode(201);
    }

    public function approveOffer(ApproveOfferRequest $request, string $application): JsonResponse
    {
        Gate::authorize('recruitment.manage');
        Gate::authorize('recruitment.approve');

        $model = $this->recruitment->approveOffer($this->find($application), $request->user(), $request->validated('note'));

        return $this->detail($model)->response();
    }

    public function hire(HireApplicationRequest $request, string $application): JsonResponse
    {
        Gate::authorize('recruitment.manage');
        $this->authorize('create', Employee::class);

        ['employee' => $employee, 'created' => $created] = $this->recruitment->hire(
            $this->find($application),
            $request->validated(),
            $request->user(),
        );

        $employee->load(['department', 'branch', 'position', 'grade', 'team', 'costCenter', 'supervisor']);
        $response = (new EmployeeResource($employee))->response()->setStatusCode($created ? 201 : 200);
        $payload = $response->getData(true);
        $payload['meta'] = ['was_duplicate' => ! $created];

        return $response->setData($payload);
    }

    public function erase(Request $request, string $application): JsonResponse
    {
        Gate::authorize('recruitment.manage');

        $model = $this->recruitment->erase($this->find($application), $request->user());

        return $this->detail($model)->response();
    }

    private function find(string $publicId): JobApplication
    {
        return JobApplication::query()->where('public_id', $publicId)->firstOrFail();
    }

    private function detail(JobApplication $application): JobApplicationResource
    {
        $application->load(self::DETAIL);

        $resource = new JobApplicationResource($application);
        $resource->possibleDuplicates = $this->recruitment->possibleDuplicates($application);

        return $resource;
    }
}
