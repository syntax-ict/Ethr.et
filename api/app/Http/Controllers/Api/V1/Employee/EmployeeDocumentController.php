<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\StoreDocumentRequest;
use App\Http\Resources\EmployeeDocumentResource;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Services\FileStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class EmployeeDocumentController extends Controller
{
    public function __construct(private readonly FileStorageService $fileService) {}

    public function index(Request $request, Employee $employee): AnonymousResourceCollection
    {
        Gate::authorize('employee.view');

        $query = $employee->documents();

        if ($request->has('filter.type')) {
            $query->where('type', $request->input('filter.type'));
        }

        return EmployeeDocumentResource::collection(
            $query->orderByDesc('created_at')->get()
        );
    }

    /**
     * Documents expiring within a window (PHASE_02 S12: "documents expiring
     * within 30 days (for notification/dashboard)").
     *
     * Tenant-wide rather than per-employee: the point is for HR to catch a work
     * permit or contract before it lapses, which means seeing every employee's at
     * once. Already-expired documents are included — a permit that lapsed last
     * week is more urgent than one lapsing next week, not less.
     */
    public function expiring(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('employee.viewAny');

        $days = min(max((int) $request->query('days', 30), 1), 365);

        $documents = EmployeeDocument::query()
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', now()->addDays($days))
            ->with('employee:id,public_id,name')
            ->orderBy('expiry_date')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return EmployeeDocumentResource::collection($documents);
    }

    public function store(StoreDocumentRequest $request, Employee $employee): JsonResponse
    {
        Gate::authorize('employee.update');

        $uploaded = $this->fileService->upload(
            $request->file('file'),
            "employees/{$employee->public_id}/documents"
        );

        $document = $employee->documents()->create([
            'title' => $request->validated('title'),
            'type' => $request->validated('type'),
            'file_path' => $uploaded['path'],
            'file_size' => $uploaded['size'],
            'mime_type' => $uploaded['mime_type'],
            'expiry_date' => $request->validated('expiry_date'),
        ]);

        AuditLog::record('employee.document.uploaded', $employee, [
            'document_id' => $document->public_id,
            'title' => $document->title,
            'type' => $document->type,
        ]);

        return (new EmployeeDocumentResource($document))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Employee $employee, EmployeeDocument $document): JsonResponse
    {
        Gate::authorize('employee.view');

        $url = $this->fileService->temporaryUrl($document->file_path, 15);

        return response()->json([
            'download_url' => $url,
            'document' => new EmployeeDocumentResource($document),
        ]);
    }

    public function destroy(Employee $employee, EmployeeDocument $document): JsonResponse
    {
        Gate::authorize('employee.update');

        // Soft delete only — the underlying file is kept in case the document
        // is later referenced in a legal/payroll dispute. Physical cleanup
        // happens on a retention job once the record is permanently purged.
        $document->delete();

        AuditLog::record('employee.document.deleted', $employee, [
            'document_id' => $document->public_id,
            'title' => $document->title,
        ]);

        return response()->json(null, 204);
    }
}
