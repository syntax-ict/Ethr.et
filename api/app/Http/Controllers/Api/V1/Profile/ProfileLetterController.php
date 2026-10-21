<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Profile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\StoreLetterRequest;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmploymentLetterRequest;
use App\Services\Letters\EmploymentLetters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * An employee's own employment letters: ask for one, see where each stands,
 * and download an issued one (EmploymentLetters). Every lookup is bound to the
 * caller's own employee id.
 */
class ProfileLetterController extends Controller
{
    public function __construct(private readonly EmploymentLetters $letters) {}

    public function index(Request $request): JsonResponse
    {
        $employee = $this->ownEmployee($request);

        $letters = EmploymentLetterRequest::query()
            ->where('employee_id', $employee->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => $letters->map(fn (EmploymentLetterRequest $l) => EmploymentLetters::present($l))->values(),
        ]);
    }

    public function store(StoreLetterRequest $request): JsonResponse
    {
        $employee = $this->ownEmployee($request);

        /** @var array{purpose: string, addressed_to?: string|null, include_salary?: bool, language?: string} $data */
        $data = $request->validated();
        $letter = $this->letters->request($employee, $data);

        return response()->json(EmploymentLetters::present($letter), 201);
    }

    public function pdf(Request $request, string $letter): Response
    {
        $employee = $this->ownEmployee($request);

        $model = EmploymentLetterRequest::query()
            ->where('employee_id', $employee->id)
            ->where('public_id', $letter)
            ->where('status', EmploymentLetterRequest::ISSUED)
            ->first();
        abort_unless($model instanceof EmploymentLetterRequest, 404);

        AuditLog::record('letter.downloaded', $model, ['reference' => $model->reference]);

        return response($this->letters->pdf($model), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"employment-letter-{$model->reference}.pdf\"",
        ]);
    }

    private function ownEmployee(Request $request): Employee
    {
        $employee = $request->user()?->employee;
        abort_unless($employee instanceof Employee, 404);

        return $employee;
    }
}
