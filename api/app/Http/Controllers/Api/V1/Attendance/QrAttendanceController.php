<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Attendance;

use App\Enums\AttendanceSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\GenerateQrRequest;
use App\Http\Requests\Attendance\QrAttendanceRequest;
use App\Http\Resources\AttendancePunchResource;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\Branch;
use App\Models\Shift;
use App\Services\Attendance\AttendanceEngine;
use App\Services\Attendance\AttendanceInput;
use App\Services\Attendance\QrCodeService;
use App\Services\CurrentTenant;
use App\Support\PunchSignals;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

class QrAttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceEngine $engine,
        private readonly QrCodeService $qrService,
    ) {}

    public function generate(GenerateQrRequest $request): JsonResponse
    {
        Gate::authorize('attendance.manage');

        $tenant = app(CurrentTenant::class)->get();

        if (! $tenant) {
            return response()->json([
                'type' => 'https://ethr.et/errors/tenant-not-found',
                'title' => 'Tenant Required',
                'status' => 400,
                'detail' => 'Tenant context could not be resolved.',
            ], 400)->header('Content-Type', 'application/problem+json');
        }

        $branch = Branch::where('public_id', $request->input('branch_public_id'))->first();

        if (! $branch) {
            return response()->json([
                'type' => 'https://ethr.et/errors/not-found',
                'title' => 'Branch Not Found',
                'status' => 404,
                'detail' => 'The specified branch was not found.',
            ], 404)->header('Content-Type', 'application/problem+json');
        }

        $shift = $request->filled('shift_public_id')
            ? Shift::where('public_id', $request->input('shift_public_id'))->first()
            : null;

        $settings = $tenant->attendanceSetting;
        $expiryMinutes = $request->integer('expiry_minutes', $settings?->qr_expiry_minutes ?? 30);

        $result = $this->qrService->generate($branch, $shift, $expiryMinutes);
        $result['auto_refresh'] = $settings?->qr_auto_refresh ?? true;

        return response()->json($result);
    }

    public function scan(QrAttendanceRequest $request): JsonResponse
    {
        Gate::authorize('attendance.checkIn');

        $user = $request->user();
        $employee = $user->employee;

        if (! $employee) {
            return response()->json([
                'type' => 'https://ethr.et/errors/not-found',
                'title' => 'Not Found',
                'status' => 404,
                'detail' => __('attendance.no_employee_linked'),
            ], 404)->header('Content-Type', 'application/problem+json');
        }

        $tenant = app(CurrentTenant::class)->get();
        $payload = $this->qrService->validate($request->validated('qr_token'), $tenant->id);

        if (! $payload) {
            return response()->json([
                'type' => 'https://ethr.et/errors/invalid-qr',
                'title' => 'Invalid QR Code',
                'status' => 422,
                'detail' => __('attendance.qr_invalid_or_expired'),
            ], 422)->header('Content-Type', 'application/problem+json');
        }

        $refusal = $this->redeem($payload, $employee->id, $tenant->id, (string) $request->validated('type'), $request->validated('idempotency_key'));
        if ($refusal !== null) {
            return response()->json([
                'type' => 'https://ethr.et/errors/invalid-qr',
                'title' => 'Invalid QR Code',
                'status' => 422,
                'detail' => $refusal,
            ], 422)->header('Content-Type', 'application/problem+json');
        }

        try {
            $result = $this->engine->record(new AttendanceInput(
                employeeId: $employee->id,
                tenantId: $employee->tenant_id,
                source: AttendanceSource::QR,
                type: $request->validated('type'),
                idempotencyKey: $request->validated('idempotency_key'),
                // Where the scan happened: a photographed or forwarded code
                // works from anywhere until it expires, so the phone's own
                // location is what shows it was scanned at the branch.
                latitude: $request->validated('latitude'),
                longitude: $request->validated('longitude'),
                ipAddress: $request->ip(),
                metadata: array_filter([
                    'qr_branch_id' => $payload['branch_id'],
                    'qr_shift_id' => $payload['shift_id'] ?? null,
                    'qr_nonce' => $payload['nonce'] ?? null,
                    'accuracy' => $request->validated('accuracy'),
                    ...PunchSignals::from($request->validated(), $request->userAgent()),
                ], fn ($v) => $v !== null),
            ));
        } catch (\RuntimeException $e) {
            return response()->json([
                'type' => 'https://ethr.et/errors/validation',
                'title' => 'Validation Failed',
                'status' => 422,
                'detail' => $e->getMessage(),
            ], 422)->header('Content-Type', 'application/problem+json');
        }

        $result->record->load('employee', 'shift');

        $punch = new AttendancePunchResource($result->record, $result->wasDuplicate);

        if ($result->wasDuplicate || $request->validated('type') === 'check_out') {
            return $punch->response()->setStatusCode(200);
        }

        return $punch->response()->setStatusCode(201);
    }

    /**
     * A QR code is redeemed once per person per punch, and no more than the
     * organisation's `qr_single_use_limit` times in all when it set one. A
     * code photographed at the door and used again from home, or passed round
     * a group chat, is refused; the screen shows a fresh one.
     *
     * A retried request (same idempotency key, already recorded) is not a
     * second use: the engine returns the first punch.
     *
     * @param  array<string, mixed>  $payload
     */
    private function redeem(array $payload, int $employeeId, int $tenantId, string $type, ?string $idempotencyKey): ?string
    {
        $nonce = $payload['nonce'] ?? null;
        if (! is_string($nonce) || $nonce === '') {
            return null;
        }

        if ($idempotencyKey !== null && AttendanceRecord::query()
            ->where(fn ($q) => $q->where('idempotency_key', $idempotencyKey)
                ->orWhere('metadata->checkout_idempotency_key', $idempotencyKey))
            ->exists()) {
            return null;
        }

        $ttl = now()->addDay();
        if (! Cache::add("qr:{$tenantId}:{$nonce}:{$employeeId}:{$type}", true, $ttl)) {
            return __('attendance.qr_already_used');
        }

        $limit = (int) (AttendanceSetting::query()->value('qr_single_use_limit') ?? 0);
        if ($limit > 0) {
            $countKey = "qr:{$tenantId}:{$nonce}:count";
            Cache::add($countKey, 0, $ttl);
            if (Cache::increment($countKey) > $limit) {
                return __('attendance.qr_use_limit_reached');
            }
        }

        return null;
    }
}
