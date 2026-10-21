<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Import;

use App\Http\Controllers\Controller;
use App\Http\Requests\Import\CommitBalanceImportRequest;
use App\Http\Requests\Import\PreviewBalanceImportRequest;
use App\Services\Import\BalanceImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Opening leave and loan balances (BalanceImporter). `{kind}` is `leave`
 * (needs leave.adjustBalance) or `loans` (needs payroll.manageLoan).
 */
class BalanceImportController extends Controller
{
    public function __construct(private readonly BalanceImporter $importer) {}

    public function template(string $kind): JsonResponse
    {
        Gate::authorize($this->ability($kind));

        return response()->json([
            'template' => $this->importer->template($this->kind($kind)),
            'headers' => $this->kind($kind) === 'leave' ? BalanceImporter::LEAVE_HEADERS : BalanceImporter::LOAN_HEADERS,
        ]);
    }

    public function preview(PreviewBalanceImportRequest $request, string $kind): JsonResponse
    {
        Gate::authorize($this->ability($kind));

        $content = (string) file_get_contents($request->file('file')->getRealPath());

        return response()->json($this->importer->preview($this->kind($kind), $content));
    }

    public function commit(CommitBalanceImportRequest $request, string $kind): JsonResponse
    {
        Gate::authorize($this->ability($kind));

        // The rows' own columns, not validated(): that keeps only the keys the
        // request has rules for. Every cell is checked by the importer itself.
        $rows = array_values(array_filter((array) $request->input('rows', []), 'is_array'));
        $result = $this->importer->commit($this->kind($kind), $rows);

        return response()->json($result, $result['committed'] ? 200 : 422);
    }

    private function kind(string $kind): string
    {
        return $kind === 'leave' ? 'leave' : 'loans';
    }

    private function ability(string $kind): string
    {
        abort_unless(in_array($kind, ['leave', 'loans'], true), 404);

        return $kind === 'leave' ? 'leave.adjustBalance' : 'payroll.manageLoan';
    }
}
