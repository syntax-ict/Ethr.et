<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Onboarding;

use App\Http\Controllers\Controller;
use App\Http\Requests\Migration\SaveImportMappingRequest;
use App\Http\Requests\Migration\UpdateImportMappingRequest;
use App\Models\AuditLog;
use App\Models\ImportMapping;
use App\Services\CurrentTenant;
use App\Support\EmployeeImportRow;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The sources an organisation brings lists of people from, and how each
 * names its columns (One employee identity, PR 8).
 *
 * Another HR or payroll system exports a list with its own column names.
 * They are matched to ETHR's once and saved here, so the next export from
 * the same system is read without being asked again, and an id from that
 * system finds the same person every time.
 */
class ImportMappingController extends Controller
{
    public function __construct(private readonly CurrentTenant $currentTenant) {}

    /**
     * The organisation's saved sources, by name.
     *
     * @response array{data: list<array{public_id: string, name: string, columns: list<array{header: string, column: string|null}>, calendar: string|null, updated_at: string|null}>}
     */
    public function index(): JsonResponse
    {
        Gate::authorize('employee.create');

        $sources = ImportMapping::query()->orderBy('name')->limit(ImportMapping::LIMIT)->get();

        return response()->json([
            'data' => $sources->map(fn (ImportMapping $source): array => $this->payload($source))->all(),
        ]);
    }

    /**
     * Save a source and what each of its column names is.
     *
     * `mapping` is a list of `{header, column}`: a column name as the
     * source's file writes it, and the ETHR column it is, or null for one
     * that is left out. One of them has to be the name.
     */
    public function store(SaveImportMappingRequest $request): JsonResponse
    {
        Gate::authorize('employee.create');

        $tenantId = $this->currentTenant->id();
        abort_if($tenantId === null, 422, __('general.not_found', ['resource' => 'Tenant']));

        $name = trim((string) $request->validated('name'));
        $source = ImportMapping::query()->create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'slug' => ImportMapping::slugFor($name),
            'columns' => $this->columns((array) $request->validated('mapping')),
            'calendar' => $request->validated('calendar'),
            'created_by' => $request->user()?->id,
        ]);

        AuditLog::record('import_mapping.saved', $source, ['name' => $source->name, 'columns' => count($source->columns)]);

        return response()->json($this->payload($source), 201);
    }

    /**
     * Rename a source or change what its column names are.
     *
     * Renaming does not change what its ids from the other system are kept
     * against: the people already linked stay linked.
     *
     * @response array{public_id: string, name: string, columns: list<array{header: string, column: string|null}>, calendar: string|null, updated_at: string|null}
     */
    public function update(UpdateImportMappingRequest $request, ImportMapping $importMapping): JsonResponse
    {
        Gate::authorize('employee.create');

        $changes = [];
        if ($request->has('name')) {
            $changes['name'] = trim((string) $request->validated('name'));
        }
        if ($request->has('mapping')) {
            $changes['columns'] = $this->columns((array) $request->validated('mapping'));
        }
        if ($request->has('calendar')) {
            $changes['calendar'] = $request->validated('calendar');
        }

        $importMapping->update($changes);

        AuditLog::record('import_mapping.saved', $importMapping, ['name' => $importMapping->name, 'columns' => count($importMapping->columns)]);

        return response()->json($this->payload($importMapping));
    }

    /**
     * Forget a source's column names.
     *
     * The people it brought stay as they are, and so do their ids from the
     * other system: a source saved again under the same name finds them.
     */
    public function destroy(ImportMapping $importMapping): JsonResponse
    {
        Gate::authorize('employee.create');

        AuditLog::record('import_mapping.deleted', $importMapping, ['name' => $importMapping->name]);
        $importMapping->delete();

        return response()->json(null, 204);
    }

    /**
     * The mapping as it is kept: each column name once, as first written,
     * with the column it is or null.
     *
     * @param  array<int, mixed>  $mapping
     * @return list<array{header: string, column: string|null}>
     */
    private function columns(array $mapping): array
    {
        $map = ImportMapping::mapOf($mapping);
        $columns = [];
        $seen = [];

        foreach ($mapping as $entry) {
            $header = is_array($entry) && is_string($entry['header'] ?? null) ? trim($entry['header']) : '';
            $key = EmployeeImportRow::headerKey($header);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $columns[] = ['header' => $header, 'column' => $map[$key] ?? null];
        }

        return $columns;
    }

    /**
     * @return array{public_id: string, name: string, columns: list<array{header: string, column: string|null}>, calendar: string|null, updated_at: string|null}
     */
    private function payload(ImportMapping $source): array
    {
        return [
            'public_id' => (string) $source->public_id,
            'name' => $source->name,
            'columns' => $source->columns,
            'calendar' => $source->calendar,
            'updated_at' => $source->updated_at?->toIso8601String(),
        ];
    }
}
