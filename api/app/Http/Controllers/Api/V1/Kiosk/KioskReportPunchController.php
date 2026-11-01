<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Kiosk;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kiosk\ReportKioskPunchRequest;
use App\Models\AttendanceIntegrityFlag;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\KioskSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * "Not you?" on the kiosk's confirmation screen: the person who just punched
 * sees someone else's name and face, because they mistyped a code or someone
 * typed theirs.
 *
 * The punch is not undone here. Anyone standing at the terminal can press the
 * button, so it only puts the punch in the integrity queue, where HR accepts
 * or rejects it (rejecting voids it). It is accepted only from the kiosk that
 * recorded the punch, and only for WINDOW_SECONDS after it.
 */
class KioskReportPunchController extends Controller
{
    public const WINDOW_SECONDS = 120;

    public function __invoke(ReportKioskPunchRequest $request, string $publicId): JsonResponse
    {
        $token = $request->header('X-Kiosk-Token');
        $session = $token ? KioskSession::resolveActiveByToken((string) $token) : null;

        if ($session === null) {
            return response()->json([
                'type' => 'https://ethr.et/errors/unauthorized',
                'title' => 'Unauthorized',
                'status' => 401,
                'detail' => __('kiosk.invalid_token'),
            ], 401)->header('Content-Type', 'application/problem+json');
        }

        $punch = (string) $request->validated('punch');
        $record = AttendanceRecord::where('public_id', $publicId)->first();
        $at = $record === null ? null : ($punch === 'check_out' ? $record->check_out : $record->check_in);

        $fromThisKiosk = $record !== null
            && (int) ($record->metadata['kiosk_session_id'] ?? 0) === $session->id;

        if (! $fromThisKiosk || $at === null || $at->lt(Carbon::now()->subSeconds(self::WINDOW_SECONDS))) {
            return response()->json([
                'type' => 'https://ethr.et/errors/not-found',
                'title' => 'Not Found',
                'status' => 404,
                'detail' => __('kiosk.report_too_late'),
            ], 404)->header('Content-Type', 'application/problem+json');
        }

        AttendanceIntegrityFlag::query()->firstOrCreate(
            ['attendance_record_id' => $record->id, 'punch' => $punch, 'type' => 'kiosk_wrong_person'],
            [
                'tenant_id' => $record->tenant_id,
                'employee_id' => $record->employee_id,
                'severity' => 'high',
                'details' => ['source' => 'kiosk', 'kiosk_session_id' => $session->id],
                'status' => 'open',
            ],
        );

        AuditLog::record('kiosk.punch_reported', $record, ['kiosk_session_id' => $session->id, 'punch' => $punch]);

        return response()->json(['status' => 'reported'], 202);
    }
}
