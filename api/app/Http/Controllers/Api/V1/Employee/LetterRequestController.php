<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\RejectLetterRequest;
use App\Models\Employee;
use App\Models\EmploymentLetterRequest;
use App\Services\Letters\EmploymentLetters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * HR's side of employment letters: the requests for employees the caller
 * reaches, and issuing or declining each. Nobody decides a letter about
 * themselves.
 */
class LetterRequestController extends Controller
{
    public function __construct(private readonly EmploymentLetters $letters) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('employee.update');

        $status = $request->query('status', EmploymentLetterRequest::PENDING);
        $letters = EmploymentLetterRequest::query()
            ->whereHas('employee', fn ($q) => $request->user()->scopeAccessibleEmployees($q))
            ->when(in_array($status, ['pending', 'issued', 'rejected'], true), fn ($q) => $q->where('status', $status))
            ->with('employee:id,public_id,name')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $letters->map(fn (EmploymentLetterRequest $l) => EmploymentLetters::present($l))->values(),
        ]);
    }

    public function issue(Request $request, string $letter): JsonResponse
    {
        Gate::authorize('employee.update');
        $model = $this->decidable($request, $letter);

        return response()->json(EmploymentLetters::present($this->letters->issue($model, $request->user())));
    }

    public function reject(RejectLetterRequest $request, string $letter): JsonResponse
    {
        Gate::authorize('employee.update');
        $model = $this->decidable($request, $letter);

        return response()->json(EmploymentLetters::present(
            $this->letters->reject($model, $request->user(), (string) $request->validated('reason')),
        ));
    }

    private function decidable(Request $request, string $publicId): EmploymentLetterRequest
    {
        $model = EmploymentLetterRequest::query()->where('public_id', $publicId)->with('employee')->first();
        $employee = $model?->employee;
        abort_unless($model instanceof EmploymentLetterRequest && $employee instanceof Employee && $request->user()->canAccessEmployee($employee), 404);
        abort_if((int) $request->user()->employee_id === (int) $model->employee_id, 403, __('letters.self_issue'));
        abort_unless($model->status === EmploymentLetterRequest::PENDING, 422, __('letters.not_pending'));

        return $model;
    }
}
