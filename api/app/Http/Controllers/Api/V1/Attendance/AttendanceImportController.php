<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\AttendanceImportCommitRequest;
use App\Http\Requests\Attendance\AttendanceImportPreviewRequest;
use App\Http\Requests\Attendance\ImportAttendanceRequest;
use App\Models\AuditLog;
use App\Services\Attendance\AttendanceImporter;
use App\Services\Import\AttendanceImportParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class AttendanceImportController extends Controller
{
    public function __construct(private readonly AttendanceImporter $importer) {}

    public function template(): JsonResponse
    {
        Gate::authorize('attendance.manage');

        return response()->json([
            'template' => $this->importer->templateCsv(),
            'headers' => ['employee_code', 'date', 'check_in_time', 'check_out_time'],
        ]);
    }

    public function preview(AttendanceImportPreviewRequest $request): JsonResponse
    {
        Gate::authorize('attendance.manage');

        $result = $this->importer->preview($request->file('file'));

        return response()->json($result);
    }

    /**
     * Parse a legacy device export (BioTime, Hikvision, generic CSV).
     * Returns detected format + parsed records for review before committing.
     */
    public function parseLegacy(ImportAttendanceRequest $request): JsonResponse
    {
        Gate::authorize('attendance.manage');

        $content = $request->file('file')->getContent();
        $parser = new AttendanceImportParser;
        $result = $parser->parse($content);

        return response()->json($result);
    }

    public function commit(AttendanceImportCommitRequest $request): JsonResponse
    {
        Gate::authorize('attendance.manage');

        $result = $this->importer->commit(
            $request->validated('import_key'),
            $request->validated('rows')
        );

        AuditLog::record('attendance.import.committed', null, [
            'import_key' => $request->validated('import_key'),
            'created' => $result['created'],
            'skipped' => $result['skipped'],
        ]);

        return response()->json($result, 201);
    }
}
