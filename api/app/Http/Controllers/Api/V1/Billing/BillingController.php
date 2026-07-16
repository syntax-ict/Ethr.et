<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\ChangePlanRequest;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Plan;
use App\Services\Billing\BillingService;
use App\Services\CurrentTenant;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class BillingController extends Controller
{
    public function __construct(
        private readonly BillingService $service,
    ) {}

    public function dashboard(Request $request): JsonResponse
    {
        $tenant = app(CurrentTenant::class)->get();

        return response()->json($this->service->dashboard($tenant));
    }

    public function changePlan(ChangePlanRequest $request): JsonResponse
    {
        Gate::authorize('billing.manage');

        $tenant = app(CurrentTenant::class)->get();
        $plan = Plan::where('public_id', $request->input('plan_public_id'))->firstOrFail();

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

    public function markPaid(Request $request, Invoice $invoice): JsonResponse
    {
        Gate::authorize('billing.manage');

        $invoice->update([
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        AuditLog::record('billing.invoice_paid', $invoice);

        return response()->json([
            'public_id' => $invoice->public_id,
            'status' => 'paid',
            'paid_at' => $invoice->paid_at,
        ]);
    }

    public function receipt(Invoice $invoice): Response
    {
        Gate::authorize('billing.manage');

        $tenant = app(CurrentTenant::class)->get();

        $pdf = Pdf::loadView('receipts.invoice', [
            'invoice' => $invoice,
            'tenant' => $tenant,
            'line_items' => $invoice->line_items ?? [],
        ]);

        $filename = 'receipt-'.$invoice->public_id.'.pdf';

        return $pdf->download($filename);
    }
}
