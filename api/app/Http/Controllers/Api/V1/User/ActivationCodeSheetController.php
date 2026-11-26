<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\IssueActivationCodesRequest;
use App\Models\AccountActivationCode;
use App\Models\Employee;
use App\Models\User;
use App\Services\Auth\ActivationCodeService;
use App\Services\CurrentTenant;
use App\Support\FrontendUrl;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Activation codes for many employees at once: a sheet of slips to print,
 * for staff brought in with no email to be invited by (One employee
 * identity, PR 9).
 */
class ActivationCodeSheetController extends Controller
{
    public function __construct(
        private readonly ActivationCodeService $codes,
        private readonly CurrentTenant $tenant,
    ) {}

    /**
     * Issue a one-time activation code for each of up to 200 employees.
     *
     * For people no invitation can reach. Each code is returned once, as
     * typed characters and as a link for a QR, and cannot be read back. HR
     * prints the sheet, checks each person's identity and hands them their
     * slip; the person redeems it on their own device and chooses their own
     * password. Someone who cannot be given a code (they can be invited,
     * they are an administrator, the organisation has no way for them to
     * sign in) is listed under `refused` with why, and the rest are issued.
     * A sheet's codes live 72 hours unless `hours` says otherwise, a week at
     * most.
     */
    public function store(IssueActivationCodesRequest $request): JsonResponse
    {
        Gate::authorize('users.issueActivationCode');

        /** @var User $actor */
        $actor = $request->user();
        /** @var list<string> $ids */
        $ids = $request->validated('employee_ids');

        // The tenant scope keeps another organisation's employees out. One of
        // them, and one outside this person's reach, is answered exactly as an
        // id that matches nobody.
        $employees = Employee::query()->whereIn('public_id', $ids)->get();
        $known = $employees->count() === count($ids)
            && $employees->every(fn (Employee $employee): bool => $actor->canAccessEmployee($employee));
        if (! $known) {
            throw ValidationException::withMessages([
                'employee_ids' => __('validation.exists', ['attribute' => 'employee ids']),
            ]);
        }

        $ordered = $employees->keyBy('public_id');
        $hours = $request->validated('hours');

        $sheet = $this->codes->issueMany(
            array_values(array_filter(array_map(fn (string $id) => $ordered->get($id), $ids))),
            $actor,
            is_numeric($hours) ? (int) $hours : null,
        );

        return response()->json($this->payload($sheet), 201)
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache');
    }

    /**
     * @param  array{batch: string|null, expires_at: Carbon, issued: list<array{employee: Employee, code: string, record: AccountActivationCode}>, refused: list<array{employee: Employee, reason: string}>}  $sheet
     * @return array{batch: string|null, expires_at: string, issued: int, codes: list<array{employee_public_id: string, name: string, employee_code: string|null, code: string, activation_url: string}>, refused: list<array{employee_public_id: string, name: string, reason: string, detail: string}>}
     */
    private function payload(array $sheet): array
    {
        $subdomain = (string) $this->tenant->get()?->subdomain;
        $page = FrontendUrl::forTenant($subdomain, '/login/activate?tenant='.rawurlencode($subdomain));

        /** @var list<array{employee_public_id: string, name: string, employee_code: string|null, code: string, activation_url: string}> $codes */
        $codes = [];
        foreach ($sheet['issued'] as $issued) {
            $employee = $issued['employee'];
            $codes[] = [
                'employee_public_id' => (string) $employee->public_id,
                'name' => (string) $employee->name,
                'employee_code' => $employee->employee_code,
                'code' => ActivationCodeService::format($issued['code']),
                'activation_url' => $page.'#c='.$issued['code'].'&e='.rawurlencode((string) $employee->employee_code),
            ];
        }

        /** @var list<array{employee_public_id: string, name: string, reason: string, detail: string}> $refused */
        $refused = [];
        foreach ($sheet['refused'] as $entry) {
            $refused[] = [
                'employee_public_id' => (string) $entry['employee']->public_id,
                'name' => (string) $entry['employee']->name,
                'reason' => $entry['reason'],
                'detail' => (string) __('auth.activation_refused.'.$entry['reason']),
            ];
        }

        return [
            'batch' => $sheet['batch'],
            'expires_at' => $sheet['expires_at']->toIso8601String(),
            'issued' => count($codes),
            'codes' => $codes,
            'refused' => $refused,
        ];
    }
}
