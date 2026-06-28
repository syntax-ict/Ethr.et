<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\ImportCommitRequest;
use App\Http\Requests\Employee\ImportPreviewRequest;
use App\Models\AuditLog;
use App\Services\Import\EmployeeImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class EmployeeImportController extends Controller
{
    public function __construct(private readonly EmployeeImporter $importer) {}

    public function template(): JsonResponse
    {
        Gate::authorize('employee.create');

        $csv = $this->importer->templateCsv();

        return response()->json([
            'template' => $csv,
            'headers' => ['name', 'email', 'phone', 'employee_code', 'gender', 'hire_date', 'department_code', 'branch_code', 'position_code', 'salary_cents'],
        ]);
    }

    public function preview(ImportPreviewRequest $request): JsonResponse
    {
        Gate::authorize('employee.create');

        $result = $this->importer->preview($request->file('file'));

        return response()->json($result);
    }

    public function commit(ImportCommitRequest $request): JsonResponse
    {
        Gate::authorize('employee.create');

        $result = $this->importer->commit(
            $request->validated('import_key'),
            $request->validated('rows')
        );

        AuditLog::record('employee.import.committed', null, [
            'import_key' => $request->validated('import_key'),
            'created' => $result['created'],
            'skipped' => $result['skipped'],
        ]);

        return response()->json($result, 201);
    }
}
