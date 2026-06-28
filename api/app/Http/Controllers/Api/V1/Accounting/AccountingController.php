<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Controller;
use App\Models\PayrollRun;
use App\Services\Accounting\AccountingExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class AccountingController extends Controller
{
    public function __construct(
        private readonly AccountingExportService $service,
    ) {}

    public function journal(PayrollRun $payrollRun): JsonResponse
    {
        Gate::authorize('payroll.viewAll');

        $journal = $this->service->journalEntries($payrollRun);

        return response()->json($journal);
    }

    public function export(PayrollRun $payrollRun): JsonResponse
    {
        Gate::authorize('payroll.viewAll');

        $journal = $this->service->journalEntries($payrollRun);

        return response()->json([
            'format' => 'csv',
            'journal' => $journal,
        ]);
    }
}
