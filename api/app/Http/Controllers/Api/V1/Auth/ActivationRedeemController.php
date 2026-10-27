<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RedeemActivationCodeRequest;
use App\Services\Auth\ActivationCodeService;
use App\Services\CurrentTenant;
use Illuminate\Http\JsonResponse;

class ActivationRedeemController extends Controller
{
    public function __construct(
        private readonly ActivationCodeService $codes,
        private readonly CurrentTenant $tenant,
    ) {}

    /**
     * Redeem an in-person activation code and choose a password.
     *
     * For an employee with no email and no phone. Every failure, whether the
     * code is wrong, spent, expired, withdrawn or never existed, answers the
     * same, so nothing confirms that an employee or a code exists. It does not
     * sign anyone in: the employee then signs in with their employee code and
     * the new password.
     */
    public function __invoke(RedeemActivationCodeRequest $request): JsonResponse
    {
        if (! $this->tenant->resolved()) {
            return $this->invalid();
        }

        $user = $this->codes->redeem(
            (string) $request->input('employee_code'),
            (string) $request->input('code'),
            (string) $request->input('password'),
        );

        if ($user === null) {
            return $this->invalid();
        }

        return response()->json([
            'message' => 'Your account is ready. Sign in with your employee code and your new password.',
        ]);
    }

    private function invalid(): JsonResponse
    {
        return response()->json([
            'type' => 'https://ethr.et/errors/invalid-activation-code',
            'title' => 'Invalid Activation Code',
            'status' => 422,
            'detail' => 'This code is not valid. Ask HR to issue a new one.',
        ], 422);
    }
}
