<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\IssueActivationCodeRequest;
use App\Models\Employee;
use App\Models\User;
use App\Services\Auth\ActivationCodeRefused;
use App\Services\Auth\ActivationCodeService;
use App\Services\CurrentTenant;
use App\Support\FrontendUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ActivationCodeController extends Controller
{
    public function __construct(
        private readonly ActivationCodeService $codes,
        private readonly CurrentTenant $tenant,
    ) {}

    /**
     * Where this employee's in-person activation stands.
     *
     * Never carries the code: it is shown once, when it is issued.
     */
    public function show(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize('issueActivationCode', $employee);

        $user = $employee->user_id !== null ? User::query()->find($employee->user_id) : null;
        $record = $user !== null ? $this->codes->latestFor($user) : null;

        return $this->noStore(response()->json([
            'login_status' => $user === null ? 'none' : (string) $user->status,
            'state' => $this->codes->stateOf($record),
            'purpose' => $record?->purpose,
            'expires_at' => $record?->expires_at,
            'document_type' => $record?->document_type,
        ]));
    }

    /**
     * Issue a one-time activation code for an employee with no email or phone.
     *
     * HR does this in person, after checking the employee's identity. The code
     * is returned once, as typed characters and as a link for a QR, and cannot
     * be read back; issuing another withdraws it. The employee redeems it on
     * their own device and chooses their own password.
     */
    public function store(IssueActivationCodeRequest $request, Employee $employee): JsonResponse
    {
        Gate::authorize('issueActivationCode', $employee);

        try {
            $issued = $this->codes->issue(
                $employee,
                $request->user(),
                (string) $request->validated('document_type'),
                $request->validated('note'),
            );
        } catch (ActivationCodeRefused $e) {
            return $this->noStore($this->refusal($e->reason));
        }

        $subdomain = (string) $this->tenant->get()?->subdomain;
        $code = $issued['code'];
        $fragment = 'c='.$code.'&e='.rawurlencode((string) $employee->employee_code);

        return $this->noStore(response()->json([
            'code' => ActivationCodeService::format($code),
            'employee_code' => $employee->employee_code,
            'purpose' => $issued['record']->purpose,
            'expires_at' => $issued['record']->expires_at,
            'activation_url' => FrontendUrl::forTenant($subdomain, '/login/activate?tenant='.rawurlencode($subdomain)).'#'.$fragment,
        ], 201));
    }

    /** Withdraw the employee's live activation code. */
    public function destroy(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize('issueActivationCode', $employee);

        $user = $employee->user_id !== null ? User::query()->find($employee->user_id) : null;
        $revoked = $user !== null && $this->codes->revoke($user, $request->user());

        return $this->noStore(response()->json(['revoked' => $revoked]));
    }

    private function refusal(string $reason): JsonResponse
    {
        return response()->json([
            'type' => 'https://ethr.et/errors/activation-code-refused',
            'title' => 'Activation Code Not Issued',
            'status' => 422,
            'detail' => __('auth.activation_refused.'.$reason),
            'reason' => $reason,
        ], 422);
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        return $response->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
    }
}
