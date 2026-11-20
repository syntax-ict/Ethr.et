<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\DismissPayDifferenceRequest;
use App\Http\Requests\Payroll\ListPayDifferencesRequest;
use App\Http\Requests\Payroll\SettlePayDifferenceRequest;
use App\Http\Resources\PayrollPayDifferenceResource;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollPayDifference;
use App\Models\PayrollRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * The pay differences a change back-dated into a released month left, for
 * finance to settle or dismiss. Nothing here moves money.
 */
class PayDifferenceController extends Controller
{
    public function index(ListPayDifferencesRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PayrollPayDifference::class);

        $query = PayrollPayDifference::query()
            ->with(['employee', 'payrollRun'])
            ->orderByDesc('id');

        $status = $request->validated('status');
        $status !== null
            ? $query->where('status', $status)
            : $query->whereIn('status', PayrollPayDifference::LIVE);

        if ($request->validated('employee') !== null) {
            $query->whereIn('employee_id', Employee::withTrashed()
                ->where('public_id', $request->validated('employee'))
                ->select('id'));
        }

        if ($request->validated('for_run') !== null) {
            $run = PayrollRun::query()->where('public_id', $request->validated('for_run'))->first();
            $query->whereIn('employee_id', PayrollEntry::query()
                ->where('payroll_run_id', $run->id ?? 0)
                ->select('employee_id'));
        }

        return PayrollPayDifferenceResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function settle(SettlePayDifferenceRequest $request, PayrollPayDifference $payDifference): JsonResponse
    {
        $this->authorize('settle', $payDifference);

        return $this->close($payDifference, PayrollPayDifference::SETTLED, 'payroll.pay_difference_settled', (string) $request->validated('note'), $request->user()->id);
    }

    public function dismiss(DismissPayDifferenceRequest $request, PayrollPayDifference $payDifference): JsonResponse
    {
        $this->authorize('dismiss', $payDifference);

        return $this->close($payDifference, PayrollPayDifference::DISMISSED, 'payroll.pay_difference_dismissed', (string) $request->validated('note'), $request->user()->id);
    }

    private function close(PayrollPayDifference $difference, string $status, string $action, string $note, int $userId): JsonResponse
    {
        $refused = DB::transaction(function () use ($difference, $status, $action, $note, $userId): bool {
            $locked = PayrollPayDifference::query()->whereKey($difference->getKey())->lockForUpdate()->first();

            if ($locked === null || $locked->status !== PayrollPayDifference::OPEN) {
                return true;
            }

            $locked->update(['status' => $status, 'note' => $note, 'settled_by' => $userId, 'settled_at' => now()]);

            AuditLog::record($action, $locked, [
                'kind' => $locked->kind,
                'gross_cents' => $locked->gross_cents,
                'net_cents' => $locked->net_cents,
                'note' => $note,
            ]);

            return false;
        });

        if ($refused) {
            return response()->json([
                'type' => 'https://ethr.et/errors/invalid-state',
                'title' => 'Invalid State',
                'status' => 422,
                'detail' => __('payroll.pay_difference_not_open'),
            ], 422)->header('Content-Type', 'application/problem+json');
        }

        $difference->refresh()->load(['employee', 'payrollRun']);

        return response()->json(new PayrollPayDifferenceResource($difference));
    }
}
