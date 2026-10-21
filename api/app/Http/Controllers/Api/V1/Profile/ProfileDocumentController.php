<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Profile;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeDocumentResource;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Services\FileStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * An employee's own documents: their contract, certificates, ID copy and the
 * rest HR has filed against their record.
 *
 * Until 2026-10-20 the only route to a document was the HR screen, which needs
 * `employee.view`, so an employee could not see or download their own
 * contract (blueprint assessment ESS8). Read-only: filing and removing stay
 * with HR.
 *
 * Every lookup is bound to the caller's own employee id, never route-model
 * binding, so another employee's document cannot be reached here.
 */
class ProfileDocumentController extends Controller
{
    public function __construct(private readonly FileStorageService $fileService) {}

    public function index(Request $request): JsonResponse
    {
        $employee = $this->ownEmployee($request);

        return response()->json([
            'data' => EmployeeDocumentResource::collection(
                $employee->documents()->with('employee:id,public_id,name')->orderByDesc('created_at')->get()
            )->resolve($request),
        ]);
    }

    public function show(Request $request, string $document): JsonResponse
    {
        $employee = $this->ownEmployee($request);

        $model = EmployeeDocument::query()
            ->where('employee_id', $employee->id)
            ->where('public_id', $document)
            ->with('employee:id,public_id,name')
            ->first();
        abort_unless($model instanceof EmployeeDocument, 404);

        AuditLog::record('employee.document.viewed', $employee, [
            'document_id' => $model->public_id,
            'type' => $model->type,
            'self' => true,
        ]);

        return response()->json([
            'download_url' => $this->fileService->temporaryUrl($model->file_path, 15),
            'document' => new EmployeeDocumentResource($model),
        ]);
    }

    private function ownEmployee(Request $request): Employee
    {
        $employee = $request->user()?->employee;
        abort_unless($employee instanceof Employee, 404);

        return $employee;
    }
}
