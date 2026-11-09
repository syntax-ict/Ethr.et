<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\ReplaceTaxBracketsRequest;
use App\Http\Resources\TaxBracketResource;
use App\Models\TaxBracket;
use App\Services\Payroll\TaxCalculator;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The income-tax ladder an organisation is taxed on: the platform-wide
 * (tenant_id = null) ladder the law sets, or a ladder the organisation saved
 * before ladders were locked, which is still honoured for its dates — see
 * {@see TaxCalculator}. A new ladder is refused: income tax is the law's.
 */
class TaxBracketController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('payroll.viewConfig');

        // The organisation's latest ladder: replace() closes the one before
        // it rather than deleting it, so earlier periods keep the rates they
        // were taxed at.
        $brackets = TaxBracket::query()
            ->whereNull('effective_to')
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

        // The income tax ladder is the law's exactly: withholding less breaks
        // it and withholding more is not more generous, it is wrong. A ladder
        // an organisation saved before this is still honoured for its dates
        // and flagged on its readiness checklist; no new one is accepted
        // (docs/payroll/STATUTORY-PARAMETERS.md, decision D6).
        abort(422, __('payroll.tax_ladder_locked', [
            'reference' => app(TaxCalculator::class)->basis(null, Carbon::today())['legal_reference'] ?? 'the Income Tax Proclamation',
        ]));
    }
}
