<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Kiosk;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kiosk\AuthenticateKioskRequest;
use App\Http\Requests\Kiosk\RegisterKioskRequest;
use App\Http\Resources\KioskSessionResource;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\KioskSession;
use App\Services\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;

class KioskSessionController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('attendance.manage');

        $sessions = KioskSession::with('branch')
            ->orderByDesc('created_at')
            ->paginate(25);

        return KioskSessionResource::collection($sessions)->response();
    }

    public function store(RegisterKioskRequest $request): JsonResponse
    {
        Gate::authorize('attendance.manage');

        $tenant = app(CurrentTenant::class)->get();
        $branch = Branch::where('public_id', $request->validated('branch_public_id'))->firstOrFail();

        $session = KioskSession::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'name' => $request->validated('name'),
            'token' => KioskSession::generateToken(),
            'admin_pin' => Hash::make($request->validated('admin_pin')),
            'device_identifier' => $request->validated('device_identifier'),
            'status' => 'active',
            'activated_at' => now(),
        ]);

        $session->load('branch');
        AuditLog::record('kiosk.registered', $session);

        return (new KioskSessionResource($session))
            ->response()
            ->setStatusCode(201);
    }

    public function show(string $publicId): JsonResponse
    {
        Gate::authorize('attendance.manage');

        $session = KioskSession::where('public_id', $publicId)->with('branch')->firstOrFail();

        return response()->json((new KioskSessionResource($session))->resolve());
    }

    public function deactivate(string $publicId): JsonResponse
    {
        Gate::authorize('attendance.manage');

        $session = KioskSession::where('public_id', $publicId)->firstOrFail();
        $session->update([
            'status' => 'inactive',
            'deactivated_at' => now(),
        ]);

        AuditLog::record('kiosk.deactivated', $session);

        return response()->json(['status' => 'deactivated']);
    }

    public function activate(string $publicId): JsonResponse
    {
        Gate::authorize('attendance.manage');

        $session = KioskSession::where('public_id', $publicId)->firstOrFail();
        $session->update([
            'status' => 'active',
            'activated_at' => now(),
            'deactivated_at' => null,
        ]);

        AuditLog::record('kiosk.activated', $session);

        return response()->json(['status' => 'activated']);
    }

    public function regenerateToken(string $publicId): JsonResponse
    {
        Gate::authorize('attendance.manage');

        $session = KioskSession::where('public_id', $publicId)->firstOrFail();
        $session->update(['token' => KioskSession::generateToken()]);

        AuditLog::record('kiosk.token_regenerated', $session);

        return response()->json(['token' => $session->token]);
    }

    public function destroy(string $publicId): JsonResponse
    {
        Gate::authorize('attendance.manage');

        $session = KioskSession::where('public_id', $publicId)->firstOrFail();
        AuditLog::record('kiosk.deleted', $session);
        $session->delete();

        return response()->json(null, 204);
    }

    public function authenticate(AuthenticateKioskRequest $request): JsonResponse
    {
        $session = KioskSession::where('token', $request->input('token'))
            ->where('status', 'active')
            ->with('branch')
            ->first();

        if (! $session) {
            return response()->json([
                'type' => 'https://ethr.et/errors/unauthorized',
                'title' => 'Unauthorized',
                'status' => 401,
                'detail' => __('kiosk.invalid_token'),
            ], 401)->header('Content-Type', 'application/problem+json');
        }

        $session->touchActivity();

        $tenant = $session->tenant;
        $settings = $tenant->attendanceSetting;

        return response()->json([
            'session' => (new KioskSessionResource($session))->resolve(),
            'tenant' => [
                'name' => $tenant->name,
                'subdomain' => $tenant->subdomain,
                'logo_path' => $tenant->logo_path,
            ],
            'settings' => [
                'pin_required' => $settings?->kiosk_pin_required ?? false,
                'auto_reset_seconds' => $settings?->kiosk_auto_reset_seconds ?? 4,
            ],
        ]);
    }
}
