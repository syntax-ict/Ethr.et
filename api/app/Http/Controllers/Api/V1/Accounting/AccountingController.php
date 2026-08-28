<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\UpdateChartOfAccountsRequest;
use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\PayrollRun;
use App\Services\Accounting\AccountingExportService;
use App\Services\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class AccountingController extends Controller
{
    private const DEFAULT_ACCOUNTS = [
        ['key' => 'salary_expense',          'account_code' => '5100', 'account_name' => 'Salary Expense'],
        ['key' => 'pension_expense',          'account_code' => '5200', 'account_name' => 'Pension Expense (Employer)'],
        ['key' => 'tax_payable',             'account_code' => '2100', 'account_name' => 'Income Tax Payable'],
        ['key' => 'pension_payable_employee', 'account_code' => '2200', 'account_name' => 'Pension Payable (Employee)'],
        ['key' => 'pension_payable_employer', 'account_code' => '2201', 'account_name' => 'Pension Payable (Employer)'],
        ['key' => 'net_salary_payable',      'account_code' => '2300', 'account_name' => 'Net Salary Payable'],
    ];

    public function __construct(
        private readonly AccountingExportService $service,
    ) {}

    public function chartOfAccounts(): JsonResponse
    {
        Gate::authorize('payroll.viewAll');

        $tenantId = app(CurrentTenant::class)->get()->id;

        $saved = ChartOfAccount::where('tenant_id', $tenantId)
            ->get()
            ->keyBy('key');

        $accounts = collect(self::DEFAULT_ACCOUNTS)->map(function ($default) use ($saved) {
            $override = $saved->get($default['key']);

            return [
                'key' => $default['key'],
                'account_code' => $override?->account_code ?? $default['account_code'],
                'account_name' => $override?->account_name ?? $default['account_name'],
                'is_custom' => $override !== null,
            ];
        });

        return response()->json(['accounts' => $accounts]);
    }

    public function updateChartOfAccounts(UpdateChartOfAccountsRequest $request): JsonResponse
    {
        Gate::authorize('payroll.viewAll');

        $tenantId = app(CurrentTenant::class)->get()->id;

        foreach ($request->input('accounts') as $item) {
            ChartOfAccount::updateOrCreate(
                ['tenant_id' => $tenantId, 'key' => $item['key']],
                ['account_code' => $item['account_code'], 'account_name' => $item['account_name']],
            );
        }

        AuditLog::record('accounting.chart_of_accounts_updated', app(CurrentTenant::class)->get(), [
            'accounts' => $request->input('accounts'),
        ]);

        return response()->json(['message' => 'Chart of accounts updated.']);
    }

    public function journal(PayrollRun $payrollRun): JsonResponse
    {
        Gate::authorize('payroll.viewAll');

        $mapping = $this->getAccountMapping($payrollRun->tenant_id);
        $journal = $this->service->journalEntries($payrollRun, $mapping);

        return response()->json($journal);
    }

    public function export(PayrollRun $payrollRun): Response
    {
        Gate::authorize('payroll.viewAll');

        $mapping = $this->getAccountMapping($payrollRun->tenant_id);
        $journal = $this->service->journalEntries($payrollRun, $mapping);

        $csv = $this->buildCsv($journal);

        AuditLog::record('accounting.journal_exported', $payrollRun);

        $filename = 'journal-'.str_replace(['/', ' '], '-', $journal['period']).'.csv';

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /** @return array<string, array{code: string, name: string}> */
    private function getAccountMapping(int $tenantId): array
    {
        return ChartOfAccount::where('tenant_id', $tenantId)
            ->get()
            ->keyBy('key')
            ->map(fn ($row) => ['code' => $row->account_code, 'name' => $row->account_name])
            ->toArray();
    }

    private function buildCsv(array $journal): string
    {
        $lines = [];
        $lines[] = implode(',', ['Reference', 'Period', 'Date', 'Account Code', 'Account Name', 'Debit (ETB)', 'Credit (ETB)']);

        foreach ($journal['entries'] as $entry) {
            $lines[] = implode(',', [
                $journal['reference'],
                $journal['period'],
                $journal['date'] ?? '',
                $entry['account_code'],
                '"'.str_replace('"', '""', $entry['account_name']).'"',
                $entry['debit_cents'] > 0 ? number_format($entry['debit_cents'] / 100, 2) : '',
                $entry['credit_cents'] > 0 ? number_format($entry['credit_cents'] / 100, 2) : '',
            ]);
        }

        $lines[] = implode(',', [
            '', 'TOTALS', '',
            '', '',
            number_format($journal['total_debits_cents'] / 100, 2),
            number_format($journal['total_credits_cents'] / 100, 2),
        ]);

        return implode("\n", $lines);
    }
}
