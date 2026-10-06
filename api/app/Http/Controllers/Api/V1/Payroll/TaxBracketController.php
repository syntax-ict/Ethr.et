<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\ReplaceTaxBracketsRequest;
use App\Http\Resources\TaxBracketResource;
use App\Models\AuditLog;
use App\Models\TaxBracket;
use App\Services\Payroll\TaxCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Tenant income-tax ladder. A tenant that defines no brackets falls back to
 * the platform-wide (tenant_id = null) ladder — see
 * {@see TaxCalculator}.
 *
 * The ladder is replaced as a whole rather than edited bracket by bracket so
 * it can never be left with gaps or overlaps between two requests.
 */
class TaxBracketController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('payroll.viewConfig');

        $brackets = TaxBracket::query()
            ->orderBy('min_amount_cents')
            ->get();

        if ($brackets->isEmpty()) {
            // Surface the inherited platform ladder so the UI shows what is
            // actually being applied, not an empty table.
            $brackets = TaxBracket::query()
                ->withoutGlobalScope('tenant')
                ->whereNull('tenant_id')
                ->orderBy('min_amount_cents')
                ->get();
        }

        // The ladder is a small fixed set, so it is returned whole rather than
        // paginated — still under a `data` key for a consistent collection shape.
        return response()->json(['data' => TaxBracketResource::collection($brackets)]);
    }

    public function replace(ReplaceTaxBracketsRequest $request): JsonResponse
    {
        Gate::authorize('payroll.manageConfig');

        /** @var list<array{min_amount_cents: int, max_amount_cents: int|null, rate: float|int|string, deduction_cents: int}> $input */
        $input = $request->validated('brackets');
        $effectiveFrom = $request->validated('effective_from');

        $previous = TaxBracket::query()->orderBy('min_amount_cents')->get();

        $brackets = DB::transaction(function () use ($input, $effectiveFrom, $previous) {
            $previous->each->delete();

            foreach ($input as $bracket) {
                TaxBracket::create([
                    'min_amount_cents' => $bracket['min_amount_cents'],
                    // 0 is the open-ended sentinel — the column is NOT NULL.
                    'max_amount_cents' => $bracket['max_amount_cents'] ?? 0,
                    'rate' => $bracket['rate'],
                    'deduction_cents' => $bracket['deduction_cents'],
                    'effective_from' => $effectiveFrom,
                    'effective_to' => null,
                ]);
            }

            return TaxBracket::query()->orderBy('min_amount_cents')->get();
        });

        AuditLog::record('tax_brackets.replaced', null, [
            'effective_from' => $effectiveFrom,
            'before' => $previous->map(fn (TaxBracket $b) => [
                'min_amount_cents' => (int) $b->min_amount_cents,
                'max_amount_cents' => (int) $b->max_amount_cents,
                'rate' => (float) $b->rate,
                'deduction_cents' => (int) $b->deduction_cents,
            ])->all(),
            'after' => $brackets->map(fn (TaxBracket $b) => [
                'min_amount_cents' => (int) $b->min_amount_cents,
                'max_amount_cents' => (int) $b->max_amount_cents,
                'rate' => (float) $b->rate,
                'deduction_cents' => (int) $b->deduction_cents,
            ])->all(),
        ]);

        return response()->json(['data' => TaxBracketResource::collection($brackets)]);
    }
}
