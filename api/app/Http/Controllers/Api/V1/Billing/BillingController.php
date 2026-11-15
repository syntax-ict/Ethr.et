<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\ChangePlanRequest;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\Billing\BillingService;
use App\Services\CurrentTenant;
use App\Support\FrontendUrl;
use App\Support\ReaderDates;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;

class BillingController extends Controller
{
    public function __construct(
        private readonly BillingService $service,
    ) {}

    public function dashboard(Request $request): JsonResponse
    {
        Gate::authorize('billing.manage');

        $tenant = app(CurrentTenant::class)->get();

        return response()->json($this->service->dashboard($tenant));
    }

    public function changePlan(ChangePlanRequest $request): JsonResponse
    {
        Gate::authorize('billing.manage');

        $tenant = app(CurrentTenant::class)->get();
        // is_active, not just existence. A plan the admin has withdrawn from
        // sale is still a row, and without this a stale pricing page — or a
        // replayed request — signs somebody up to a tier that is no longer
        // offered, at a price nobody is maintaining. 404 rather than 422
        // because that is what firstOrFail already returns for an unknown
        // plan_public_id, and one shape for "no such plan on offer" beats two.
        $plan = Plan::where('public_id', $request->input('plan_public_id'))
            ->where('is_active', true)
            ->firstOrFail();

        $result = $this->service->changePlan($tenant, $plan);

        if (isset($result['error'])) {
            return response()->json([
                'type' => 'https://ethr.et/errors/billing',
                'title' => 'Billing Error',
                'status' => 422,
                'detail' => $result['error'],
            ], 422)->header('Content-Type', 'application/problem+json');
        }

        AuditLog::record('billing.plan_changed', $tenant, $result);

        return response()->json($result);
    }

    public function receipt(Request $request, Invoice $invoice): Response
    {
        Gate::authorize('billing.manage');

        $tenant = app(CurrentTenant::class)->get();
        abort_unless($tenant instanceof Tenant, 404);

        // Ethiopic names need the bundled Noto face (see PayslipPdfService);
        // dompdf caches font metrics here and does not create the directory.
        File::ensureDirectoryExists(storage_path('fonts'));

        $pdf = Pdf::loadView('receipts.invoice', [
            'invoice' => $invoice,
            'tenant' => $tenant,
            'address' => FrontendUrl::addressOf($tenant),
            'line_items' => $invoice->line_items ?? [],
            // The receipt is English; its dates are in the reader's calendar.
            'dates' => ReaderDates::for($request->user(), 'en'),
        ]);

        $pdf->setOption('isFontSubsettingEnabled', true);

        $filename = 'receipt-'.$invoice->public_id.'.pdf';

        return $pdf->download($filename);
    }
}
