<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Kiosk;

use App\Enums\Feature;
use App\Http\Controllers\Controller;
use App\Models\KioskSession;
use App\Services\Attendance\AttendancePolicy;
use App\Services\Attendance\QrCodeService;
use App\Services\CurrentTenant;
use App\Services\Features\FeatureGate;
use App\Support\FeatureUnavailable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KioskQrController extends Controller
{
    /**
     * Long enough to walk up and scan, short enough that a photograph of the
     * screen is stale before it reaches another phone.
     */
    public const TTL_SECONDS = 45;

    public function __construct(private readonly QrCodeService $qr) {}

    /**
     * The code this kiosk shows now. Each call issues a new one and the old
     * one simply runs out; the kiosk asks again before it does.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $token = $request->header('X-Kiosk-Token');
        $session = $token ? KioskSession::resolveActiveByToken((string) $token) : null;

        if (! $session) {
            return response()->json([
                'type' => 'https://ethr.et/errors/unauthorized',
                'title' => 'Unauthorized',
                'status' => 401,
                'detail' => __('kiosk.invalid_token'),
            ], 401)->header('Content-Type', 'application/problem+json');
        }

        $tenant = app(CurrentTenant::class)->get();
        $decision = $tenant === null ? null : app(FeatureGate::class)->decide($tenant, Feature::KioskCheckIn);
        if ($decision !== null && ! $decision->on) {
            return FeatureUnavailable::response(Feature::KioskCheckIn, $decision->reason);
        }

        $branch = $session->branch;
        $settings = app(AttendancePolicy::class)->forBranch($session->branch_id);

        if ($branch === null || ! $settings->isMethodEnabled('qr')) {
            return response()->json([
                'type' => 'https://ethr.et/errors/forbidden',
                'title' => 'Method Disabled',
                'status' => 403,
                'detail' => __('attendance.method_disabled'),
            ], 403)->header('Content-Type', 'application/problem+json');
        }

        return response()->json($this->qr->generateForKiosk($session, $branch, self::TTL_SECONDS));
    }
}
