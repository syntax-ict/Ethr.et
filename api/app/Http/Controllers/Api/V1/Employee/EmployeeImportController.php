<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\ImportCommitRequest;
use App\Http\Requests\Employee\ImportPreviewRequest;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Services\CurrentTenant;
use App\Services\Import\EmployeeImporter;
use App\Services\PlanLimitService;
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

    public function commit(ImportCommitRequest $request, PlanLimitService $planLimits): JsonResponse
    {
        Gate::authorize('employee.create');

        // Enforced on the whole batch up front, not per row: a partial commit
        // that silently stops mid-import would leave the tenant guessing which
        // rows landed, and the importer's row-skip logic (bad code lookups,
        // duplicates) is unrelated to plan capacity.
        $planLimits->assertCanAdd(
            app(CurrentTenant::class)->get(),
            'employees',
            count($request->validated('rows')),
        );

        $result = $this->importer->commit(
            $request->validated('import_key'),
            $request->validated('rows'),
            (bool) $request->boolean('create_logins'),
        );

        AuditLog::record('employee.import.committed', null, [
            'import_key' => $request->validated('import_key'),
            'created' => $result['created'],
            'skipped' => $result['skipped'],
            'users_created' => $result['users_created'],
        ]);

        return response()->json($result, 201);
    }

    /**
     * Outcome of a previously committed import (PHASE_02 S12).
     *
     * `commit` runs synchronously and returns its result, so this is not a
     * progress bar — there is no partially-running import to poll. What it is
     * for is recovery: a client that lost the commit response (dropped
     * connection, closed tab) can ask what actually landed instead of
     * re-submitting and hoping idempotency saves it.
     *
     * The count is derived from real rows rather than a cached tally, so it
     * cannot drift from what is actually in the database.
     */
    public function status(string $key): JsonResponse
    {
        Gate::authorize('employee.viewAny');

        // Row keys are "{importKey}_{employeeCode}". The separator has to be
        // matched literally: in LIKE, `_` is a single-character wildcard, so a
        // plain `$key.'_%'` would let "batch-1" also match "batch-12_…" and
        // over-report. The key itself is escaped first so a key containing `%`
        // or `_` cannot widen its own match.
        //
        // `!` is the escape character rather than the conventional backslash:
        // MariaDB treats a backslash as an escape *inside* string literals, so
        // ESCAPE '\' is an unterminated literal and the whole statement fails
        // to parse. `!` needs no quoting in either MariaDB or the SQLite used
        // in tests, which keeps this one clause portable across both.
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $key);

        $imported = Employee::whereRaw("import_key LIKE ? ESCAPE '!'", [$escaped.'!_%'])->count();

        return response()->json([
            'import_key' => $key,
            // A committed import is complete by definition; an unknown key has
            // simply never been committed.
            'status' => $imported > 0 ? 'completed' : 'not_found',
            'imported' => $imported,
        ], $imported > 0 ? 200 : 404);
    }
}
